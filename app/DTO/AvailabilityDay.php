<?php

namespace App\DTO;

/**
 * A single day's availability, aggregated across all cottages
 * (or scoped to one when computed per-property).
 */
class AvailabilityDay
{
    public function __construct(
        public readonly string $date,          // YYYY-MM-DD
        public readonly int $totalCottages,    // how many exist
        public readonly int $availableCount,   // how many are free that day
        public readonly int $minStay,          // minimum stay across free cottages
        public readonly bool $checkInAllowed,  // any cottage allows check-in
        public readonly bool $checkOutAllowed, // any cottage allows check-out
        public readonly ?float $lowestPrice = null,
        public readonly ?string $currency = null,
    ) {}

    public function isFullyBooked(): bool
    {
        return $this->availableCount === 0;
    }

    public function isLimited(int $threshold = 2): bool
    {
        return $this->availableCount > 0 && $this->availableCount <= $threshold;
    }

    public function isFullyAvailable(): bool
    {
        return $this->availableCount === $this->totalCottages;
    }

    /** Frontend-friendly shape for JSON payloads */
    public function toArray(): array
    {
        return [
            'date'             => $this->date,
            'available'        => $this->availableCount,
            'total'            => $this->totalCottages,
            'min_stay'         => $this->minStay,
            'check_in'         => $this->checkInAllowed,
            'check_out'        => $this->checkOutAllowed,
            'is_booked'        => $this->isFullyBooked(),
            'is_limited'       => $this->isLimited((int) config('lodgify.limited_threshold', 2)),
            'is_fully_free'    => $this->isFullyAvailable(),
            'lowest_price'     => $this->lowestPrice,
            'currency'         => $this->currency,
        ];
    }
}
