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
 * The email carrying a payment link — deposit, balance, or full.
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
        return new Content(
            markdown: 'mail.payment-link',
            with: [
                'booking' => $this->booking,
                'payment' => $this->payment,
                'payUrl' => $this->payment->payUrl(),
                'amount' => $this->payment->amount(),
                'expires' => $this->payment->link_expires_at,
                'isFinal' => $this->payment->type !== PaymentType::Deposit,
            ],
        );
    }
}
