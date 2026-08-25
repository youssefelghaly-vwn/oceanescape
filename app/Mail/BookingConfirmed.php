<?php

namespace App\Mail;

use App\Models\Booking;
use App\Models\BookingPayment;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Sent when a payment settles. Doubles as the deposit receipt and the confirmation. */
class BookingConfirmed extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public Booking $booking,
        public BookingPayment $payment,
    ) {}

    public function envelope(): Envelope
    {
        $confirmed = $this->booking->status->holdsDates();

        return new Envelope(
            subject: $confirmed
                ? "You're booked — {$this->booking->cottage_name}, {$this->booking->stay_label}"
                : "Payment received — {$this->booking->cottage_name}",
            replyTo: array_filter([config('booking.support_email')]),
            metadata: ['booking' => $this->booking->reference],
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.booking-confirmed',
            with: [
                'booking' => $this->booking,
                'payment' => $this->payment,
                'paid' => $this->payment->amountReceived() ?? $this->payment->amount(),
                'outstanding' => $this->booking->amountOutstanding(),
                'balanceDue' => $this->booking->balance_cents > 0
                                 && ! $this->booking->status->isTerminal(),
            ],
        );
    }
}
