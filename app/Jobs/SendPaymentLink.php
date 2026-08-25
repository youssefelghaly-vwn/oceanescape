<?php

namespace App\Jobs;

use App\Enums\PaymentType;
use App\Mail\PaymentLinkMail;
use App\Models\BookingPayment;
use App\Services\Booking\BookingAuditor;
use App\Services\Payments\PaymentLinkService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

/**
 * Build the Stripe session for a payment and email the guest its link.
 *
 * Queued rather than inline so a slow Stripe API call never blocks the guest's confirm
 * request — they get "check your email" immediately and the link follows.
 *
 * IDEMPOTENT in the way that matters: the Stripe session is created with the payment row's
 * stored idempotency key, so a retry reuses the original session rather than producing a
 * second payable link. A retry may therefore send a duplicate EMAIL, which is a mild
 * annoyance; it can never create a duplicate CHARGE.
 */
class SendPaymentLink implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public array $backoff = [10, 60, 300, 900];

    public function __construct(public int $paymentId) {}

    public function handle(
        PaymentLinkService $links,
        BookingAuditor $auditor,
    ): void {
        /** @var BookingPayment|null $payment */
        $payment = BookingPayment::query()->with('booking')->find($this->paymentId);

        if (! $payment) {
            return;
        }

        // Do not chase money that has already arrived, or a cancelled stay.
        if ($payment->status->isSettled() || $payment->booking->status->isTerminal()) {
            return;
        }

        /*
         * A lapsed link needs a new Stripe session before it can be sent, otherwise the
         * guest follows it to a dead Stripe page. refresh() rotates the token AND the
         * idempotency key — reusing the key would make Stripe hand back the same expired
         * session forever.
         */
        if ($payment->isExpired()) {
            $payment = $links->refresh($payment);
        }

        $payment = $links->prepareSession($payment);

        Mail::to($payment->booking->guest_email)
            ->send(new PaymentLinkMail($payment->booking->fresh(), $payment->fresh()));

        $links->markLinkSent($payment);

        $auditor->record('payment.link_emailed', $payment->booking, $payment, [
            'type' => $payment->type->value,
            'to' => $payment->booking->guest_email,
        ]);
    }

    public function failed(\Throwable $e): void
    {
        $payment = BookingPayment::query()->with('booking')->find($this->paymentId);

        if (! $payment) {
            return;
        }

        app(BookingAuditor::class)->recordFailure('payment.link_send_exhausted', $payment->booking, $payment, [
            'message' => $e->getMessage(),
            'note' => $payment->type === PaymentType::Balance
                ? 'Guest has not been asked for the balance. Chase manually.'
                : 'Guest has an Open reservation but no way to pay for it. Chase manually.',
        ]);
    }
}
