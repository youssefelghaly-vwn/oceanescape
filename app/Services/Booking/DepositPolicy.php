<?php

namespace App\Services\Booking;

use App\Exceptions\PaymentScheduleUnavailable;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Decides how much a guest pays now and how much later.
 *
 * THE RULE: LODGIFY IS THE AUTHORITY ON WHAT IS OWED.
 *
 * The deposit comes from `scheduled_payments` on Lodgify's own /v2/quote response — the
 * same block RateController::parseV2Quote() already surfaces to the booking panel as
 * `schedule` and `due_now`. We do not compute a percentage ourselves, because the moment
 * two systems disagree about what a guest owes, the guest believes whichever one they saw
 * last and somebody has to refund the difference.
 *
 * If Lodgify returns no usable schedule we THROW rather than guess. A booking that cannot
 * be priced authoritatively is a booking we decline to take money for. That behaviour is
 * configured, visibly, at config('booking.deposit.source') — and the percentage fallback
 * is off by default precisely so that turning it on is a recorded decision.
 *
 * ONE EXCEPTION, and it is about guest experience rather than money: a stay starting
 * inside config('booking.full_payment_within_days') is charged in a single payment,
 * because sending a deposit link and a balance link four days apart is worse for
 * everybody than one link for the total. The total is unchanged either way.
 */
class DepositPolicy
{
    /**
     * @param  array<string, mixed>  $quote  a parsed quote as produced by
     *                                       RateController::parseV2Quote()/parsePublicQuote()
     */
    public function planFor(array $quote, Carbon $arrival): PaymentPlan
    {
        $currency = strtoupper((string) ($quote['currency'] ?? 'CAD'));
        Money::assertSupported($currency);

        $total = $this->totalFrom($quote, $currency);

        if (! $total->isPositive()) {
            throw new PaymentScheduleUnavailable(
                'Lodgify quote carries no positive total; refusing to create a payment.'
            );
        }

        // --- single payment for imminent stays ---------------------------------
        $fullPaymentWithin = (int) config('booking.full_payment_within_days', 14);

        if (Carbon::today()->diffInDays($arrival, false) <= $fullPaymentWithin) {
            return new PaymentPlan(
                total: $total,
                deposit: $total,
                balance: Money::zero($currency),
                singlePayment: true,
                schedule: $this->rawSchedule($quote),
                source: 'full_payment_window',
            );
        }

        // --- deposit from Lodgify's schedule -----------------------------------
        $deposit = $this->depositFromSchedule($quote, $currency, $total);

        if ($deposit === null) {
            $deposit = $this->fallbackDeposit($total);   // throws unless explicitly enabled
            $source = 'percentage_fallback';
        } else {
            $source = 'lodgify_schedule';
        }

        /*
         * A "deposit" of the entire total is not a deposit — Lodgify schedules can say
         * "pay in full on booking". Treat it as a single payment so the guest is not
         * later sent a balance link for zero.
         */
        if ($deposit->cents >= $total->cents) {
            return new PaymentPlan(
                total: $total,
                deposit: $total,
                balance: Money::zero($currency),
                singlePayment: true,
                schedule: $this->rawSchedule($quote),
                source: $source.'_full',
            );
        }

        $plan = new PaymentPlan(
            total: $total,
            deposit: $deposit,
            balance: $total->minus($deposit),
            singlePayment: false,
            schedule: $this->rawSchedule($quote),
            source: $source,
        );

        /*
         * Belt and braces. If deposit + balance ever failed to equal the total we would
         * be charging the guest the wrong amount, so refuse rather than proceed. With
         * integer cents this is exact and should be unreachable — which is why it is
         * worth asserting.
         */
        if (! $plan->isConsistent()) {
            throw new PaymentScheduleUnavailable(sprintf(
                'Payment plan does not reconcile: %d + %d != %d (%s)',
                $plan->deposit->cents, $plan->balance->cents, $plan->total->cents, $currency
            ));
        }

        return $plan;
    }

    /**
     * The authoritative total.
     *
     * `total` is what parseV2Quote() derives from Lodgify's `total_including_vat`. We do
     * NOT recompute it from rental + fees + taxes: that sum is presentational and can
     * disagree with the figure Lodgify would actually charge.
     */
    protected function totalFrom(array $quote, string $currency): Money
    {
        $total = $quote['total'] ?? null;

        if (! is_numeric($total)) {
            throw new PaymentScheduleUnavailable(
                'Lodgify quote has no numeric total; refusing to create a payment.'
            );
        }

        return Money::fromFloat($total, $currency);
    }

    /**
     * Read the first instalment out of Lodgify's payment schedule.
     *
     * Two shapes are handled, matching the two quote parsers already in RateController:
     *
     *   v2      scheduled_payments[] -> [{ name: "On agreement", amount: 300.0,
     *                                      status: ..., is_current: true }, ...]
     *   public  scheduledPayments.payments[] -> normalised to the same keys upstream
     *
     * Prefers the entry Lodgify marks `is_current`; otherwise takes the first positive
     * amount, which is the instalment due now. Returns null when nothing usable is
     * present — the caller decides what that means.
     */
    protected function depositFromSchedule(array $quote, string $currency, Money $total): ?Money
    {
        $schedule = $this->rawSchedule($quote);

        if ($schedule === []) {
            return null;
        }

        $current = collect($schedule)->first(fn ($row) => (bool) ($row['is_current'] ?? false));

        $chosen = $current ?? collect($schedule)
            ->first(fn ($row) => is_numeric($row['amount'] ?? null) && (float) $row['amount'] > 0);

        if ($chosen === null || ! is_numeric($chosen['amount'] ?? null)) {
            return null;
        }

        $deposit = Money::fromFloat($chosen['amount'], $currency);

        if (! $deposit->isPositive()) {
            return null;
        }

        /*
         * Never ask for more than the total. A schedule that exceeds the total means we
         * have misread it, and over-charging is not a failure mode we accept.
         */
        if ($deposit->cents > $total->cents) {
            Log::channel('booking')->warning('Lodgify schedule instalment exceeds quote total', [
                'instalment_cents' => $deposit->cents,
                'total_cents' => $total->cents,
                'currency' => $currency,
            ]);

            return null;
        }

        return $deposit;
    }

    /**
     * Percentage fallback — DISABLED by default, and that is the point.
     *
     * config('booking.deposit.allow_percentage_fallback') is false unless somebody has
     * explicitly accepted the risk of charging an amount Lodgify never sanctioned.
     */
    protected function fallbackDeposit(Money $total): Money
    {
        if (! config('booking.deposit.allow_percentage_fallback', false)) {
            throw new PaymentScheduleUnavailable(
                'Lodgify returned no usable payment schedule, and the percentage fallback is '
                .'disabled (booking.deposit.allow_percentage_fallback). Refusing to guess a '
                .'deposit amount.'
            );
        }

        $percent = (float) config('booking.deposit.fallback_percent', 25.0);

        Log::channel('booking')->warning(
            'Falling back to a computed deposit percentage; Lodgify supplied no schedule.',
            ['percent' => $percent, 'total_cents' => $total->cents]
        );

        return $total->percent($percent);
    }

    /**
     * Lodgify's schedule rows, normalised to a list of arrays.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function rawSchedule(array $quote): array
    {
        $schedule = $quote['schedule'] ?? $quote['scheduled_payments'] ?? [];

        if (! is_array($schedule)) {
            return [];
        }

        return array_values(array_filter($schedule, 'is_array'));
    }
}
