<?php

namespace Tests\Unit\Booking;

use App\Exceptions\PaymentScheduleUnavailable;
use App\Services\Booking\DepositPolicy;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DepositPolicyTest extends TestCase
{
    private function policy(): DepositPolicy
    {
        return new DepositPolicy;
    }

    private function quote(array $overrides = []): array
    {
        return array_merge([
            'currency' => 'CAD',
            'total' => 1000.0,
            'schedule' => [
                ['name' => 'On agreement', 'amount' => 250.0, 'is_current' => true],
                ['name' => 'Before arrival', 'amount' => 750.0, 'is_current' => false],
            ],
        ], $overrides);
    }

    #[Test]
    public function it_takes_the_deposit_from_lodgifys_schedule(): void
    {
        $plan = $this->policy()->planFor($this->quote(), Carbon::today()->addDays(90));

        $this->assertSame(100000, $plan->total->cents);
        $this->assertSame(25000, $plan->deposit->cents);
        $this->assertSame(75000, $plan->balance->cents);
        $this->assertSame('lodgify_schedule', $plan->source);
        $this->assertTrue($plan->isConsistent());
    }

    #[Test]
    public function it_prefers_the_instalment_lodgify_marks_current(): void
    {
        // First row is NOT current; the policy must not just take row zero.
        $plan = $this->policy()->planFor($this->quote([
            'schedule' => [
                ['name' => 'Second', 'amount' => 800.0, 'is_current' => false],
                ['name' => 'Due now', 'amount' => 200.0, 'is_current' => true],
            ],
        ]), Carbon::today()->addDays(90));

        $this->assertSame(20000, $plan->deposit->cents);
    }

    #[Test]
    public function it_refuses_to_guess_when_lodgify_sends_no_schedule(): void
    {
        /*
         * The behaviour that was explicitly chosen: refuse rather than invent an amount
         * Lodgify never sanctioned.
         */
        config()->set('booking.deposit.allow_percentage_fallback', false);

        $this->expectException(PaymentScheduleUnavailable::class);

        $this->policy()->planFor($this->quote(['schedule' => []]), Carbon::today()->addDays(90));
    }

    #[Test]
    public function it_refuses_when_the_schedule_has_no_positive_amount(): void
    {
        config()->set('booking.deposit.allow_percentage_fallback', false);

        $this->expectException(PaymentScheduleUnavailable::class);

        $this->policy()->planFor($this->quote([
            'schedule' => [['name' => 'Nothing', 'amount' => 0.0]],
        ]), Carbon::today()->addDays(90));
    }

    #[Test]
    public function it_refuses_a_quote_with_no_total(): void
    {
        $this->expectException(PaymentScheduleUnavailable::class);

        $this->policy()->planFor($this->quote(['total' => null]), Carbon::today()->addDays(90));
    }

    #[Test]
    public function it_ignores_a_schedule_instalment_larger_than_the_total(): void
    {
        // Misread schedule. Must never over-charge — falls through to "no usable amount".
        config()->set('booking.deposit.allow_percentage_fallback', false);

        $this->expectException(PaymentScheduleUnavailable::class);

        $this->policy()->planFor($this->quote([
            'total' => 100.0,
            'schedule' => [['name' => 'Bad', 'amount' => 5000.0, 'is_current' => true]],
        ]), Carbon::today()->addDays(90));
    }

    #[Test]
    public function it_asks_for_the_full_amount_on_an_imminent_stay(): void
    {
        config()->set('booking.full_payment_within_days', 14);

        $plan = $this->policy()->planFor($this->quote(), Carbon::today()->addDays(5));

        $this->assertTrue($plan->singlePayment);
        $this->assertSame(100000, $plan->deposit->cents);
        $this->assertTrue($plan->balance->isZero());
        $this->assertTrue($plan->isConsistent());
    }

    #[Test]
    public function a_pay_in_full_schedule_becomes_a_single_payment(): void
    {
        // Lodgify schedules can say "the whole thing, now". That is not a deposit, and the
        // guest must not later get a balance link for zero.
        $plan = $this->policy()->planFor($this->quote([
            'schedule' => [['name' => 'On booking', 'amount' => 1000.0, 'is_current' => true]],
        ]), Carbon::today()->addDays(90));

        $this->assertTrue($plan->singlePayment);
        $this->assertTrue($plan->balance->isZero());
    }

    #[Test]
    public function the_percentage_fallback_only_applies_when_explicitly_enabled(): void
    {
        config()->set('booking.deposit.allow_percentage_fallback', true);
        config()->set('booking.deposit.fallback_percent', 25.0);

        $plan = $this->policy()->planFor($this->quote(['schedule' => []]), Carbon::today()->addDays(90));

        $this->assertSame('percentage_fallback', $plan->source);
        $this->assertSame(25000, $plan->deposit->cents);
    }
}
