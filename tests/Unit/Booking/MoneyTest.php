<?php

namespace Tests\Unit\Booking;

use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    #[Test]
    public function it_rounds_floats_instead_of_truncating_them(): void
    {
        /*
         * The whole reason this class exists. (int) (19.99 * 100) is 1998 because 19.99 is
         * not exactly representable — a one-cent undercharge on every such price.
         */
        $this->assertSame(1998, (int) (19.99 * 100), 'guard: naive cast still truncates');
        $this->assertSame(1999, Money::fromFloat(19.99, 'CAD')->cents);
        $this->assertSame(600, Money::fromFloat(6.0, 'CAD')->cents);
        $this->assertSame(49020, Money::fromFloat(490.2, 'CAD')->cents);
    }

    #[Test]
    public function it_normalises_currency_case(): void
    {
        $this->assertSame('CAD', Money::fromFloat(10, 'cad')->currency);
        $this->assertSame('CAD', Money::fromCents(1000, ' cad ')->currency);
    }

    #[Test]
    public function it_rejects_zero_decimal_currencies(): void
    {
        // Charging 100 JPY as 10000 minor units would be a 100x overcharge.
        $this->expectException(InvalidArgumentException::class);
        Money::fromFloat(100, 'JPY');
    }

    #[Test]
    public function it_rejects_negative_amounts(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Money(-1, 'CAD');
    }

    #[Test]
    public function it_refuses_to_mix_currencies(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::fromCents(100, 'CAD')->plus(Money::fromCents(100, 'USD'));
    }

    #[Test]
    public function it_refuses_subtraction_that_would_go_negative(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::fromCents(100, 'CAD')->minus(Money::fromCents(101, 'CAD'));
    }

    #[Test]
    public function deposit_and_balance_reconcile_exactly(): void
    {
        // Deliberately an amount whose 25% does not divide cleanly.
        $total = Money::fromCents(49999, 'CAD');
        $deposit = $total->percent(25);
        $balance = $total->minus($deposit);

        $this->assertSame(12500, $deposit->cents);
        $this->assertTrue($deposit->plus($balance)->equals($total));
    }
}
