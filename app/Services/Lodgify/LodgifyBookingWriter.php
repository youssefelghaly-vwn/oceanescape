<?php

namespace App\Services\Lodgify;

use App\Exceptions\LodgifyWriteFailed;
use App\Models\Booking;
use App\Models\BookingPayment;
use Illuminate\Support\Facades\Log;

/**
 * The only place in the application that WRITES to Lodgify.
 *
 * Everything else in app/Services/Lodgify is read-only, and deliberately so — see the
 * docblock on ReservationRepository. This class is the exception, and it is kept small
 * and separate for two reasons:
 *
 * 1. WRITES ARE NOT IDEMPOTENT AT THE API. Creating a reservation twice creates two
 *    reservations. Every method here is therefore written to be called from a job that
 *    has already established, under a row lock, that the write has not happened.
 *
 * 2. THE REQUEST CONTRACT IS UNVERIFIED. Field names come from
 *    config('lodgify.write.field_map') so that correcting them against a real account is
 *    a config change, not a refactor. This mirrors the existing `rates_param_style`
 *    escape hatch, which exists because Lodgify's parameter naming differs per endpoint
 *    and had to be discovered empirically.
 *
 * Failures raise LodgifyWriteFailed carrying `moneyAtRisk`, which is what tells the
 * caller whether it may fail the guest's request (nothing charged yet) or must alert a
 * human instead (payment already taken, calendar now wrong).
 */
class LodgifyBookingWriter
{
    public function __construct(protected LodgifyClient $client) {}

    /**
     * Create the reservation as `Open`.
     *
     * Returns the Lodgify reservation id. The caller MUST persist it before doing
     * anything else — an id we obtained but failed to store is a reservation we can
     * never reconcile, and the guest's dates are held by a ghost.
     */
    public function createOpenBooking(Booking $booking): string
    {
        $payload = $this->buildCreatePayload($booking);

        $this->log('lodgify.create.attempt', $booking, ['payload_keys' => array_keys($payload)]);

        try {
            $response = $this->client->createBooking($payload);
        } catch (LodgifyApiException $e) {
            throw new LodgifyWriteFailed(
                "Lodgify refused the reservation for booking {$booking->reference}: HTTP {$e->status}",
                operation: 'createOpenBooking',
                status: $e->status,
                moneyAtRisk: false,          // nothing charged at this point in the flow
                responseExcerpt: mb_substr($e->responseBody, 0, 400),
            );
        }

        $id = $this->extractBookingId($response);

        if ($id === null) {
            /*
             * The dangerous case: Lodgify answered 2xx but we cannot find an id. A
             * reservation may well exist that we cannot reference. Fail loudly with the
             * response recorded so it can be reconciled by hand.
             */
            Log::channel('booking')->critical('Lodgify create returned no usable id', [
                'booking' => $booking->reference,
                'response_keys' => is_array($response) ? array_keys($response) : null,
                'response' => $response,
            ]);

            throw new LodgifyWriteFailed(
                "Lodgify accepted the reservation for {$booking->reference} but returned no id; "
                .'it may exist and require manual reconciliation.',
                operation: 'createOpenBooking',
                moneyAtRisk: false,
                responseExcerpt: json_encode($response),
            );
        }

        $this->log('lodgify.create.ok', $booking, ['lodgify_booking_id' => $id]);

        return (string) $id;
    }

    /**
     * Flip the reservation to Booked. THIS is what blocks the dates in Lodgify.
     *
     * Called only after a payment has settled, so `moneyAtRisk` is always true here: a
     * failure means the guest has paid and the calendar does not reflect it.
     */
    public function markBooked(Booking $booking): void
    {
        if (blank($booking->lodgify_booking_id)) {
            throw new LodgifyWriteFailed(
                "Cannot mark booking {$booking->reference} as booked: no Lodgify id recorded.",
                operation: 'markBooked',
                moneyAtRisk: true,
            );
        }

        $this->log('lodgify.mark_booked.attempt', $booking);

        try {
            $this->client->markBookingBooked($booking->lodgify_booking_id);
        } catch (LodgifyApiException $e) {
            throw new LodgifyWriteFailed(
                "Could not mark Lodgify reservation {$booking->lodgify_booking_id} as booked: HTTP {$e->status}",
                operation: 'markBooked',
                status: $e->status,
                moneyAtRisk: true,
                responseExcerpt: mb_substr($e->responseBody, 0, 400),
            );
        }

        $this->log('lodgify.mark_booked.ok', $booking);
    }

    /**
     * Report a settled payment back onto the reservation.
     *
     * BEST EFFORT BY DESIGN. Returns false — rather than throwing — when no endpoint is
     * configured, because the money is already captured and the reservation is already
     * Booked. Recording it in Lodgify keeps `amount_paid`/`amount_due` accurate for
     * whoever is looking at the dashboard; failing to do so is an admin follow-up, never
     * a guest-facing error.
     */
    public function recordPayment(BookingPayment $payment): bool
    {
        $booking = $payment->booking;

        if (blank($booking->lodgify_booking_id)) {
            return false;
        }

        if (! config('booking.record_payments_in_lodgify', true)) {
            return false;
        }

        if (blank(config('lodgify.write.record_payment_path'))) {
            Log::channel('booking')->info(
                'Skipping Lodgify payment record: no endpoint configured. '
                .'Set LODGIFY_RECORD_PAYMENT_PATH once the real route is confirmed.',
                ['booking' => $booking->reference, 'payment' => $payment->reference]
            );

            return false;
        }

        $result = $this->client->recordBookingPayment($booking->lodgify_booking_id, [
            'amount' => $payment->amount()->toFloat(),
            'currency' => $payment->currency,
            'type' => $payment->type->value,
            'reference' => $payment->reference,
            'paid_at' => $payment->paid_at?->toIso8601String(),
            'gateway' => 'stripe',
        ]);

        $this->log('lodgify.record_payment.ok', $booking, [
            'payment' => $payment->reference,
            'amount' => $payment->amount()->format(),
        ]);

        return $result !== null;
    }

    /**
     * Release a reservation whose deposit was never paid, so the nights go back on sale.
     *
     * Deliberately non-throwing: this runs from a sweeper over many bookings, and one
     * stubborn reservation must not stop the rest being released. The failure is recorded
     * for an admin instead.
     */
    public function release(Booking $booking, string $reason): bool
    {
        if (blank($booking->lodgify_booking_id)) {
            return true;   // nothing was ever created; nothing to release
        }

        try {
            $this->client->deleteBooking($booking->lodgify_booking_id);

            $this->log('lodgify.release.ok', $booking, ['reason' => $reason]);

            return true;
        } catch (\Throwable $e) {
            Log::channel('booking')->error('Could not release unpaid Lodgify reservation', [
                'booking' => $booking->reference,
                'lodgify_booking_id' => $booking->lodgify_booking_id,
                'reason' => $reason,
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    // =====================================================================
    // Payload construction
    // =====================================================================

    /**
     * Build the create-reservation body from the configured field map.
     *
     * @return array<string, mixed>
     */
    protected function buildCreatePayload(Booking $booking): array
    {
        $f = (array) config('lodgify.write.field_map', []);

        $get = fn (string $key, string $default) => (string) ($f[$key] ?? $default);

        $guest = array_filter([
            $get('guest_name', 'name') => $booking->guest_name,
            $get('guest_email', 'email') => $booking->guest_email,
            $get('guest_phone', 'phone') => $booking->guest_phone,
            $get('guest_country', 'country_code') => $booking->guest_country,
        ], fn ($v) => $v !== null && $v !== '');

        $room = array_filter([
            $get('room_type_id', 'room_type_id') => $booking->room_type_id,
            $get('people', 'people') => $booking->adults + $booking->children,
            'guest_breakdown' => [
                'adults' => $booking->adults,
                'children' => $booking->children,
                'infants' => $booking->infants,
                'pets' => $booking->pets,
            ],
        ], fn ($v) => $v !== null && $v !== '');

        return array_filter([
            $get('property_id', 'property_id') => $booking->cottage_id,
            $get('arrival', 'arrival') => $booking->arrival->toDateString(),
            $get('departure', 'departure') => $booking->departure->toDateString(),

            // Created UNCONFIRMED on purpose. It only becomes Booked when money lands.
            $get('status', 'status') => (string) config('lodgify.write.status_open', 'Open'),
            $get('source', 'source') => (string) config('lodgify.write.source', 'Website'),
            $get('currency', 'currency_code') => $booking->currency,

            /*
             * Sent so the Lodgify dashboard shows the same figure the guest saw. Lodgify
             * re-prices from its own rates regardless, which is the correct precedence —
             * this is informational, not authoritative.
             */
            $get('total', 'total_amount') => $booking->total()->toFloat(),

            $get('notes', 'notes') => $this->notesFor($booking),
            $get('guest', 'guest') => $guest,
            $get('rooms', 'rooms') => [$room],
        ], fn ($v) => $v !== null && $v !== '' && $v !== []);
    }

    /**
     * Notes written onto the reservation.
     *
     * Deliberately includes our own reference and the fact that payment is handled on
     * our site: whoever opens this reservation in the Lodgify dashboard needs to know
     * NOT to chase the guest for money Lodgify thinks is outstanding, and needs a handle
     * to find our record.
     */
    protected function notesFor(Booking $booking): string
    {
        $lines = [
            "Booked on oceanescapecottages.ca — ref {$booking->reference}",
            'Payment collected via Stripe on our own site, NOT through Lodgify.',
            'Deposit: '.$booking->depositAmount()->format()
                .' · Balance: '.$booking->balanceAmount()->format()
                .' · Total: '.$booking->total()->format(),
        ];

        if (filled($booking->guest_notes)) {
            $lines[] = 'Guest note: '.$booking->guest_notes;
        }

        return implode("\n", $lines);
    }

    /**
     * Find the reservation id in a create response.
     *
     * Tries several shapes because the response envelope is unverified. Returns null
     * rather than guessing wrong — the caller treats that as a hard failure needing
     * reconciliation, which is the safe reading.
     */
    protected function extractBookingId(mixed $response): int|string|null
    {
        if (is_int($response) || (is_string($response) && $response !== '')) {
            return $response;   // some Lodgify routes return a bare id
        }

        if (! is_array($response)) {
            return null;
        }

        foreach (['id', 'booking_id', 'bookingId', 'Id', 'reservation_id'] as $key) {
            if (filled($response[$key] ?? null)) {
                return $response[$key];
            }
        }

        // One level down, e.g. { "data": { "id": ... } }
        foreach (['data', 'booking', 'reservation', 'result'] as $wrapper) {
            if (is_array($response[$wrapper] ?? null)) {
                foreach (['id', 'booking_id', 'bookingId'] as $key) {
                    if (filled($response[$wrapper][$key] ?? null)) {
                        return $response[$wrapper][$key];
                    }
                }
            }
        }

        return null;
    }

    /** @param array<string, mixed> $context */
    protected function log(string $event, Booking $booking, array $context = []): void
    {
        Log::channel('booking')->info($event, $context + [
            'booking' => $booking->reference,
            'lodgify_booking_id' => $booking->lodgify_booking_id,
            'cottage_id' => $booking->cottage_id,
            'arrival' => $booking->arrival?->toDateString(),
        ]);
    }
}
