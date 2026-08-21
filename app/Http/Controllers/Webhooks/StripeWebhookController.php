<?php

namespace App\Http\Controllers\Webhooks;

use App\Enums\PaymentStatus;
use App\Models\BookingPayment;
use App\Models\StripeWebhookEvent;
use App\Services\Payments\PaymentSettler;
use App\Services\Payments\StripeGateway;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Stripe\Event;

/**
 * Stripe webhook endpoint.
 *
 * THIS IS THE SECURITY BOUNDARY OF THE ENTIRE PAYMENTS FEATURE.
 *
 * The route is necessarily public and CSRF-exempt — Stripe cannot present a CSRF token —
 * so signature verification is the ONLY thing standing between this database and anyone
 * who can POST to the URL. Without it, a stranger could mark any booking paid and confirm
 * a reservation for free. Consequences of that shape the whole method:
 *
 *   1. The RAW body is verified before it is parsed. Using $request->json() first would
 *      mean trusting attacker-controlled data to decide how to verify it.
 *   2. A bad signature returns 400 with no detail. Never say which part failed.
 *   3. Every accepted event is INSERTED before it is processed. The unique index on
 *      stripe_event_id makes a duplicate delivery an atomic no-op — see below.
 *   4. Handler failures return 500 ON PURPOSE, so Stripe retries. Returning 200 on an
 *      error would tell Stripe the event was handled and permanently lose a paid booking.
 *
 * WHY INSERT-BEFORE-PROCESS RATHER THAN "if (!exists)"
 * Stripe guarantees at-least-once delivery. Two deliveries of the same event can land on
 * two workers in the same millisecond; a check-then-act would let both through the gap
 * between the check and the write. Attempting the INSERT and catching the unique violation
 * pushes the race down to the database, where it is atomic.
 */
class StripeWebhookController extends Controller
{
    public function __construct(
        protected StripeGateway $stripe,
        protected PaymentSettler $settler,
    ) {}

    public function handle(Request $request): Response
    {
        // ---- 1. verify the signature against the RAW body ---------------------
        $event = $this->stripe->verifyWebhook(
            $request->getContent(),
            $request->header('Stripe-Signature'),
        );

        if ($event === null) {
            // No detail. A prober learns only that it was rejected.
            return response('Invalid signature.', 400);
        }

        // ---- 2. record it before acting, so replays are free ------------------
        try {
            $record = StripeWebhookEvent::create([
                'stripe_event_id' => $event->id,
                'type' => $event->type,
                'api_version' => $event->api_version,
                'payload' => $event->toArray(),
                'status' => StripeWebhookEvent::STATUS_RECEIVED,
            ]);
        } catch (UniqueConstraintViolationException) {
            /*
             * Already seen. This is the normal, expected outcome of a Stripe retry — not
             * an error. 200 so Stripe stops redelivering.
             */
            Log::channel('booking')->info('Duplicate Stripe webhook ignored', [
                'event_id' => $event->id,
                'type' => $event->type,
            ]);

            return response('Already processed.', 200);
        }

        // ---- 3. dispatch by type ---------------------------------------------
        try {
            $handled = match ($event->type) {
                'checkout.session.completed' => $this->onSessionCompleted($event, $record),
                'checkout.session.async_payment_succeeded' => $this->onAsyncSucceeded($event, $record),
                'checkout.session.async_payment_failed' => $this->onAsyncFailed($event, $record),
                'checkout.session.expired' => $this->onSessionExpired($event, $record),
                'charge.refunded' => $this->onChargeRefunded($event, $record),
                default => null,
            };

            if ($handled === null) {
                // Valid, ours, but nothing to do. Recorded so the trail is complete.
                $record->markIgnored("No handler for {$event->type}");

                return response('Ignored.', 200);
            }

            $record->markProcessed($handled);

            return response('OK', 200);
        } catch (\Throwable $e) {
            $record->markFailed($e->getMessage());

            Log::channel('booking')->error('Stripe webhook handler failed', [
                'event_id' => $event->id,
                'type' => $event->type,
                'message' => $e->getMessage(),
            ]);

            /*
             * 500 DELIBERATELY. Stripe will retry with backoff, and the insert-before-
             * process guard above means the retry is safe. Answering 200 here would tell
             * Stripe we handled it and strand a real payment.
             */
            return response('Handler failed; please retry.', 500);
        }
    }

    /**
     * The main path. A Checkout Session finished.
     *
     * `payment_status` is checked rather than assumed: some methods complete the session
     * as `unpaid` and settle later. Confirming a booking on session completion alone would
     * confirm it against money that has not arrived.
     */
    protected function onSessionCompleted(Event $event, StripeWebhookEvent $record): ?int
    {
        $session = $event->data->object;
        $payment = $this->resolvePayment($session);

        if (! $payment) {
            return null;
        }

        if (($session->payment_status ?? null) !== 'paid') {
            $this->settler->markProcessing($payment);

            return $payment->getKey();
        }

        $captured = $this->capturedFrom($session);

        if ($captured === null) {
            throw new \RuntimeException(
                "Session {$session->id} completed but carried no readable amount."
            );
        }

        $this->settler->settle(
            payment: $payment,
            captured: $captured,
            paymentIntentId: $this->idOf($session->payment_intent ?? null),
            customerId: $this->idOf($session->customer ?? null),
        );

        return $payment->getKey();
    }

    /** A delayed method finally cleared. */
    protected function onAsyncSucceeded(Event $event, StripeWebhookEvent $record): ?int
    {
        $session = $event->data->object;
        $payment = $this->resolvePayment($session);

        if (! $payment) {
            return null;
        }

        $captured = $this->capturedFrom($session);

        if ($captured === null) {
            throw new \RuntimeException("Async success for {$session->id} carried no amount.");
        }

        $this->settler->settle(
            payment: $payment,
            captured: $captured,
            paymentIntentId: $this->idOf($session->payment_intent ?? null),
            customerId: $this->idOf($session->customer ?? null),
        );

        return $payment->getKey();
    }

    protected function onAsyncFailed(Event $event, StripeWebhookEvent $record): ?int
    {
        $payment = $this->resolvePayment($event->data->object);

        if (! $payment) {
            return null;
        }

        $this->settler->markFailed($payment, 'Asynchronous payment failed at Stripe.');

        return $payment->getKey();
    }

    protected function onSessionExpired(Event $event, StripeWebhookEvent $record): ?int
    {
        $payment = $this->resolvePayment($event->data->object);

        if (! $payment) {
            return null;
        }

        /*
         * Only the Stripe session lapsed. Our own signed link may still be live, in which
         * case PaymentController mints a fresh session on the next visit — so this is
         * recorded but does not kill the payment.
         */
        $this->settler->markExpired($payment);

        return $payment->getKey();
    }

    /**
     * A refund was issued (from the Stripe dashboard, most likely).
     *
     * Recorded, but deliberately does NOT cancel the reservation. A partial refund, a
     * goodwill gesture and a genuine cancellation look identical here, and unbooking
     * someone's stay is not a decision to take from a webhook. An admin does that.
     */
    protected function onChargeRefunded(Event $event, StripeWebhookEvent $record): ?int
    {
        $charge = $event->data->object;

        $payment = BookingPayment::query()
            ->where('stripe_charge_id', $charge->id)
            ->orWhere('stripe_payment_intent_id', $this->idOf($charge->payment_intent ?? null))
            ->first();

        if (! $payment) {
            return null;
        }

        $refunded = (int) ($charge->amount_refunded ?? 0);

        $payment->forceFill([
            'refunded_cents' => $refunded,
            'refunded_at' => now(),
            'status' => $refunded >= (int) $payment->amount_cents
                ? PaymentStatus::Refunded
                : $payment->status,
        ])->save();

        Log::channel('booking')->warning('Payment refunded at Stripe', [
            'payment' => $payment->reference,
            'booking' => $payment->booking?->reference,
            'refunded_cents' => $refunded,
            'note' => 'Reservation NOT cancelled automatically — needs an admin decision.',
        ]);

        return $payment->getKey();
    }

    /**
     * Find our payment row for a Stripe session.
     *
     * Tries the session id first, then our own reference from metadata. Both come from a
     * SIGNATURE-VERIFIED payload, so trusting them is sound — but note we look the row up
     * rather than taking any state from the payload beyond identifiers and the amount.
     */
    protected function resolvePayment(mixed $session): ?BookingPayment
    {
        $payment = BookingPayment::query()
            ->with('booking')
            ->where('stripe_checkout_session_id', $session->id ?? null)
            ->first();

        if ($payment) {
            return $payment;
        }

        $reference = $session->metadata->payment_reference ?? null;

        if (filled($reference)) {
            $payment = BookingPayment::query()->with('booking')->where('reference', $reference)->first();

            if ($payment) {
                // Backfill so the next event for this session resolves on the fast path.
                if (blank($payment->stripe_checkout_session_id) && filled($session->id ?? null)) {
                    $payment->forceFill(['stripe_checkout_session_id' => $session->id])->save();
                }

                return $payment;
            }
        }

        /*
         * Not ours. Most likely a payment made through a different integration on the same
         * Stripe account. Logged at info, not error: this is expected noise, and treating
         * it as a failure would make Stripe retry something we will never handle.
         */
        Log::channel('booking')->info('Stripe event did not match a known payment', [
            'session_id' => $session->id ?? null,
            'reference' => $reference,
        ]);

        return null;
    }

    protected function capturedFrom(mixed $session): ?Money
    {
        if (! is_numeric($session->amount_total ?? null) || blank($session->currency ?? null)) {
            return null;
        }

        return Money::fromCents(
            (int) $session->amount_total,
            strtoupper((string) $session->currency),
        );
    }

    /** Stripe fields are either an id string or an expanded object. */
    protected function idOf(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }

        return is_object($value) ? ($value->id ?? null) : null;
    }
}
