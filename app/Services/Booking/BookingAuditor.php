<?php

namespace App\Services\Booking;

use App\Models\Booking;
use App\Models\BookingAuditLog;
use App\Models\BookingPayment;
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
 * WHAT MUST NEVER REACH THE CONTEXT COLUMN
 * Card data (we never see any), Stripe secrets, webhook signing secrets, full webhook
 * payloads, or raw API keys. `scrub()` enforces this by allowlisting scalar values and
 * redacting anything whose key looks sensitive — because an audit trail is read by more
 * people than the code that writes it, and "I'll be careful at the call site" does not
 * survive a year of edits.
 */
class BookingAuditor
{
    /**
     * Keys that are redacted wherever they appear, at any depth.
     */
    protected const REDACT = [
        'secret', 'password', 'token', 'api_key', 'apikey', 'authorization',
        'card', 'cvc', 'cvv', 'number', 'signature', 'client_secret',
    ];

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
    }

    /**
     * Recursively redact sensitive keys and flatten anything that is not a scalar.
     *
     * @param  array<mixed>  $context
     * @return array<mixed>
     */
    protected function scrub(array $context, int $depth = 0): array
    {
        if ($depth > 4) {
            return ['_truncated' => 'nesting too deep for an audit record'];
        }

        $out = [];

        foreach ($context as $key => $value) {
            if ($this->isSensitive((string) $key)) {
                $out[$key] = '[redacted]';

                continue;
            }

            $out[$key] = match (true) {
                is_array($value) => $this->scrub($value, $depth + 1),
                is_scalar($value), is_null($value) => $value,
                $value instanceof \DateTimeInterface => $value->format(DATE_ATOM),
                $value instanceof \Stringable => (string) $value,
                // Objects are summarised rather than serialised: an audit row should
                // never become a dumping ground for a whole API response.
                default => '['.get_debug_type($value).']',
            };
        }

        return $out;
    }

    protected function isSensitive(string $key): bool
    {
        $needle = strtolower($key);

        foreach (self::REDACT as $bad) {
            if (str_contains($needle, $bad)) {
                return true;
            }
        }

        return false;
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
