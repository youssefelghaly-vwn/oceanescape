<?php

namespace App\Jobs;

use App\Exceptions\LodgifyWriteFailed;
use App\Mail\BookingNeedsAttention;
use App\Models\Booking;
use App\Services\Booking\BookingAuditor;
use App\Services\Lodgify\LodgifyBookingWriter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Flip a paid reservation to `Booked` in Lodgify, which is what actually blocks the dates.
 *
 * THE ONE JOB THAT MUST NOT SILENTLY GIVE UP.
 *
 * It only ever runs after money has been captured. If it never succeeds, the guest has
 * paid for nights that Lodgify still believes are for sale — so the failure path ends in a
 * human being emailed, not in a swallowed exception.
 *
 * IDEMPOTENT: guarded on the booking not already being marked booked, under a row lock, so
 * a retry or a duplicate dispatch cannot double-write.
 */
class MarkLodgifyBookingBooked implements ShouldQueue
{
    use Queueable;

    /**
     * Six attempts over roughly half an hour. Long enough to ride out a Lodgify blip or a
     * brief outage, short enough that a genuine failure reaches a person the same day.
     */
    public int $tries = 6;

    public array $backoff = [10, 30, 120, 300, 900];

    /** Stop retrying after an hour regardless of attempts. */
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHour();
    }

    public function __construct(public int $bookingId) {}

    /**
     * Serialised by id rather than by model so a retry always reads current state — a
     * serialised model would carry a stale status from before the last attempt.
     */
    public function handle(
        LodgifyBookingWriter $writer,
        BookingAuditor $auditor,
    ): void {
        /** @var Booking|null $booking */
        $booking = Booking::query()->find($this->bookingId);

        if (! $booking) {
            return;   // deleted between dispatch and run; nothing to do
        }

        // Already done — a duplicate dispatch or a successful earlier attempt.
        if ($booking->lodgify_status === (string) config('lodgify.write.status_booked', 'Booked')) {
            return;
        }

        if (blank($booking->lodgify_booking_id)) {
            /*
             * Should be unreachable: settlement only happens on a booking that has a
             * reservation. If it does happen, retrying will not fix it — fail permanently
             * so it reaches the alert path rather than looping.
             */
            $auditor->recordFailure('lodgify.mark_booked.no_reservation', $booking);
            $this->fail(new LodgifyWriteFailed(
                "Booking {$booking->reference} settled but carries no Lodgify id.",
                operation: 'markBooked',
                moneyAtRisk: true,
            ));

            return;
        }

        try {
            $writer->markBooked($booking);
        } catch (LodgifyWriteFailed $e) {
            DB::table('bookings')->where('id', $booking->getKey())->update([
                'lodgify_sync_attempts' => $booking->lodgify_sync_attempts + 1,
                'lodgify_sync_error' => mb_substr($e->getMessage(), 0, 1000),
                'updated_at' => now(),
            ]);

            $auditor->recordFailure('lodgify.mark_booked.failed', $booking, context: [
                'attempt' => $this->attempts(),
                'status' => $e->status,
                'response' => $e->responseExcerpt,
            ]);

            throw $e;   // let the queue retry with backoff
        }

        $booking->forceFill([
            'lodgify_status' => (string) config('lodgify.write.status_booked', 'Booked'),
            'lodgify_sync_error' => null,
            'booked_at' => $booking->booked_at ?? now(),
        ])->save();

        $auditor->record('lodgify.mark_booked.ok', $booking, context: [
            'lodgify_booking_id' => $booking->lodgify_booking_id,
            'attempt' => $this->attempts(),
        ], actorType: 'lodgify');
    }

    /**
     * Every retry is spent and the reservation is still not confirmed.
     *
     * This is the alert that matters: the guest has paid and the Lodgify calendar does not
     * reflect it, so those nights can still be sold to somebody else. A person has to
     * open the dashboard and fix it.
     */
    public function failed(\Throwable $e): void
    {
        $booking = Booking::query()->find($this->bookingId);

        if (! $booking) {
            return;
        }

        $booking->forceFill([
            'lodgify_sync_error' => 'PAID BUT NOT CONFIRMED IN LODGIFY: '.mb_substr($e->getMessage(), 0, 900),
        ])->save();

        app(BookingAuditor::class)->recordFailure('lodgify.mark_booked.exhausted', $booking, context: [
            'message' => $e->getMessage(),
            'note' => 'Guest has paid. Reservation is still Open in Lodgify. Dates NOT held.',
        ]);

        $alertTo = config('booking.alert_email');

        if (blank($alertTo)) {
            /*
             * No alert address configured. Log at critical so it at least trips whatever
             * log-based alerting exists, and say plainly what is wrong.
             */
            Log::channel('booking')->critical(
                'PAID BOOKING NOT CONFIRMED IN LODGIFY and BOOKING_ALERT_EMAIL is unset.',
                ['booking' => $booking->reference, 'lodgify_booking_id' => $booking->lodgify_booking_id]
            );

            return;
        }

        try {
            Mail::to($alertTo)->send(new BookingNeedsAttention($booking, $e->getMessage()));
        } catch (\Throwable $mailFailure) {
            Log::channel('booking')->critical(
                'Could not send the paid-but-unconfirmed alert.',
                [
                    'booking' => $booking->reference,
                    'original' => $e->getMessage(),
                    'mail_error' => $mailFailure->getMessage(),
                ]
            );
        }
    }
}
