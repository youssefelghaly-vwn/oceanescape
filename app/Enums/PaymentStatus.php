<?php

namespace App\Enums;

/**
 * Lifecycle of one Stripe Checkout Session.
 *
 *   pending ──► link_sent ──► processing ──► paid ──► refunded
 *                   │              │
 *                   ▼              ▼
 *                expired        failed
 *
 * `processing` exists because some payment methods settle asynchronously: Stripe fires
 * `checkout.session.completed` with `payment_status: unpaid` and confirms later via
 * `checkout.session.async_payment_succeeded`. Treating completion as payment would
 * confirm a booking against money that has not arrived, so those land in `processing`
 * and only the async success event moves them to `paid`.
 */
enum PaymentStatus: string
{
    case Pending = 'pending';
    case LinkSent = 'link_sent';
    case Processing = 'processing';
    case Paid = 'paid';
    case Failed = 'failed';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Not yet sent',
            self::LinkSent => 'Link sent',
            self::Processing => 'Payment processing',
            self::Paid => 'Paid',
            self::Failed => 'Failed',
            self::Expired => 'Link expired',
            self::Cancelled => 'Cancelled',
            self::Refunded => 'Refunded',
        };
    }

    public function classes(): string
    {
        return match ($this) {
            self::Pending => 'bg-fog-100 text-tide-600 ring-fog-300',
            self::LinkSent => 'bg-amber-50 text-amber-800 ring-amber-200',
            self::Processing => 'bg-sky-50 text-sky-800 ring-sky-200',
            self::Paid => 'bg-emerald-50 text-emerald-800 ring-emerald-200',
            self::Failed => 'bg-rose-50 text-rose-800 ring-rose-200',
            self::Expired => 'bg-fog-100 text-tide-600 ring-fog-300',
            self::Cancelled => 'bg-fog-100 text-tide-600 ring-fog-300',
            self::Refunded => 'bg-fog-100 text-tide-600 ring-fog-300',
        };
    }

    /** Can a guest still pay against this? */
    public function isPayable(): bool
    {
        return in_array($this, [self::Pending, self::LinkSent], true);
    }

    public function isSettled(): bool
    {
        return in_array($this, [self::Paid, self::Refunded], true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Paid, self::Expired, self::Cancelled, self::Refunded], true);
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $s) => [$s->value => $s->label()])->all();
    }
}
