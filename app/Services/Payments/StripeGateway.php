<?php

namespace App\Services\Payments;

use App\Enums\PaymentType;
use App\Exceptions\StripeNotConfigured;
use App\Models\BookingPayment;
use App\Support\Money;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Stripe\Checkout\Session as CheckoutSession;
use Stripe\Event;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClient;
use Stripe\Webhook;
use UnexpectedValueException;

/**
 * The only place that talks to Stripe.
 *
 * HOSTED CHECKOUT, NOT THE EMBEDDED ELEMENT — deliberately.
 *
 * Card details are entered on checkout.stripe.com and never reach this server, which keeps
 * us in PCI SAQ A rather than SAQ A-EP. Lodgify's hosted checkout used to give us that for
 * free by taking the payment itself; now that it has been removed from the project, hosted
 * Stripe Checkout is what preserves the same property. We changed WHO takes the payment,
 * not whether we handle card data.
 *
 * THE AMOUNT IS NEVER TAKEN FROM THE CLIENT. Sessions are built from a BookingPayment row
 * whose amount was derived server-side from a Lodgify quote by DepositPolicy. There is no
 * code path where a request parameter influences what is charged.
 *
 * NO CARD IS EVER SAVED.
 *
 * Every session is built by sessionPayload(), and that payload deliberately omits all four
 * of the things that would make a card reusable:
 *
 *   setup_future_usage           would tell Stripe to keep the payment method on file
 *   customer                     would attach the card to a stored Customer
 *   customer_creation            would create one for a guest checkout
 *   saved_payment_method_options would offer "save this card" in the UI
 *
 * `customer_email` is a prefill for the Stripe form and does NOT create a Customer object.
 * Every payment is therefore a fresh card entry on Stripe's own page, for a returning guest
 * as much as a first-time one. NoCardSavingTest asserts the absence of each key, so adding
 * one is a test failure rather than a quiet policy change.
 */
class StripeGateway
{
    private ?StripeClient $client = null;

    /**
     * The client is built LAZILY, on first use — never in the constructor.
     *
     * StripeClient throws if the api_key is an empty string, and this class is
     * constructor-injected into PaymentController. Building it eagerly meant that an
     * unconfigured Stripe key took down anything that merely RESOLVED the controller —
     * including `route:list` and every unrelated page — rather than only the payment
     * paths. This mirrors LodgifyClient, which logs a warning for a missing key instead
     * of throwing.
     */
    protected function client(): StripeClient
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $secret = trim((string) config('services.stripe.secret'));

        if ($secret === '') {
            Log::channel('booking')->error(
                'Stripe secret is not configured. Set STRIPE_SECRET in .env.'
            );

            throw new StripeNotConfigured(
                'Stripe is not configured: services.stripe.secret is empty.'
            );
        }

        $config = ['api_key' => $secret];

        /*
         * Only override the SDK's own pinned version when explicitly told to. The typed
         * objects in stripe-php are written against \Stripe\Util\ApiVersion::CURRENT, so
         * forcing a different version is how responses stop deserialising.
         */
        if (filled(config('services.stripe.api_version'))) {
            $config['stripe_version'] = (string) config('services.stripe.api_version');
        }

        return $this->client = new StripeClient($config);
    }

    public function isConfigured(): bool
    {
        return filled(config('services.stripe.secret'))
            && filled(config('services.stripe.webhook_secret'));
    }

    /**
     * Create (or re-fetch) the Checkout Session for a payment.
     *
     * IDEMPOTENCY, TWO LAYERS DEEP:
     *
     * 1. If the row already carries a session id, we retrieve that session rather than
     *    making another. One BookingPayment has exactly one Stripe session for its life.
     * 2. The creation call sends `idempotency_key` = the row's stored key. If the request
     *    is retried — network blip, queue retry, double-click — Stripe returns the
     *    ORIGINAL session instead of creating a second one. Without this, a retry is a
     *    second payable link for the same money.
     *
     * @throws ApiErrorException
     */
    public function createCheckoutSession(BookingPayment $payment): CheckoutSession
    {
        if (filled($payment->stripe_checkout_session_id)) {
            $existing = $this->retrieveSession($payment->stripe_checkout_session_id);

            // Reuse only while it is still usable; an expired session must be replaced.
            if ($existing !== null && $existing->status !== 'expired') {
                return $existing;
            }
        }

        $session = $this->client()->checkout->sessions->create(
            $this->sessionPayload($payment),
            ['idempotency_key' => $payment->idempotency_key],
        );

        Log::stack(['booking', 'payments'])->info('stripe.session.created', [
            'booking' => $payment->booking->reference,
            'payment' => $payment->reference,
            'session_id' => $session->id,
            'amount' => $payment->amount()->format(),
        ]);

        return $session;
    }

    /**
     * Everything we send Stripe to open a Checkout Session.
     *
     * PUBLIC, and separate from createCheckoutSession(), so that what we ask Stripe for can
     * be asserted on without a network call — specifically the four keys that are NOT here
     * (see the class docblock: no card is ever saved). A guarantee nothing can test is a
     * guarantee that lasts until the next edit.
     *
     * @return array<string, mixed>
     */
    public function sessionPayload(BookingPayment $payment): array
    {
        $booking = $payment->booking;
        $amount = $payment->amount();

        return [
            'mode' => 'payment',
            'client_reference_id' => $payment->reference,

            /*
             * A PREFILL ONLY. Passing customer_email does not create a Stripe Customer and
             * does not store a payment method; it saves the guest typing an address they
             * have already given us. Nothing here makes a card reusable.
             */
            'customer_email' => $booking->guest_email,

            /*
             * Expire the session at the same moment our own signed link expires, so a
             * guest can never reach a live Stripe page from a dead link, or vice versa.
             * Stripe requires this to be 30 minutes to 24 hours out, so it is clamped.
             */
            'expires_at' => $this->sessionExpiry($payment),

            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => $amount->stripeCurrency(),
                    'unit_amount' => $amount->cents,
                    'product_data' => [
                        'name' => $this->lineItemName($payment),
                        'description' => $this->lineItemDescription($payment),
                    ],
                ],
            ]],

            /*
             * Metadata is what lets the webhook find its way back to our records without
             * trusting anything in the request. Kept to ids and references — never guest
             * contact details, since metadata is visible to anyone with dashboard access.
             */
            'metadata' => [
                'booking_reference' => $booking->reference,
                'payment_reference' => $payment->reference,
                'payment_id' => (string) $payment->getKey(),
                'booking_id' => (string) $booking->getKey(),
                'payment_type' => $payment->type->value,
                'lodgify_booking_id' => (string) ($booking->lodgify_booking_id ?? ''),
            ],

            'payment_intent_data' => [
                'description' => $this->lineItemName($payment),
                'metadata' => [
                    'booking_reference' => $booking->reference,
                    'payment_reference' => $payment->reference,
                ],
            ],

            'success_url' => route('booking.pay.success', ['token' => $payment->token])
                             .'?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => route('booking.pay.cancelled', ['token' => $payment->token]),
        ];
    }

    public function retrieveSession(string $sessionId): ?CheckoutSession
    {
        try {
            return $this->client()->checkout->sessions->retrieve($sessionId, [
                // Needed to read the settled charge and its amount in one round trip.
                'expand' => ['payment_intent'],
            ]);
        } catch (ApiErrorException $e) {
            Log::channel('booking')->warning('Could not retrieve Stripe session', [
                'session_id' => $sessionId,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Expire a session so an abandoned link cannot be paid later against a price we no
     * longer honour. Safe to call on an already-expired or already-paid session.
     */
    public function expireSession(string $sessionId): bool
    {
        try {
            $this->client()->checkout->sessions->expire($sessionId);

            return true;
        } catch (ApiErrorException $e) {
            // Already expired or already completed — nothing to do either way.
            Log::channel('booking')->info('Stripe session could not be expired', [
                'session_id' => $sessionId,
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Verify a webhook signature and return the event.
     *
     * THIS IS THE ONLY THING STANDING BETWEEN OUR DATABASE AND ANYONE WHO CAN POST TO THE
     * WEBHOOK URL. The endpoint is necessarily public and CSRF-exempt, so an unverified
     * payload would let a stranger mark any booking paid. Never parse the body before
     * this succeeds.
     *
     * Returns null on a bad signature so the controller can answer 400 without leaking
     * which part failed.
     */
    public function verifyWebhook(string $payload, ?string $signatureHeader): ?Event
    {
        $secret = (string) config('services.stripe.webhook_secret');

        if ($secret === '') {
            Log::channel('booking')->critical(
                'Stripe webhook received but STRIPE_WEBHOOK_SECRET is not set. Rejecting.'
            );

            return null;
        }

        if (blank($signatureHeader)) {
            Log::channel('booking')->warning('Stripe webhook rejected: no signature header.');

            return null;
        }

        try {
            return Webhook::constructEvent(
                $payload,
                $signatureHeader,
                $secret,
                // Bounds replay of a captured request. Stripe's default is 300s.
                (int) config('services.stripe.webhook_tolerance', 300),
            );
        } catch (SignatureVerificationException $e) {
            Log::channel('booking')->warning('Stripe webhook rejected: bad signature.', [
                'message' => $e->getMessage(),
            ]);

            return null;
        } catch (UnexpectedValueException $e) {
            Log::channel('booking')->warning('Stripe webhook rejected: unparseable payload.', [
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * What Stripe actually captured on a session, in our Money type.
     *
     * Read from `amount_total` and compared against what we asked for before any booking
     * is confirmed — see BookingPayment::amountMatches(). Trusting the session to be for
     * the right amount, rather than checking, is how a tampered or mis-built session gets
     * a booking confirmed for less than it costs.
     */
    public function capturedAmount(CheckoutSession $session): ?Money
    {
        if (! is_numeric($session->amount_total) || blank($session->currency)) {
            return null;
        }

        return Money::fromCents((int) $session->amount_total, strtoupper((string) $session->currency));
    }

    /**
     * Stripe requires expires_at between 30 minutes and 24 hours from now, so our own
     * link TTL (which can be days) is clamped into that range. The authoritative expiry
     * remains OUR signed URL — this just stops a stale Stripe page lingering.
     */
    protected function sessionExpiry(BookingPayment $payment): int
    {
        $desired = $payment->link_expires_at?->getTimestamp() ?? now()->addHours(24)->getTimestamp();

        $min = now()->addMinutes(31)->getTimestamp();
        $max = now()->addHours(23)->getTimestamp();

        return max($min, min($desired, $max));
    }

    protected function lineItemName(BookingPayment $payment): string
    {
        $booking = $payment->booking;

        return match ($payment->type) {
            PaymentType::Deposit => "Deposit — {$booking->cottage_name}",
            PaymentType::Balance => "Balance — {$booking->cottage_name}",
            PaymentType::Full => "Stay — {$booking->cottage_name}",
        };
    }

    protected function lineItemDescription(BookingPayment $payment): string
    {
        $booking = $payment->booking;

        return sprintf(
            '%s · %d %s · %s · booking %s',
            $booking->stay_label,
            $booking->nights,
            Str::plural('night', $booking->nights),
            $booking->party_label,
            $booking->reference,
        );
    }
}
