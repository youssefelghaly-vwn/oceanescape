<?php

namespace App\Services\Payments;

use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Jobs\SendPaymentLink;
use App\Models\Booking;
use App\Models\BookingPayment;
use App\Services\Booking\BookingAuditor;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Creates and re-issues the payment rows a booking needs, and the Stripe sessions behind
 * them.
 *
 * IDEMPOTENCY IS THE WHOLE JOB OF THIS CLASS.
 *
 * Every entry point is safe to call twice. `firstOrCreate` on (booking, type) is backed by
 * a UNIQUE constraint, so even two concurrent callers cannot produce two deposit rows —
 * the loser gets a integrity violation and re-reads. The Stripe session is then created
 * with the row's stored idempotency key, so a retried creation returns the original
 * session rather than a second payable link.
 *
 * The consequence of getting this wrong is charging a guest twice, so it is enforced by
 * constraints rather than by remembering to check.
 */
class PaymentLinkService
{
    public function __construct(
        protected StripeGateway $stripe,
        protected BookingAuditor $auditor,
    ) {}

    /**
     * Ensure the payment row for a given type exists with the right amount, then queue
     * its link.
     *
     * Returns the row whether it was created now or already existed.
     */
    public function issue(Booking $booking, PaymentType $type, Money $amount, bool $send = true): BookingPayment
    {
        $payment = $this->ensurePayment($booking, $type, $amount);

        if ($send && $payment->isPayable()) {
            SendPaymentLink::dispatch($payment->getKey());
        }

        return $payment;
    }

    /**
     * Create the payment row, or return the existing one.
     *
     * Wrapped in a transaction with a lock on the booking so two requests arriving
     * together cannot both decide the row is missing. The UNIQUE(booking_id, type) index
     * is the real backstop; this just avoids a noisy integrity error in the common case.
     */
    public function ensurePayment(Booking $booking, PaymentType $type, Money $amount): BookingPayment
    {
        if (! $amount->isPositive()) {
            throw new \InvalidArgumentException(
                "Refusing to create a {$type->value} payment for a zero amount on {$booking->reference}."
            );
        }

        if ($amount->currency !== $booking->currency) {
            throw new \InvalidArgumentException(
                "Currency mismatch creating {$type->value}: {$amount->currency} vs booking {$booking->currency}."
            );
        }

        return DB::transaction(function () use ($booking, $type, $amount) {
            $locked = Booking::query()->whereKey($booking->getKey())->lockForUpdate()->firstOrFail();

            $existing = BookingPayment::query()
                ->where('booking_id', $locked->getKey())
                ->where('type', $type->value)
                ->first();

            if ($existing) {
                /*
                 * Never silently re-price an existing payment. If the amount has moved,
                 * that is a real discrepancy — the guest may already be looking at a link
                 * for the old figure — so record it and keep the original amount.
                 * Re-pricing is a deliberate admin action, not a side effect.
                 */
                if ((int) $existing->amount_cents !== $amount->cents) {
                    $this->auditor->recordFailure('payment.amount_drift', $locked, $existing, [
                        'existing_cents' => (int) $existing->amount_cents,
                        'requested_cents' => $amount->cents,
                    ]);
                }

                return $existing;
            }

            $payment = new BookingPayment([
                'booking_id' => $locked->getKey(),
                'type' => $type->value,
            ]);

            /*
             * forceFill for the money and the idempotency key: these are outside
             * $fillable on purpose, so that no request-shaped array can ever set them.
             */
            $payment->forceFill([
                'status' => PaymentStatus::Pending,
                'amount_cents' => $amount->cents,
                'currency' => $amount->currency,
                'idempotency_key' => $this->idempotencyKeyFor($locked, $type),
                'link_expires_at' => $this->expiryFor($type),
            ])->save();

            $this->auditor->record('payment.created', $locked, $payment, [
                'type' => $type->value,
                'amount' => $amount->format(),
                'expires_at' => $payment->link_expires_at?->toIso8601String(),
            ]);

            return $payment;
        });
    }

    /**
     * Build (or reuse) the Stripe session and mark the link as sent.
     *
     * Called from the SendPaymentLink job rather than inline, so a slow Stripe API call
     * never blocks a guest's request.
     */
    public function prepareSession(BookingPayment $payment): BookingPayment
    {
        $session = $this->stripe->createCheckoutSession($payment);

        $payment->forceFill([
            'stripe_checkout_session_id' => $session->id,
            'stripe_payment_intent_id' => is_string($session->payment_intent)
                ? $session->payment_intent
                : ($session->payment_intent->id ?? null),
        ])->save();

        return $payment;
    }

    /**
     * Re-issue a link whose Stripe session has lapsed.
     *
     * Clears the session id so createCheckoutSession() builds a fresh one, and rotates
     * BOTH the URL token and the idempotency key. Rotating the key is essential: reusing
     * it would make Stripe hand back the original expired session forever.
     */
    public function refresh(BookingPayment $payment): BookingPayment
    {
        if ($payment->status->isSettled()) {
            return $payment;   // nothing to refresh; the money is in
        }

        $old = $payment->stripe_checkout_session_id;

        if (filled($old)) {
            $this->stripe->expireSession($old);
        }

        $payment->forceFill([
            'stripe_checkout_session_id' => null,
            'stripe_payment_intent_id' => null,
            'token' => bin2hex(random_bytes(32)),
            'idempotency_key' => $this->idempotencyKeyFor($payment->booking, $payment->type, refresh: true),
            'link_expires_at' => $this->expiryFor($payment->type),
            'status' => PaymentStatus::Pending,
            'expired_at' => null,
        ])->save();

        $this->auditor->record('payment.link_refreshed', $payment->booking, $payment, [
            'previous_session' => $old,
        ]);

        return $payment;
    }

    public function markLinkSent(BookingPayment $payment): void
    {
        $payment->forceFill([
            'status' => PaymentStatus::LinkSent,
            'link_sent_at' => now(),
            'link_send_count' => $payment->link_send_count + 1,
        ])->save();

        $this->auditor->record('payment.link_sent', $payment->booking, $payment, [
            'send_count' => $payment->link_send_count,
            'expires_at' => $payment->link_expires_at?->toIso8601String(),
        ]);
    }

    /**
     * The key we hand Stripe.
     *
     * Deterministic from (booking, type) so a retry of the same logical request reuses it
     * and Stripe de-duplicates. `refresh` adds entropy, because a deliberately re-issued
     * link MUST get a new session rather than the de-duplicated original.
     */
    protected function idempotencyKeyFor(Booking $booking, PaymentType $type, bool $refresh = false): string
    {
        $parts = [$booking->reference, $type->value];

        if ($refresh) {
            $parts[] = bin2hex(random_bytes(8));
        }

        return substr(hash('sha256', implode('|', $parts)), 0, 60);
    }

    protected function expiryFor(PaymentType $type): Carbon
    {
        return $type === PaymentType::Balance
            ? now()->addDays((int) config('booking.balance_link_ttl_days', 14))
            : now()->addHours((int) config('booking.deposit_link_ttl_hours', 48));
    }
}
