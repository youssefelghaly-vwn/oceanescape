<?php

namespace App\Mail;

use App\Enums\PaymentType;
use App\Models\Booking;
use App\Models\BookingPayment;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The email carrying a payment link.
 *
 * TWO TEMPLATES, ONE MAILABLE. `mail.payment-deposit` and `mail.payment-balance` are
 * separate files because they are separate messages: the deposit mail has to say that the
 * dates are NOT held and will be released when the link expires, while the balance mail goes
 * to someone whose stay is already confirmed and nothing is at risk. Branching inside one
 * template produced copy that read as neither.
 *
 * The link is generated at RENDER time from BookingPayment::payUrl(), which mints a fresh
 * signed, expiring URL. It is not stored on the model or in this class, so a queued
 * mailable serialised for an hour cannot carry an already-dead signature.
 */
class PaymentLinkMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public Booking $booking,
        public BookingPayment $payment,
    ) {}

    public function envelope(): Envelope
    {
        $subject = match ($this->payment->type) {
            PaymentType::Deposit => "Confirm your stay at {$this->booking->cottage_name} — deposit due",
            PaymentType::Balance => "Your balance for {$this->booking->cottage_name} is due",
            PaymentType::Full => "Confirm your stay at {$this->booking->cottage_name} — payment due",
        };

        return new Envelope(
            subject: $subject,
            replyTo: array_filter([config('booking.support_email')]),
            // The reference in a header makes a support thread greppable without the
            // reference having to be quoted in the body.
            metadata: ['booking' => $this->booking->reference],
        );
    }

    public function content(): Content
    {
        $isBalance = $this->payment->type === PaymentType::Balance;

        return new Content(
            markdown: $isBalance ? 'mail.payment-balance' : 'mail.payment-deposit',
            with: [
                'booking' => $this->booking,
                'payment' => $this->payment,
                'payUrl' => $this->payment->payUrl(),
                'amount' => $this->payment->amount(),
                'expires' => $this->payment->link_expires_at,
                /*
                 * A stay inside `full_payment_within_days` is charged once, so the deposit
                 * template must not promise a balance mail that will never arrive.
                 */
                'isFullPayment' => $this->payment->type === PaymentType::Full,
                'daysToArrival' => $this->booking->arrival
                    ? (int) now()->startOfDay()->diffInDays($this->booking->arrival->startOfDay(), false)
                    : null,
            ],
        );
    }
}
