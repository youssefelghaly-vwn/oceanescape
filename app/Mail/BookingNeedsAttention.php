<?php

namespace App\Mail;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * INTERNAL alert: a guest has paid and Lodgify does not know.
 *
 * Sent to config('booking.alert_email') when MarkLodgifyBookingBooked exhausts its
 * retries. This is the one failure in the feature that genuinely needs a person, because
 * the nights the guest paid for are still on sale in Lodgify.
 *
 * Never sent to a guest.
 */
class BookingNeedsAttention extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public Booking $booking,
        public string $reason,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "ACTION NEEDED: paid booking {$this->booking->reference} is not confirmed in Lodgify",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.booking-needs-attention',
            with: [
                'booking' => $this->booking,
                'reason' => $this->reason,
                'paid' => $this->booking->amountPaid(),
            ],
        );
    }
}
