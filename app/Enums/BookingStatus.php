<?php

namespace App\Enums;

/**
 * Lifecycle of a direct booking taken on our own site.
 *
 * THE STATE MACHINE
 *
 *   pending_lodgify ──► awaiting_deposit ──► deposit_paid ──► awaiting_balance ──► paid_in_full
 *          │                    │                 │                  │
 *          ▼                    ▼                  └──────────────────┴──► cancelled
 *        failed              expired
 *
 * WHAT EACH STATE MEANS FOR THE LODGIFY CALENDAR — this is the part that matters:
 *
 *   pending_lodgify   No reservation exists yet. Dates NOT held.
 *   awaiting_deposit  Reservation exists as `Open`. Dates NOT HELD — an Open
 *                     reservation does not block the Lodgify calendar, so these nights
 *                     can still be sold to somebody else. This is why the deposit link
 *                     expires (config booking.deposit_link_ttl_hours) and why `expired`
 *                     exists as a state rather than leaving the row to rot.
 *   deposit_paid      Reservation flipped to `Booked`. Dates ARE held.
 *   awaiting_balance  Still `Booked`. Dates held.
 *   paid_in_full      Still `Booked`. Nothing outstanding.
 *   expired           Deposit went unpaid past its window; the Open reservation is
 *                     released so the nights go back on sale.
 *   failed            The Lodgify write failed permanently. No money was taken — the
 *                     guest is asked to call us. Needs a human.
 */
enum BookingStatus: string
{
    case PendingLodgify = 'pending_lodgify';
    case AwaitingDeposit = 'awaiting_deposit';
    case DepositPaid = 'deposit_paid';
    case AwaitingBalance = 'awaiting_balance';
    case PaidInFull = 'paid_in_full';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::PendingLodgify => 'Creating reservation',
            self::AwaitingDeposit => 'Awaiting deposit',
            self::DepositPaid => 'Deposit paid',
            self::AwaitingBalance => 'Awaiting balance',
            self::PaidInFull => 'Paid in full',
            self::Expired => 'Expired unpaid',
            self::Cancelled => 'Cancelled',
            self::Failed => 'Failed',
        };
    }

    /** Tailwind classes for the status pill, matching the other admin enums. */
    public function classes(): string
    {
        return match ($this) {
            self::PendingLodgify => 'bg-fog-100 text-tide-600 ring-fog-300',
            self::AwaitingDeposit => 'bg-amber-50 text-amber-800 ring-amber-200',
            self::DepositPaid => 'bg-sky-50 text-sky-800 ring-sky-200',
            self::AwaitingBalance => 'bg-sky-50 text-sky-800 ring-sky-200',
            self::PaidInFull => 'bg-emerald-50 text-emerald-800 ring-emerald-200',
            self::Expired => 'bg-fog-100 text-tide-600 ring-fog-300',
            self::Cancelled => 'bg-fog-100 text-tide-600 ring-fog-300',
            self::Failed => 'bg-rose-50 text-rose-800 ring-rose-200',
        };
    }

    /**
     * Legal transitions.
     *
     * Declared as data rather than scattered `if` statements so that an illegal
     * transition is a single guarded failure (Booking::transitionTo) instead of a
     * silent overwrite. Replaying a webhook must not walk a paid booking backwards.
     */
    public function canTransitionTo(self $next): bool
    {
        if ($this === $next) {
            return true;    // idempotent re-assertion of the current state is allowed
        }

        return in_array($next, $this->allowedNext(), true);
    }

    /** @return array<int, self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::PendingLodgify => [self::AwaitingDeposit, self::Failed, self::Cancelled],
            // Straight to PaidInFull covers a single full payment on a late booking.
            self::AwaitingDeposit => [self::DepositPaid, self::PaidInFull, self::Expired, self::Cancelled],
            self::DepositPaid => [self::AwaitingBalance, self::PaidInFull, self::Cancelled],
            self::AwaitingBalance => [self::PaidInFull, self::Cancelled],
            // Terminal.
            self::PaidInFull, self::Expired, self::Cancelled, self::Failed => [],
        };
    }

    public function isTerminal(): bool
    {
        return $this->allowedNext() === [];
    }

    /** Has the reservation been confirmed in Lodgify (and the dates therefore held)? */
    public function holdsDates(): bool
    {
        return in_array($this, [self::DepositPaid, self::AwaitingBalance, self::PaidInFull], true);
    }

    /** Is money still owed? */
    public function hasOutstandingBalance(): bool
    {
        return in_array($this, [self::AwaitingDeposit, self::DepositPaid, self::AwaitingBalance], true);
    }

    /** Needs someone to look at it. */
    public function needsAttention(): bool
    {
        return $this === self::Failed;
    }

    /** @return array<string, string> value => label, for selects */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $s) => [$s->value => $s->label()])
            ->all();
    }
}
