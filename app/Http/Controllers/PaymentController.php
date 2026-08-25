<?php

namespace App\Http\Controllers;

use App\Enums\PaymentStatus;
use App\Models\BookingPayment;
use App\Services\Booking\BookingAuditor;
use App\Services\Payments\PaymentLinkService;
use App\Services\Payments\PaymentSettler;
use App\Services\Payments\StripeGateway;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The guest-facing payment pages.
 *
 * WHY WE ROUTE THROUGH OUR OWN DOMAIN INSTEAD OF EMAILING A STRIPE URL
 *
 *   - A Stripe session URL cannot be revoked once it is in an inbox.
 *   - Stripe sessions carry their own expiry, up to 24h, which we cannot shorten to match
 *     our deposit window.
 *   - An expired session shows the guest a bare Stripe error. Coming through our page, we
 *     can mint a fresh session and carry on.
 *   - It gives us one place to record that the link was opened.
 *
 * The `pay` route is a SIGNED, EXPIRING route, and the token in the path is 32 random
 * bytes rather than an id — so payment links are neither guessable nor enumerable from a
 * neighbouring booking.
 */
class PaymentController extends Controller
{
    public function __construct(
        protected StripeGateway $stripe,
        protected PaymentLinkService $links,
        protected PaymentSettler $settler,
        protected BookingAuditor $auditor,
    ) {}

    /**
     * GET /pay/{token}  (signed)
     *
     * Redirects to Stripe Checkout, re-minting the session if the old one has lapsed.
     */
    public function show(string $token): RedirectResponse|View
    {
        $payment = $this->findPayment($token);

        $this->auditor->record('payment.link_opened', $payment->booking, $payment);

        // Already paid: show the receipt rather than a second checkout.
        if ($payment->status->isSettled()) {
            return view('pages.payment-already-paid', [
                'booking' => $payment->booking,
                'payment' => $payment,
            ]);
        }

        if ($payment->booking->status->isTerminal()) {
            return view('pages.payment-unavailable', [
                'booking' => $payment->booking,
                'payment' => $payment,
                'reason' => 'This booking is no longer active.',
            ]);
        }

        try {
            /*
             * A lapsed link gets a brand-new session here rather than a dead end. The
             * signed-URL expiry has already been checked by the `signed` middleware, so
             * reaching this point means the guest is inside the window we promised them.
             */
            if ($payment->isExpired()) {
                $payment = $this->links->refresh($payment);
            }

            $session = $this->stripe->createCheckoutSession($payment);

            $payment->forceFill([
                'stripe_checkout_session_id' => $session->id,
                'status' => $payment->status === PaymentStatus::Pending
                    ? PaymentStatus::LinkSent
                    : $payment->status,
            ])->save();
        } catch (\Throwable $e) {
            Log::channel('booking')->error('Could not start Stripe checkout', [
                'payment' => $payment->reference,
                'message' => $e->getMessage(),
            ]);

            return view('pages.payment-unavailable', [
                'booking' => $payment->booking,
                'payment' => $payment,
                'reason' => 'We could not open the payment page just now.',
            ]);
        }

        return redirect()->away($session->url);
    }

    /**
     * GET /pay/{token}/success
     *
     * The return URL. NOT where a booking is confirmed — that is the webhook's job.
     *
     * This matters: a guest can close the tab, lose signal, or never come back, and the
     * payment is still real. Equally, reaching this URL proves nothing on its own; anyone
     * could request it. So this page only READS state. It does opportunistically reconcile
     * from Stripe so the guest is not told "awaiting payment" a second after paying, and
     * that reconciliation goes through the same idempotent settler the webhook uses.
     */
    public function success(string $token): View
    {
        $payment = $this->findPayment($token);

        if (! $payment->status->isSettled()) {
            $this->reconcileFromStripe($payment);
            $payment->refresh();
        }

        return view('pages.payment-thanks', [
            'booking' => $payment->booking->fresh(),
            'payment' => $payment,
        ]);
    }

    /** GET /pay/{token}/cancelled — the guest backed out. The link stays usable. */
    public function cancelled(string $token): View
    {
        $payment = $this->findPayment($token);

        $this->auditor->record('payment.checkout_abandoned', $payment->booking, $payment);

        return view('pages.payment-cancelled', [
            'booking' => $payment->booking,
            'payment' => $payment,
            'payUrl' => $payment->isPayable() ? $payment->payUrl() : null,
        ]);
    }

    /**
     * Pull the session from Stripe and settle if it really is paid.
     *
     * Belt and braces for webhook latency. Uses the SAME PaymentSettler as the webhook, so
     * the amount check and the idempotency guard apply identically — whichever path
     * arrives second is a no-op.
     */
    protected function reconcileFromStripe(BookingPayment $payment): void
    {
        if (blank($payment->stripe_checkout_session_id)) {
            return;
        }

        try {
            $session = $this->stripe->retrieveSession($payment->stripe_checkout_session_id);

            if (! $session || $session->payment_status !== 'paid') {
                return;
            }

            $captured = $this->stripe->capturedAmount($session);

            if ($captured === null) {
                return;
            }

            $intent = is_string($session->payment_intent)
                ? $session->payment_intent
                : ($session->payment_intent->id ?? null);

            $this->settler->settle(
                payment: $payment,
                captured: $captured,
                paymentIntentId: $intent,
                customerId: is_string($session->customer) ? $session->customer : null,
            );
        } catch (\Throwable $e) {
            // The webhook remains the authoritative path; this was only a courtesy.
            Log::channel('booking')->info('Return-page reconciliation did not complete', [
                'payment' => $payment->reference,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Resolve the token to a payment.
     *
     * Constant-time comparison is not required here because the token is looked up rather
     * than compared, and the route is signature-verified before it runs. A miss is a 404
     * with no detail — never "no such payment" versus "wrong booking", which would confirm
     * to a prober which tokens exist.
     */
    protected function findPayment(string $token): BookingPayment
    {
        $payment = BookingPayment::query()->with('booking')->where('token', $token)->first();

        if (! $payment || ! $payment->booking) {
            throw new NotFoundHttpException('Payment not found.');
        }

        return $payment;
    }
}
