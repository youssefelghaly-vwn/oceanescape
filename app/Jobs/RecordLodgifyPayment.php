<?php

namespace App\Jobs;

use App\Models\BookingPayment;
use App\Services\Booking\BookingAuditor;
use App\Services\Lodgify\LodgifyBookingWriter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Report a settled payment back onto the Lodgify reservation, so `amount_paid` and
 * `amount_due` in the dashboard match reality.
 *
 * BEST EFFORT, AND THAT IS THE CORRECT SEVERITY. The money is captured and the reservation
 * is already confirmed; this only keeps Lodgify's own figures tidy. Unlike
 * MarkLodgifyBookingBooked, a permanent failure here does not put a guest's stay at risk,
 * so it does not page anybody — it records why and moves on.
 *
 * There is also no confirmed public endpoint for it (see config lodgify.write
 * .record_payment_path), so "not configured" is an expected outcome rather than an error.
 */
class RecordLodgifyPayment implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [30, 300];

    public function __construct(public int $paymentId) {}

    public function handle(LodgifyBookingWriter $writer, BookingAuditor $auditor): void
    {
        /** @var BookingPayment|null $payment */
        $payment = BookingPayment::query()->with('booking')->find($this->paymentId);

        if (! $payment || $payment->recorded_in_lodgify_at !== null) {
            return;   // gone, or already recorded
        }

        try {
            $recorded = $writer->recordPayment($payment);
        } catch (\Throwable $e) {
            $payment->forceFill([
                'lodgify_record_error' => mb_substr($e->getMessage(), 0, 1000),
            ])->save();

            $auditor->recordFailure('lodgify.record_payment.failed', $payment->booking, $payment, [
                'attempt' => $this->attempts(),
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }

        if (! $recorded) {
            /*
             * Not an error: either recording is switched off, or no endpoint is
             * configured. Written to the payment row so an admin can see why the Lodgify
             * dashboard shows an outstanding balance that has in fact been paid.
             */
            $payment->forceFill([
                'lodgify_record_error' => 'Not recorded: no Lodgify payment endpoint configured.',
            ])->save();

            return;
        }

        $payment->forceFill([
            'recorded_in_lodgify_at' => now(),
            'lodgify_record_error' => null,
        ])->save();

        $auditor->record('lodgify.record_payment.ok', $payment->booking, $payment, actorType: 'lodgify');
    }
}
