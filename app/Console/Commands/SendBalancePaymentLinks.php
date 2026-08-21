<?php

namespace App\Console\Commands;

use App\Enums\BookingStatus;
use App\Enums\PaymentType;
use App\Models\Booking;
use App\Services\Payments\PaymentLinkService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Ask for the balance on confirmed bookings that are approaching arrival.
 *
 * IDEMPOTENT BY QUERY, not by bookkeeping. `scopeDueForBalanceLink` excludes any booking
 * that already has a balance payment row, so running this twice in the same hour — or two
 * overlapping runs on two servers — cannot send two links. That is why the schedule can
 * safely be hourly.
 */
class SendBalancePaymentLinks extends Command
{
    protected $signature = 'booking:send-balance-links
        {--lead= : Override booking.balance_lead_days}
        {--dry-run : List what would be sent without sending}';

    protected $description = 'Email balance payment links for bookings approaching arrival';

    public function handle(PaymentLinkService $links): int
    {
        if (! config('booking.direct_payments_enabled')) {
            $this->line('Direct payments are disabled; nothing to do.');

            return self::SUCCESS;
        }

        $lead = (int) ($this->option('lead') ?: config('booking.balance_lead_days', 30));
        $dry = (bool) $this->option('dry-run');

        $due = Booking::query()->dueForBalanceLink($lead)->with('payments')->get();

        if ($due->isEmpty()) {
            $this->line("No bookings need a balance link within {$lead} days.");

            return self::SUCCESS;
        }

        $this->line("{$due->count()} booking(s) due a balance link (lead {$lead} days):");

        $sent = 0;

        foreach ($due as $booking) {
            $amount = $booking->balanceAmount();

            $this->line(sprintf(
                '  %-10s %-26s arrives %s  balance %s',
                $booking->reference,
                Str::limit($booking->cottage_name, 24),
                $booking->arrival->toDateString(),
                $amount->format(),
            ));

            if ($dry) {
                continue;
            }

            /*
             * A zero balance means the deposit covered everything — mark it complete
             * rather than emailing a link for nothing.
             */
            if (! $amount->isPositive()) {
                $booking->transitionTo(BookingStatus::PaidInFull);

                continue;
            }

            try {
                $links->issue($booking, PaymentType::Balance, $amount);
                $booking->transitionTo(BookingStatus::AwaitingBalance);
                $sent++;
            } catch (\Throwable $e) {
                /*
                 * One failure must not stop the rest — the same reasoning as safe() in
                 * LodgifyRepository. Reported so it reaches error tracking.
                 */
                $this->error("  ! {$booking->reference}: {$e->getMessage()}");
                report($e);
            }
        }

        if ($dry) {
            $this->newLine();
            $this->warn('Dry run — nothing sent.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info("Queued {$sent} balance link(s).");

        return self::SUCCESS;
    }
}
