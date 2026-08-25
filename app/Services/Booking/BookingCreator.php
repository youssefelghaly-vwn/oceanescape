<?php

namespace App\Services\Booking;

use App\Enums\BookingStatus;
use App\Exceptions\BookingException;
use App\Exceptions\LodgifyWriteFailed;
use App\Models\Booking;
use App\Services\Lodgify\LodgifyBookingWriter;
use App\Services\Lodgify\LodgifyRepository;
use App\Services\Payments\PaymentLinkService;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Takes a validated booking request through to "reservation exists, deposit link on its
 * way".
 *
 * ORDER OF OPERATIONS, AND WHY IT IS THIS ORDER
 *
 *   1. Re-price from Lodgify.       Never trust a total from the client. The browser has
 *                                   been showing a quote for however long the guest sat
 *                                   on the page; rates and availability may have moved.
 *   2. Re-check availability.       Same reason. Cheap, and a booking taken for sold
 *                                   nights is worse than a lost booking.
 *   3. Derive the payment plan.     DepositPolicy, strictly from Lodgify's schedule.
 *                                   Throws rather than guessing.
 *   4. Write our row (transaction). So the guest's details survive a Lodgify failure.
 *   5. Create in Lodgify.           SYNCHRONOUS and outside the transaction. Synchronous
 *                                   because Lodgify's acceptance is the authoritative
 *                                   answer and the guest must be told now if it is no.
 *                                   Outside the transaction because an HTTP call inside
 *                                   one holds a write lock for the length of a network
 *                                   round trip — on SQLite that blocks the whole site.
 *   6. Issue the deposit link.      Queued. No money has moved yet, so nothing here is
 *                                   irreversible.
 *
 * NOTHING IS CHARGED ANYWHERE IN THIS CLASS. That is what makes failing safe easy: if any
 * step throws, we have at worst an orphaned local row and possibly an Open reservation,
 * both of which the expiry sweeper releases. The dangerous path is settlement, which
 * lives in PaymentSettler.
 */
class BookingCreator
{
    public function __construct(
        protected LodgifyRepository $lodgify,
        protected LodgifyBookingWriter $writer,
        protected DepositPolicy $policy,
        protected PaymentLinkService $payments,
        protected BookingAuditor $auditor,
        protected QuoteReader $quotes,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated request data
     *
     * @throws BookingException
     */
    public function create(array $data): Booking
    {
        $cottage = $this->lodgify->cottageBySlug((string) $data['slug']);

        if (! $cottage) {
            throw new BookingException("Unknown cottage: {$data['slug']}");
        }

        /*
         * Refuse a cottage we cannot describe to Lodgify.
         *
         * primaryRoomId() is null when the property payload carried no `rooms[]` — which
         * happens when the detail fetch failed and we fell back to the thin list entry. Such
         * a cottage has no rate calendar and no quote, and a create payload without a
         * room_type_id is not a booking Lodgify can honour. Better to decline here than to
         * take a guest's details for a reservation that cannot be made.
         */
        if ($cottage->primaryRoomId() === null) {
            throw new class("Cottage {$cottage->id} has no room type id; refusing to book.") extends BookingException
            {
                public function guestMessage(): ?string
                {
                    return 'We could not open that cottage for online booking just now. '
                         .'Please call us on '.config('booking.support_phone').' and we will '
                         .'take the booking by phone.';
                }
            };
        }

        $arrival = Carbon::parse($data['arrival'])->startOfDay();
        $departure = Carbon::parse($data['departure'])->startOfDay();

        /*
         * Idempotency for the CREATE itself. A double-clicked confirm button, or a
         * browser retry, must resolve to the same booking rather than two reservations
         * for the same nights. Keyed on the things that make this request that request —
         * deliberately NOT including a timestamp.
         */
        $idempotencyKey = $this->idempotencyKey($cottage->id, $arrival, $departure, (string) $data['guest_email']);

        if ($existing = Booking::query()->where('idempotency_key', $idempotencyKey)->first()) {
            $this->auditor->record('booking.duplicate_suppressed', $existing, context: [
                'idempotency_key' => $idempotencyKey,
            ]);

            return $existing;
        }

        // ---- 1 + 2: re-price and re-check, server side ------------------------
        $quote = $this->quotes->authoritativeQuote(
            $cottage,
            $arrival->toDateString(),
            $departure->toDateString(),
            (int) $data['adults'],
            (int) ($data['children'] ?? 0),
            (int) ($data['pets'] ?? 0),
        );

        $this->assertStillAvailable($cottage, $arrival, $departure);

        // ---- 3: what do we ask for, and when ----------------------------------
        $plan = $this->policy->planFor($quote, $arrival);

        // ---- 4: persist our record --------------------------------------------
        $booking = DB::transaction(function () use ($data, $cottage, $arrival, $departure, $plan, $quote, $idempotencyKey) {
            $booking = new Booking([
                'cottage_id' => $cottage->id,
                'cottage_name' => $cottage->name,
                'room_type_id' => $cottage->primaryRoomId(),
                'arrival' => $arrival,
                'departure' => $departure,
                'nights' => $arrival->diffInDays($departure),
                'adults' => (int) $data['adults'],
                'children' => (int) ($data['children'] ?? 0),
                'infants' => (int) ($data['infants'] ?? 0),
                'pets' => (int) ($data['pets'] ?? 0),
                'guest_name' => $data['guest_name'],
                'guest_email' => strtolower(trim((string) $data['guest_email'])),
                'guest_phone' => $data['guest_phone'] ?? null,
                'guest_country' => isset($data['guest_country']) ? strtoupper((string) $data['guest_country']) : null,
                'guest_notes' => $data['guest_notes'] ?? null,
                'user_id' => $data['user_id'] ?? null,
                'utm_source' => $data['utm_source'] ?? null,
                'utm_medium' => $data['utm_medium'] ?? null,
                'utm_campaign' => $data['utm_campaign'] ?? null,
                'ip_address' => $data['ip_address'] ?? null,
                'user_agent' => $data['user_agent'] ?? null,
                'session_id' => $data['session_id'] ?? null,
            ]);

            // Money and state are forceFilled: they are outside $fillable so that no
            // request-shaped array can reach them.
            $booking->forceFill([
                'status' => BookingStatus::PendingLodgify,
                'currency' => $plan->total->currency,
                'total_cents' => $plan->total->cents,
                'deposit_cents' => $plan->deposit->cents,
                'balance_cents' => $plan->balance->cents,
                'requires_full_payment' => $plan->singlePayment,
                'payment_schedule' => $plan->toArray(),
                'quote_snapshot' => $this->quoteSnapshot($quote),
                'idempotency_key' => $idempotencyKey,
            ])->save();

            return $booking;
        });

        $this->auditor->record('booking.created', $booking, context: [
            'cottage_id' => $cottage->id,
            'stay' => $booking->stay_label,
            'total' => $plan->total->format(),
            'deposit' => $plan->deposit->format(),
            'plan_source' => $plan->source,
        ], toStatus: BookingStatus::PendingLodgify->value, actorType: 'guest');

        // ---- 5: create in Lodgify, synchronously -------------------------------
        try {
            $lodgifyId = $this->writer->createOpenBooking($booking);
        } catch (LodgifyWriteFailed $e) {
            $booking->forceFill([
                'status' => BookingStatus::Failed,
                'lodgify_sync_error' => mb_substr($e->getMessage(), 0, 1000),
                'lodgify_sync_attempts' => $booking->lodgify_sync_attempts + 1,
            ])->save();

            $this->auditor->recordFailure('booking.lodgify_create_failed', $booking, context: [
                'operation' => $e->operation,
                'status' => $e->status,
                'response' => $e->responseExcerpt,
            ]);

            throw $e;
        }

        $booking->forceFill([
            'lodgify_booking_id' => $lodgifyId,
            'lodgify_status' => (string) config('lodgify.write.status_open', 'Open'),
            'lodgify_created_at' => now(),
            'lodgify_sync_error' => null,
        ])->save();

        $booking->transitionTo(BookingStatus::AwaitingDeposit);

        $this->auditor->recordTransition(
            $booking,
            'booking.awaiting_deposit',
            BookingStatus::PendingLodgify->value,
            BookingStatus::AwaitingDeposit->value,
            ['lodgify_booking_id' => $lodgifyId],
        );

        // ---- 6: issue the first payment link ----------------------------------
        $this->payments->issue(
            $booking,
            $plan->firstPaymentType(),
            $plan->firstPaymentAmount(),
        );

        return $booking->refresh();
    }

    /**
     * Refuse the booking if the nights are no longer free.
     *
     * A failure to CHECK is treated differently from a check that says no: if Lodgify is
     * unreachable we let the booking through, because Lodgify validates again when we
     * create the reservation and will reject it there. An availability check that cannot
     * run must not become a blocker — and nothing is charged at this point anyway.
     */
    protected function assertStillAvailable($cottage, Carbon $arrival, Carbon $departure): void
    {
        try {
            $free = $this->lodgify
                ->cottagesFreeFor($arrival->toDateString(), $departure->toDateString())
                ->contains(fn ($c) => $c->id === $cottage->id);
        } catch (\Throwable) {
            return;
        }

        if (! $free) {
            throw new class('Those dates are no longer available.') extends BookingException
            {
                public function guestMessage(): ?string
                {
                    return 'Those dates were taken while you were deciding. '
                         .'Nothing has been charged — here is what is still open.';
                }
            };
        }
    }

    /**
     * Trim the quote to what is worth keeping for a dispute.
     *
     * The full Lodgify payload can be large and contains presentational fields we do not
     * want to imply we relied on. These are the figures we actually priced from.
     *
     * @return array<string, mixed>
     */
    protected function quoteSnapshot(array $quote): array
    {
        return array_filter([
            'source' => $quote['source'] ?? null,
            'currency' => $quote['currency'] ?? null,
            'nights' => $quote['nights'] ?? null,
            'nightly' => $quote['nightly'] ?? null,
            'rental' => $quote['rental'] ?? null,
            'fees' => $quote['fees'] ?? null,
            'taxes' => $quote['taxes'] ?? null,
            'promotions' => $quote['promotions'] ?? null,
            'total' => $quote['total'] ?? null,
            'due_now' => $quote['due_now'] ?? null,
            'schedule' => $quote['schedule'] ?? null,
            'security_deposit' => $quote['security_deposit'] ?? null,
            'cancellation_policy' => $quote['cancellation_policy'] ?? null,
            'quoted_at' => now()->toIso8601String(),
        ], fn ($v) => $v !== null);
    }

    protected function idempotencyKey(int $cottageId, Carbon $arrival, Carbon $departure, string $email): string
    {
        return substr(hash('sha256', implode('|', [
            $cottageId,
            $arrival->toDateString(),
            $departure->toDateString(),
            strtolower(trim($email)),
        ])), 0, 60);
    }
}
