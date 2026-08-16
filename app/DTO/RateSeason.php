<?php

namespace App\DTO;

use Illuminate\Support\Carbon;

/**
 * A seasonal rate period as configured in the Lodgify dashboard.
 * Drives the "Seasonal rates" table on the cottage page.
 */
class RateSeason
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $start,      // YYYY-MM-DD, null = open-ended
        public readonly ?string $end,
        public readonly ?float $nightly,
        public readonly ?float $weekly,
        public readonly ?float $monthly,
        public readonly ?int $minStay,
        public readonly ?string $currency,
        public readonly bool $isDefault = false,
    ) {}

    public function dateRangeLabel(): string
    {
        if ($this->isDefault || (!$this->start && !$this->end)) {
            return 'All other dates';
        }
        $s = $this->start ? Carbon::parse($this->start) : null;
        $e = $this->end   ? Carbon::parse($this->end)   : null;

        if ($s && $e) {
            $sameYear = $s->year === $e->year;
            return $s->format('M j, Y') . ' – ' . $e->format($sameYear ? 'M j, Y' : 'M j, Y');
        }
        if ($s) return 'From ' . $s->format('M j, Y');
        if ($e) return 'Until ' . $e->format('M j, Y');
        return 'All other dates';
    }

    public function isCurrent(): bool
    {
        if (!$this->start || !$this->end) {
            return false;
        }
        return Carbon::today()->betweenIncluded(
            Carbon::parse($this->start),
            Carbon::parse($this->end)
        );
    }

    public function toArray(): array
    {
        return [
            'name'       => $this->name,
            'start'      => $this->start,
            'end'        => $this->end,
            'nightly'    => $this->nightly,
            'weekly'     => $this->weekly,
            'monthly'    => $this->monthly,
            'min_stay'   => $this->minStay,
            'currency'   => $this->currency,
            'is_default' => $this->isDefault,
        ];
    }
}