<?php

namespace App\Services\Payments;

use App\Models\Booking;
use App\Models\BookingPayment;
use App\Support\ScrubbedContext;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;

/**
 * One line per payment ATTEMPT, in the `payments` log channel.
 *
 * WHY THIS EXISTS SEPARATELY FROM BookingAuditor
 *
 * The audit table answers "what happened to this booking" and the `booking` channel is
 * the incident tail for the whole lifecycle — Lodgify writes, mail, status changes. Neither
 * answers the question an operator actually asks when a guest says "I tried to pay three
 * times and it wouldn't work":
 *
 *     grep PAY-7K2QMD storage/logs/payments-2026-08-25.log
 *
 * That has to return the attempt history and nothing else — opened, reached Stripe,
 * declined with a reason, abandoned, paid, expired — in the order it happened. Mixing it
 * into the booking channel means paging through Lodgify retries to find two lines.
 *
 * The audit trail remains the queryable record and is still written for every one of these
 * events (BookingAuditor mirrors `payment.*` and `stripe.*` events into this channel, so
 * an event raised anywhere in the codebase lands here without a call to this class). This
 * class exists for the attempt-specific detail that has no business in an audit row: which
 * Stripe session was reused, and why we could not open one at all. Everything with a
 * lifecycle meaning — created, link sent, opened, declined, paid, expired — goes through
 * BookingAuditor instead, so it lands in the admin trail as well as here.
 *
 * WHAT IS NEVER WRITTEN HERE
 * Card numbers (we never see one — hosted Stripe Checkout), Stripe secrets, webhook
 * payloads. Everything goes through ScrubbedContext, the same redaction the audit table
 * uses.
 */
class PaymentAttemptLog
{
    /** The channel name, so a caller never has to spell it. */
    public const CHANNEL = 'payments';

    /**
     * We have a live Stripe Checkout Session and are handing the guest to it. THIS is the
     * moment an attempt to pay actually begins.
     *
     * `reused` matters during an investigation: a reused session means the guest is back on
     * an attempt they already started, so a decline on it is not a new decline. Nothing
     * else in the codebase records a reused session — StripeGateway only logs the creation
     * of a NEW one — which is why this call is here and not left to the audit mirror.
     */
    public function reachedStripe(BookingPayment $payment, string $sessionId, bool $reused = false, array $context = []): void
    {
        $this->write('reached_stripe', $payment, $context + [
            'session' => $sessionId,
            'reused_session' => $reused,
        ]);
    }

    /** We could not get the guest as far as Stripe. Nothing was charged. */
    public function unavailable(BookingPayment $payment, string $reason, array $context = []): void
    {
        $this->write('unavailable', $payment, $context + ['reason' => $reason], level: 'error');
    }

    /**
     * A generic attempt event, for callers that already have an event name of their own.
     *
     * This is the entry point BookingAuditor uses to mirror `payment.*` events, so every
     * payment event in the application appears in this file whether or not the code that
     * raised it knows this class exists.
     */
    public function event(string $event, ?Booking $booking, ?BookingPayment $payment, array $context = [], string $level = 'info'): void
    {
        Log::channel(self::CHANNEL)->{$level}($event, ScrubbedContext::make(
            $this->identity($booking, $payment) + ['context' => $context ?: null]
        ));
    }

    /**
     * The shape every line in this file shares.
     *
     * Fixed key order and a fixed vocabulary of `attempt` values, because this file is
     * read by grep far more often than by a person scrolling it.
     */
    protected function write(string $attempt, BookingPayment $payment, array $context = [], string $level = 'info'): void
    {
        $payload = [
            'attempt' => $attempt,
        ] + $this->identity($payment->booking, $payment) + [
            'ip' => $this->ip(),
            'context' => $context ?: null,
        ];

        Log::channel(self::CHANNEL)->{$level}('payment.attempt', ScrubbedContext::make($payload));
    }

    /**
     * Identifiers, and the amount at stake.
     *
     * The guest email is included deliberately: support is given a name and an address,
     * never a payment reference, so a log this file cannot be searched by email is a log
     * nobody can use. It is contact detail we already hold, not payment data.
     *
     * @return array<string, mixed>
     */
    protected function identity(?Booking $booking, ?BookingPayment $payment): array
    {
        $booking ??= $payment?->booking;

        return array_filter([
            'booking' => $booking?->reference,
            'payment' => $payment?->reference,
            'type' => $payment?->type->value,
            'status' => $payment?->status->value,
            'amount' => $payment ? $payment->amount()->format() : null,
            'guest_email' => $booking?->guest_email,
            'stay' => $booking?->stay_label,
            'stripe_session' => $payment?->stripe_checkout_session_id,
            'stripe_intent' => $payment?->stripe_payment_intent_id,
        ], fn ($v) => $v !== null);
    }

    /** Jobs and scheduled commands run without a request, where Request::ip() invents one. */
    protected function ip(): ?string
    {
        return app()->runningInConsole() ? null : Request::ip();
    }
}
