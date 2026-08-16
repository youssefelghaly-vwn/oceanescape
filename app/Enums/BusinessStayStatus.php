<?php

namespace App\Enums;

/**
 * Lifecycle of a corporate enquiry.
 *
 * Deliberately a small set: every extra status is one more decision for
 * whoever is triaging, and these five cover the real path from arrival to
 * outcome.
 */
enum BusinessStayStatus: string
{
    case New       = 'new';
    case Contacted = 'contacted';
    case Quoted    = 'quoted';
    case Won       = 'won';
    case Lost      = 'lost';

    public function label(): string
    {
        return match ($this) {
            self::New       => 'New',
            self::Contacted => 'Contacted',
            self::Quoted    => 'Quoted',
            self::Won       => 'Confirmed',
            self::Lost      => 'Closed',
        };
    }

    /** Tailwind classes for the status pill. */
    public function classes(): string
    {
        return match ($this) {
            self::New       => 'bg-brand-50 text-brand-700 ring-brand-200',
            self::Contacted => 'bg-amber-50 text-amber-800 ring-amber-200',
            self::Quoted    => 'bg-sky-50 text-sky-800 ring-sky-200',
            self::Won       => 'bg-emerald-50 text-emerald-800 ring-emerald-200',
            self::Lost      => 'bg-fog-100 text-tide-600 ring-fog-300',
        };
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::New, self::Contacted, self::Quoted], true);
    }

    /** @return array<string, string> value => label, for selects */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $s) => [$s->value => $s->label()])
            ->all();
    }
}