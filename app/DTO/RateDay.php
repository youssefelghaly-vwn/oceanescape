<?php

namespace App\DTO;

/**
 * One calendar day for a single cottage: price + availability + stay rules.
 * Drives the price-annotated calendar and client-side rule enforcement.
 */
class RateDay
{
    public function __construct(
        public readonly string $date,            // YYYY-MM-DD
        public readonly ?float $price,           // nightly rate, null when unknown
        public readonly ?string $currency,
        public readonly bool $available,
        public readonly bool $checkInAllowed,
        public readonly bool $checkOutAllowed,
        public readonly int $minStay,
        public readonly ?int $maxStay,
        public readonly ?string $seasonName,
        public readonly bool $isDefaultRate = false,
        public readonly ?float $pricePerAdditionalGuest = null,
        public readonly ?int $additionalGuestsStartFrom = null,
    ) {}

    public function toArray(): array
    {
        return [
            'date'       => $this->date,
            'price'      => $this->price,
            'currency'   => $this->currency,
            'available'  => $this->available,
            'check_in'   => $this->checkInAllowed,
            'check_out'  => $this->checkOutAllowed,
            'min_stay'   => $this->minStay,
            'max_stay'   => $this->maxStay,
            'season'     => $this->seasonName,
            'is_default' => $this->isDefaultRate,
            'extra_guest_price' => $this->pricePerAdditionalGuest,
            'extra_guest_from'  => $this->additionalGuestsStartFrom,
        ];
    }
}