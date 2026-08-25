<?php

namespace App\Services\Booking;

use App\Models\Booking;
use App\Models\BookingAuditLog;
use App\Models\BookingPayment;
use App\Services\Payments\PaymentAttemptLog;
use App\Support\ScrubbedContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;

/**
 * Writes the audit trail for the booking and payment lifecycle.
 *
 * EVERY STATE CHANGE GOES THROUGH HERE, to two places:
 *   - booking_audit_logs, which is queryable and joinable in an admin screen
 *   - the `booking` log channel, which is what you tail during an incident and which
 *     survives the database being the thing that broke
 *
 * AND, for anything to do with money, to a third: `payment.*` and `stripe.*` events are
 * MIRRORED into the `payments` channel (storage/logs/payments-*.log). That mirroring lives
 * here rather than at each call site so that a payment event raised anywhere in the
 * codebase — settler, sweeper, webhook, a service written next year — lands in the payment
 * attempt log without its author having to remember to put it there.
 *
 * WHAT MUST NEVER REACH THE CONTEXT COLUMN
 * Card data (we never see any), Stripe secrets, webhook signing secrets, full webhook
 * payloads, or raw API keys. `scrub()` enforces this by allowlisting scalar values and
 * redacting anything whose key looks sensitive — because an audit trail is read by more
 * people than the code that writes it, and "I'll be careful at the call site" does not
 * survive a year of edits.
 */
class BookingAuditor
{
    public function __construct(protected PaymentAttemptLog $attempts) {}

    /** @param array<string, mixed> $context */
    public function record(
        string $event,
        ?Booking $booking = null,
        ?BookingPayment $payment = null,
        array $context = [],
        ?string $fromStatus = null,
        ?string $toStatus = null,
        string $actorType = 'system',
    ): void {
        $clean = $this->scrub($context);

        try {
            BookingAuditLog::create([
                'booking_id' => $booking?->getKey() ?? $payment?->booking_id,
                'booking_payment_id' => $payment?->getKey(),
                'event' => $event,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'actor_type' => $actorType,
                'actor_id' => Auth::id(),
                'context' => $clean,
                'ip_address' => $this->ip(),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            /*
             * An audit write must never break the flow it is describing — losing the
             * trail is bad, failing a paid booking because we could not log it is worse.
             * The log channel is the fallback record that this happened.
             */
            Log::channel('booking')->error('Audit log write failed', [
                'event' => $event,
                'booking' => $booking?->reference,
                'message' => $e->getMessage(),
            ]);
        }

        Log::channel('booking')->info($event, array_filter([
            'booking' => $booking?->reference ?? $payment?->booking?->reference,
            'payment' => $payment?->reference,
            'from' => $fromStatus,
            'to' => $toStatus,
            'actor' => $actorType,
            'context' => $clean ?: null,
        ], fn ($v) => $v !== null));

        if ($this->isPaymentEvent($event, $payment)) {
            $this->attempts->event($event, $booking, $payment, $clean);
        }
    }

    /** Convenience for a booking status change, so from/to are never forgotten. */
    public function recordTransition(
        Booking $booking,
        string $event,
        string $fromStatus,
        string $toStatus,
        array $context = [],
        string $actorType = 'system',
    ): void {
        $this->record(
            event: $event,
            booking: $booking,
            context: $context,
            fromStatus: $fromStatus,
            toStatus: $toStatus,
            actorType: $actorType,
        );
    }

    /**
     * Something went wrong that a human needs to see. Logged at error level so it trips
     * alerting, and recorded in the trail so the booking's own history explains itself.
     */
    public function recordFailure(
        string $event,
        ?Booking $booking = null,
        ?BookingPayment $payment = null,
        array $context = [],
        string $actorType = 'system',
    ): void {
        $clean = $this->scrub($context);

        $this->record($event, $booking, $payment, $context, actorType: $actorType);

        Log::channel('booking')->error($event, [
            'booking' => $booking?->reference ?? $payment?->booking?->reference,
            'payment' => $payment?->reference,
            'context' => $clean,
        ]);

        /*
         * Also at error level in the payment log. A failure that record() already mirrored
         * at info would otherwise be invisible to anything alerting on level — and
         * payment.amount_mismatch is precisely the line that must not be missed.
         */
        if ($this->isPaymentEvent($event, $payment)) {
            $this->attempts->event($event, $booking, $payment, $clean, level: 'error');
        }
    }

    /**
     * Redaction lives in App\Support\ScrubbedContext, shared with the payment attempt
     * log — one list of sensitive keys for everything we write about a payment, so the two
     * cannot drift into redacting different things.
     *
     * @param  array<mixed>  $context
     * @return array<mixed>
     */
    protected function scrub(array $context): array
    {
        return ScrubbedContext::make($context);
    }

    /**
     * Is this a money event?
     *
     * Prefix match, not a list of known event names: a new `payment.*` event added next
     * year has to appear in the payment log without anyone remembering to register it
     * here. `lodgify.record_payment.*` is included because reporting a payment back to
     * Lodgify is part of the payment's story even though it is a Lodgify call.
     */
    protected function isPaymentEvent(string $event, ?BookingPayment $payment = null): bool
    {
        /*
         * `mail.*` counts only when a payment is attached: a payment-link email is part of
         * the payment's story, an ops alert about a Lodgify write is not.
         */
        if (str_starts_with($event, 'mail.')) {
            return $payment !== null;
        }

        return str_starts_with($event, 'payment.')
            || str_starts_with($event, 'stripe.')
            || str_starts_with($event, 'lodgify.record_payment');
    }

    /**
     * Request IP, when there is a request. Jobs and scheduled commands run without one,
     * and Request::ip() would throw or invent a value.
     */
    protected function ip(): ?string
    {
        return app()->runningInConsole() ? null : Request::ip();
    }
}
