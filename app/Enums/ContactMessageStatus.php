<?php

namespace App\Enums;

enum ContactMessageStatus: string
{
    case New      = 'new';
    case Read     = 'read';
    case Replied  = 'replied';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::New      => 'New',
            self::Read     => 'Read',
            self::Replied  => 'Replied',
            self::Archived => 'Archived',
        };
    }

    public function classes(): string
    {
        return match ($this) {
            self::New      => 'bg-brand-50 text-brand-700 ring-brand-200',
            self::Read     => 'bg-amber-50 text-amber-800 ring-amber-200',
            self::Replied  => 'bg-emerald-50 text-emerald-800 ring-emerald-200',
            self::Archived => 'bg-fog-100 text-tide-600 ring-fog-300',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $s) => [$s->value => $s->label()])->all();
    }
}
