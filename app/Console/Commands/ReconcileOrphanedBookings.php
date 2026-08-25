<?php

namespace App\Console\Commands;

use App\DTO\Reservation;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Services\Booking\BookingAuditor;
use App\Services\Lodgify\ReservationRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Match up bookings that lost their Lodgify reservation id.
 *
 * WHY THIS EXISTS
 * There is one failure mode where a reservation can exist in Lodgify with nothing in our
 * database pointing at it: the create request succeeds, and then something goes wrong
 * interpreting the response. That is not hypothetical — `createBooking()` was typed
 * `: array` while the endpoint answers with a bare integer id, so the TypeError fired
 * after Lodgify had already created the reservation.
 *
 * The code now fails loudly and logs at critical in that situation, but "loudly" does not
 * un-orphan anything. This command is the recovery path: it reads reservations back out of
 * Lodgify (the read side has always worked) and matches them to our stranded rows on
 * property + dates, confirming with the guest email where Lodgify has one.
 *
 * Read-only unless --link is passed.
 */
class ReconcileOrphanedBookings extends Command
{
    protected $signature = 'booking:reconcile-orphans
        {--link : Attach the matches found. Without this the command only reports}
        {--days=400 : How far back to consider stranded bookings}';

    protected $description = 'Find Lodgify reservations for bookings that lost their id, and optionally relink them';

    public function handle(ReservationRepository $reservations, BookingAuditor $auditor): int
    {
        $stranded = Booking::query()
            ->whereNull('lodgify_booking_id')
            ->whereIn('status', [BookingStatus::PendingLodgify->value, BookingStatus::Failed->value])
            ->where('created_at', '>=', now()->subDays((int) $this->option('days')))
            ->orderBy('created_at')
            ->get();

        if ($stranded->isEmpty()) {
            $this->info('No stranded bookings. Nothing to reconcile.');

            return self::SUCCESS;
        }

        $this->line("{$stranded->count()} booking(s) with no Lodgify id:");
        $this->newLine();

        // One fresh read of the reservation feed, reused for every candidate.
        try {
            $all = $reservations->all(fresh: true);
        } catch (\Throwable $e) {
            $this->error('Could not read reservations from Lodgify: '.$e->getMessage());

            return self::FAILURE;
        }

        $linked = 0;
        $ambiguous = 0;

        foreach ($stranded as $booking) {
            $candidates = $all->filter(fn (Reservation $r) => (int) $r->propertyId === (int) $booking->cottage_id
                && $r->arrival?->toDateString() === $booking->arrival->toDateString()
                && $r->departure?->toDateString() === $booking->departure->toDateString());

            /*
             * Prefer an email match when Lodgify has one. Lodgify allows reservations with
             * no email (phone bookings, some channels), so absence is not a mismatch — but
             * a present-and-different email means this is somebody else's reservation and
             * must never be claimed.
             */
            $byEmail = $candidates->filter(fn (Reservation $r) => filled($r->guestEmail)
                && strtolower((string) $r->guestEmail) === strtolower((string) $booking->guest_email));

            $match = $byEmail->count() === 1
                ? $byEmail->first()
                : ($candidates->count() === 1 ? $candidates->first() : null);

            $this->line(sprintf(
                '  %-10s %-22s %s → %s',
                $booking->reference,
                Str::limit($booking->cottage_name, 20),
                $booking->arrival->toDateString(),
                $booking->departure->toDateString(),
            ));

            if ($match === null) {
                if ($candidates->isEmpty()) {
                    $this->line('      no Lodgify reservation matches — nothing was created');
                } else {
                    $ambiguous++;
                    $this->warn("      {$candidates->count()} reservations match these dates; too ambiguous to link automatically");
                    foreach ($candidates as $c) {
                        $this->line("        candidate #{$c->id} · ".($c->guestEmail ?: 'no email').' · '.$c->status);
                    }
                }

                continue;
            }

            $this->info("      matches Lodgify #{$match->id} ({$match->status}, ".($match->guestEmail ?: 'no email').')');

            if (! $this->option('link')) {
                continue;
            }

            $booking->forceFill([
                'lodgify_booking_id' => (string) $match->id,
                'lodgify_status' => $match->status,
                'lodgify_created_at' => $match->createdAt ?? now(),
                'lodgify_sync_error' => null,
            ])->save();

            /*
             * Only advance a booking that was still waiting on the reservation. A `failed`
             * booking is left as-is: somebody decided it failed, and quietly reviving it
             * behind their back would be worse than making them look.
             */
            if ($booking->status === BookingStatus::PendingLodgify) {
                $booking->transitionTo(BookingStatus::AwaitingDeposit);
                $this->line('      relinked and moved to awaiting_deposit');
            } else {
                $this->line('      relinked; status left as '.$booking->status->value.' for a human to confirm');
            }

            $auditor->record('lodgify.reconciled', $booking, context: [
                'lodgify_booking_id' => (string) $match->id,
                'matched_on' => $byEmail->count() === 1 ? 'property+dates+email' : 'property+dates',
            ], actorType: 'admin');

            $linked++;
        }

        $this->newLine();

        if (! $this->option('link')) {
            $this->warn('Report only. Re-run with --link to attach the matches above.');

            return self::SUCCESS;
        }

        $this->info("Relinked {$linked} booking(s).");

        if ($ambiguous > 0) {
            $this->warn("{$ambiguous} needed a human — resolve those in the Lodgify dashboard.");
        }

        return self::SUCCESS;
    }
}
