<?php

namespace App\Services\Payments;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Jobs\MarkLodgifyBookingBooked;
use App\Jobs\RecordLodgifyPayment;
use App\Mail\BookingConfirmed;
use App\Models\BookingPayment;
use App\Services\Booking\BookingAuditor;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Applies a settled Stripe payment to a booking.
 *
 * THIS IS THE MOST DANGEROUS CLASS IN THE FEATURE. By the time it runs, money has left
 * the guest's account. Three properties are therefore non-negotiable:
 *
 * 1. IDEMPOTENT. Stripe delivers webhooks at-least-once, and the same
 *    `checkout.session.completed` will arrive again. Settling twice must not double-count
 *    the payment, send two confirmation emails, or fire two Lodgify writes. Enforced by a
 *    row lock plus a status guard, not by hoping deliveries are unique.
 *
 * 2. AMOUNT-VERIFIED. What Stripe captured is compared against what we asked for BEFORE
 *    anything is confirmed. A mismatch confirms nothing and raises for a human — a
 *    session for the wrong amount means either we built it wrong or something tampered
 *    with it, and neither should produce a confirmed reservation.
 *
 * 3. NEVER LOSES A PAID BOOKING. The Lodgify write is queued with retries rather than
 *    attempted inline, so a Lodgify outage cannot turn a successful payment into a failed
 *    webhook. If it ultimately fails, a human is alerted; the guest's money and our
 *    record of it are safe either way.
 */
class PaymentSettler
{
    public function __construct(
        protected BookingAuditor $auditor,
    ) {}

    /**
     * Record a successful capture.
     *
     * @param  Money  $captured  what Stripe actually took, read from the session
     * @return bool true if THIS call settled the payment; false if it was already settled
     */
    public function settle(
        BookingPayment $payment,
        Money $captured,
        ?string $paymentIntentId = null,
        ?string $chargeId = null,
        ?string $customerId = null,
    ): bool {
        return DB::transaction(function () use ($payment, $captured, $paymentIntentId, $chargeId, $customerId) {
            /*
             * Re-read under a lock. Two concurrent webhook deliveries both arrive here;
             * the first takes the lock and settles, the second finds status = paid and
             * returns false. Without the lock both would pass the status check.
             */
            $locked = BookingPayment::query()
                ->whereKey($payment->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === PaymentStatus::Paid) {
                $this->auditor->record('payment.settle_ignored_already_paid', $locked->booking, $locked, [
                    'captured' => $captured->format(),
                ]);

                return false;
            }

            if ($locked->status === PaymentStatus::Refunded) {
                $this->auditor->recordFailure('payment.settle_after_refund', $locked->booking, $locked, [
                    'captured' => $captured->format(),
                ]);

                return false;
            }

            // ---- amount verification, before anything is confirmed --------------
            $expected = $locked->amount();

            if (! $captured->equals($expected)) {
                $locked->forceFill([
                    'amount_received_cents' => $captured->cents,
                    'status' => PaymentStatus::Failed,
                    'failed_at' => now(),
                    'failure_reason' => sprintf(
                        'Amount mismatch: captured %s, expected %s',
                        $captured->format(),
                        $expected->format()
                    ),
                ])->save();

                $this->auditor->recordFailure('payment.amount_mismatch', $locked->booking, $locked, [
                    'captured_cents' => $captured->cents,
                    'expected_cents' => $expected->cents,
                    'currency' => $captured->currency,
                ]);

                /*
                 * Deliberately NOT confirming the booking. The money is in Stripe and
                 * will need refunding or topping up by a person; confirming a reservation
                 * against the wrong amount is the worse outcome.
                 */
                return false;
            }

            $locked->forceFill([
                'status' => PaymentStatus::Paid,
                'paid_at' => now(),
                'amount_received_cents' => $captured->cents,
                'stripe_payment_intent_id' => $paymentIntentId ?? $locked->stripe_payment_intent_id,
                'stripe_charge_id' => $chargeId ?? $locked->stripe_charge_id,
                'stripe_customer_id' => $customerId ?? $locked->stripe_customer_id,
                'failure_reason' => null,
            ])->save();

            $this->auditor->record('payment.succeeded', $locked->booking, $locked, [
                'type' => $locked->type->value,
                'amount' => $captured->format(),
                'intent' => $paymentIntentId,
            ], actorType: 'stripe');

            $this->advanceBooking($locked);

            return true;
        });
    }

    /**
     * Mark an asynchronous payment as still in flight.
     *
     * Some methods complete the Checkout Session with `payment_status: unpaid` and settle
     * minutes or days later. Treating session completion as payment would confirm a
     * reservation against money that has not arrived, so those wait here for
     * `checkout.session.async_payment_succeeded`.
     */
    public function markProcessing(BookingPayment $payment): void
    {
        if ($payment->status->isSettled()) {
            return;
        }

        $payment->forceFill(['status' => PaymentStatus::Processing])->save();

        $this->auditor->record('payment.processing', $payment->booking, $payment, [], actorType: 'stripe');
    }

    public function markFailed(BookingPayment $payment, string $reason): void
    {
        if ($payment->status->isSettled()) {
            return;   // never walk a paid payment backwards
        }

        $payment->forceFill([
            'status' => PaymentStatus::Failed,
            'failed_at' => now(),
            'failure_reason' => mb_substr($reason, 0, 250),
        ])->save();

        $this->auditor->record('payment.failed', $payment->booking, $payment, [
            'reason' => $reason,
        ], actorType: 'stripe');
    }

    public function markExpired(BookingPayment $payment): void
    {
        if ($payment->status->isSettled()) {
            return;
        }

        $payment->forceFill([
            'status' => PaymentStatus::Expired,
            'expired_at' => now(),
        ])->save();

        $this->auditor->record('payment.expired', $payment->booking, $payment);
    }

    /**
     * Move the booking on, now that a payment has landed.
     *
     * Runs inside settle()'s transaction, so the booking status and the payment status
     * commit together — there is no window where the payment is paid but the booking has
     * not noticed.
     */
    protected function advanceBooking(BookingPayment $payment): void
    {
        $booking = $payment->booking->refresh();
        $from = $booking->status;

        $confirms = $payment->type->confirmsBooking();

        // Full payment, or a balance that completes an already-confirmed booking.
        $settlesEverything = $payment->type === PaymentType::Full
            || $payment->type === PaymentType::Balance
            || $booking->balance_cents === 0;

        $target = $settlesEverything ? BookingStatus::PaidInFull : BookingStatus::DepositPaid;

        if (! $from->canTransitionTo($target)) {
            /*
             * Reachable when webhooks arrive out of order — a balance event before the
             * deposit event, for instance. Recorded rather than forced: the state machine
             * refusing is information, and the reconciliation command can sort it out.
             */
            $this->auditor->recordFailure('booking.unexpected_transition', $booking, $payment, [
                'from' => $from->value,
                'target' => $target->value,
            ]);

            return;
        }

        $extra = $confirms && $booking->booked_at === null ? ['booked_at' => now()] : [];

        if (! $booking->transitionTo($target, $extra)) {
            // Somebody else advanced it first; their side effects have already run.
            return;
        }

        $this->auditor->recordTransition(
            $booking,
            'booking.advanced',
            $from->value,
            $target->value,
            ['on_payment' => $payment->type->value],
        );

        /*
         * QUEUED, NOT INLINE — and dispatched after commit so the job can never read a
         * booking row that has not been written yet.
         *
         * This is the "never lose a paid booking" property: a Lodgify outage becomes a
         * retrying job and an eventual alert, not a 500 back to Stripe.
         */
        if ($confirms) {
            MarkLodgifyBookingBooked::dispatch($booking->getKey())->afterCommit();
        }

        RecordLodgifyPayment::dispatch($payment->getKey())->afterCommit();

        DB::afterCommit(function () use ($booking, $payment) {
            $this->sendConfirmation($booking, $payment);
        });
    }

    /**
     * Confirmation email. Non-fatal by design: the payment is captured and the booking is
     * advanced, so a mail failure is an inconvenience, not a reason to fail a webhook and
     * have Stripe redeliver it.
     */
    protected function sendConfirmation($booking, BookingPayment $payment): void
    {
        try {
            Mail::to($booking->guest_email)->send(new BookingConfirmed($booking->fresh(), $payment));
        } catch (\Throwable $e) {
            $this->auditor->recordFailure('booking.confirmation_mail_failed', $booking, $payment, [
                'message' => $e->getMessage(),
            ]);
        }
    }
}
