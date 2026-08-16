<?php

namespace App\Enums;

enum GuestPhotoStatus: string
{
    case Pending  = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending  => 'Awaiting review',
            self::Approved => 'Published',
            self::Rejected => 'Rejected',
        };
    }

    public function classes(): string
    {
        return match ($this) {
            self::Pending  => 'bg-amber-50 text-amber-800 ring-amber-200',
            self::Approved => 'bg-emerald-50 text-emerald-800 ring-emerald-200',
            self::Rejected => 'bg-fog-100 text-tide-600 ring-fog-300',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $s) => [$s->value => $s->label()])->all();
    }
}
