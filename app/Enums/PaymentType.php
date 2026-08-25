<?php

namespace App\Enums;

enum PaymentType: string
{
    /** First instalment, per Lodgify's own payment schedule. Flips Open -> Booked. */
    case Deposit = 'deposit';

    /** Remainder, requested config('booking.balance_lead_days') before arrival. */
    case Balance = 'balance';

    /**
     * The whole amount in one payment. Used when a booking is made so close to
     * arrival that a deposit-then-balance split would put both links days apart —
     * see config('booking.full_payment_within_days').
     */
    case Full = 'full';

    public function label(): string
    {
        return match ($this) {
            self::Deposit => 'Deposit',
            self::Balance => 'Balance',
            self::Full => 'Full payment',
        };
    }

    /** Does settling this payment confirm the reservation in Lodgify? */
    public function confirmsBooking(): bool
    {
        return match ($this) {
            self::Deposit, self::Full => true,
            self::Balance => config('booking.mark_booked_on') === 'balance',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $t) => [$t->value => $t->label()])->all();
    }
}
