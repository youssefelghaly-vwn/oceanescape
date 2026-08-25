<?php

namespace App\Services\Booking;

use App\Enums\PaymentType;
use App\Support\Money;

/**
 * What we are going to ask a guest for, and when.
 *
 * Derived exclusively from a Lodgify quote by DepositPolicy. Immutable, so once it has
 * been validated against the quote it cannot be edited before the Stripe session is
 * built from it.
 */
final readonly class PaymentPlan
{
    /**
     * @param  array<int, array<string, mixed>>  $schedule  Lodgify's raw schedule, kept
     *                                                      verbatim for the audit record
     */
    public function __construct(
        public Money $total,
        public Money $deposit,
        public Money $balance,
        public bool $singlePayment,
        public array $schedule,
        public string $source,
    ) {}

    /** The payment we ask for first. */
    public function firstPaymentType(): PaymentType
    {
        return $this->singlePayment ? PaymentType::Full : PaymentType::Deposit;
    }

    public function firstPaymentAmount(): Money
    {
        return $this->singlePayment ? $this->total : $this->deposit;
    }

    /**
     * Sanity check that the parts sum to the whole.
     *
     * Called before any Stripe session is created. Integer cents make this exact, which
     * is the entire reason money is stored in cents — the equivalent float assertion
     * would fail spuriously.
     */
    public function isConsistent(): bool
    {
        if ($this->singlePayment) {
            return $this->balance->isZero() && $this->deposit->equals($this->total);
        }

        return $this->deposit->plus($this->balance)->equals($this->total);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'total_cents' => $this->total->cents,
            'deposit_cents' => $this->deposit->cents,
            'balance_cents' => $this->balance->cents,
            'currency' => $this->total->currency,
            'single_payment' => $this->singlePayment,
            'source' => $this->source,
            'schedule' => $this->schedule,
        ];
    }
}
