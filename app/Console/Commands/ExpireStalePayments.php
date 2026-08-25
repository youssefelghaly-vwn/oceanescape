<?php

namespace App\Console\Commands;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\BookingPayment;
use App\Services\Booking\BookingAuditor;
use App\Services\Lodgify\LodgifyBookingWriter;
use App\Services\Payments\StripeGateway;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Release bookings whose deposit was never paid, and tidy up their Stripe sessions.
 *
 * WHY THIS MATTERS MORE IN THIS FLOW THAN IT LOOKS
 *
 * A booking sits `Open` in Lodgify from creation until the deposit lands, and an `Open`
 * reservation does NOT block the calendar — so the nights are still sellable, but our
 * records claim someone has them, and the guest may believe they have a booking. Leaving
 * those to accumulate means an admin cannot tell a live booking from an abandoned one.
 *
 * Releasing does two things: expires the Stripe session so a stale link cannot be paid
 * weeks later at a price we no longer honour, and deletes the Open reservation in Lodgify
 * so the dashboard is clean.
 */
class ExpireStalePayments extends Command
{
    protected $signature = 'booking:expire-stale
        {--dry-run : Report what would be expired without changing anything}';

    protected $description = 'Expire unpaid payment links and release their Open reservations';

    public function handle(
        StripeGateway $stripe,
        LodgifyBookingWriter $writer,
        BookingAuditor $auditor,
    ): int {
        $dry = (bool) $this->option('dry-run');

        // ---- 1. lapsed payment links ------------------------------------------
        $lapsed = BookingPayment::query()->lapsed()->with('booking')->get();

        $this->line("{$lapsed->count()} lapsed payment link(s).");

        foreach ($lapsed as $payment) {
            $this->line(sprintf(
                '  %-10s %-8s expired %s',
                $payment->reference,
                $payment->type->value,
                $payment->link_expires_at?->diffForHumans() ?? '—',
            ));

            if ($dry) {
                continue;
            }

            if (filled($payment->stripe_checkout_session_id)) {
                $stripe->expireSession($payment->stripe_checkout_session_id);
            }

            $payment->forceFill([
                'status' => PaymentStatus::Expired,
                'expired_at' => now(),
            ])->save();

            $auditor->record('payment.expired_by_sweeper', $payment->booking, $payment);
        }

        // ---- 2. release Open reservations that were never paid for -------------
        $stale = Booking::query()->staleAwaitingDeposit()->get();

        $this->newLine();
        $this->line("{$stale->count()} unpaid reservation(s) to release.");

        $released = 0;

        foreach ($stale as $booking) {
            $this->line(sprintf(
                '  %-10s %-24s arrives %s',
                $booking->reference,
                Str::limit($booking->cottage_name, 22),
                $booking->arrival->toDateString(),
            ));

            if ($dry) {
                continue;
            }

            /*
             * Guard against the race that matters: a guest paying in the seconds between
             * the query above and this line. Re-read and skip anything that is no longer
             * awaiting a deposit — releasing a reservation somebody just paid for would be
             * the worst bug in this feature.
             */
            $fresh = $booking->fresh();

            if (! $fresh || $fresh->status !== BookingStatus::AwaitingDeposit) {
                $this->line('    (skipped — status changed while sweeping)');

                continue;
            }

            $writer->release($fresh, 'deposit not paid within the link window');

            $fresh->transitionTo(BookingStatus::Expired, ['cancelled_at' => now()]);

            $auditor->recordTransition(
                $fresh,
                'booking.expired_unpaid',
                BookingStatus::AwaitingDeposit->value,
                BookingStatus::Expired->value,
            );

            $released++;
        }

        $this->newLine();

        if ($dry) {
            $this->warn('Dry run — nothing changed.');

            return self::SUCCESS;
        }

        $this->info("Released {$released} unpaid reservation(s).");

        return self::SUCCESS;
    }
}
