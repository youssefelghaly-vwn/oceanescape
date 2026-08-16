<?php

namespace App\DTO;

/**
 * Stable, frontend-safe representation of a Lodgify property.
 * Views only ever see this shape, never raw Lodgify JSON.
 */
class Cottage
{
    public function __construct(
        public readonly int $id,
        public readonly string $slug,
        public readonly string $name,
        public readonly ?string $description,
        public readonly ?string $shortDescription,

        // location
        public readonly ?string $addressLine,
        public readonly ?string $city,
        public readonly ?string $state,
        public readonly ?string $country,
        public readonly ?string $postalCode,
        public readonly ?float $latitude,
        public readonly ?float $longitude,

        // capacity
        public readonly int $bedrooms,
        public readonly int $bathrooms,
        public readonly int $maxGuests,
        public readonly ?string $propertyType,
        public readonly ?int $sizeSqm,

        // rules / policy
        public readonly bool $petFriendly,
        public readonly bool $smokingAllowed,
        public readonly bool $partiesAllowed,
        public readonly bool $childrenAllowed,
        public readonly ?string $checkInTime,
        public readonly ?string $checkOutTime,
        public readonly ?int $minStay,
        public readonly ?int $maxStay,
        /** @var string[] extra free-text rules */
        public readonly array $houseRules,

        // media
        public readonly ?string $heroImage,
        /** @var string[] */
        public readonly array $images,
        /**
         * Alt text keyed by image URL. Lodgify's V1 room endpoint supplies a
         * `text` label per photo, which is worth keeping for accessibility and
         * image SEO rather than falling back to the cottage name everywhere.
         *
         * @var array<string, string>
         */
        public readonly array $imageAlts,

        // rooms / rates
        /** @var array<int, array{id:int,name:string,maxGuests:int}> */
        public readonly array $rooms,
        public readonly ?float $baseNightlyPrice,
        public readonly ?string $currency,

        // amenities, grouped by category
        /** @var array<string, string[]> */
        public readonly array $amenities,
    ) {}

    /** The room/room-type id Lodgify needs for calendar + rate queries. */
    public function primaryRoomId(): ?int
    {
        return $this->rooms[0]['id'] ?? null;
    }

    public function detailUrl(): string
    {
        return route('cottage.show', ['slug' => $this->slug]);
    }

    public function locationLine(): string
    {
        return collect([$this->city, $this->state, $this->country])
            ->filter()->unique()->implode(', ');
    }

    public function hasCoordinates(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    public function mapEmbedUrl(): ?string
    {
        if (!$this->hasCoordinates()) {
            return null;
        }
        // OpenStreetMap embed — no API key required.
        $d = 0.01;
        $bbox = implode(',', [
            $this->longitude - $d, $this->latitude - $d,
            $this->longitude + $d, $this->latitude + $d,
        ]);
        return 'https://www.openstreetmap.org/export/embed.html?bbox=' . $bbox
             . '&layer=mapnik&marker=' . $this->latitude . ',' . $this->longitude;
    }

    public function directionsUrl(): ?string
    {
        if ($this->hasCoordinates()) {
            return "https://www.google.com/maps/dir/?api=1&destination={$this->latitude},{$this->longitude}";
        }
        $q = collect([$this->addressLine, $this->city, $this->state, $this->country])
            ->filter()->implode(', ');
        return $q === '' ? null : 'https://www.google.com/maps/search/?api=1&query=' . urlencode($q);
    }

    public function amenityCount(): int
    {
        return collect($this->amenities)->flatten()->count();
    }

    /**
     * A larger variant of a Lodgify CDN image, for the lightbox.
     *
     * Lodgify hands back `?f=32`, which is a thumbnail — fine in a grid cell,
     * unusable full-screen. `lodgify.image_size_large` replaces the preset.
     */
    public function largeImage(string $url): string
    {
        $size = config('lodgify.image_size_large');
        if ($size === null || $size === '' || !str_contains($url, 'icdbcdn')) {
            return $url;
        }
        if (preg_match('/[?&]f=/', $url)) {
            return preg_replace('/([?&])f=[^&]*/', '$1f=' . rawurlencode((string) $size), $url) ?? $url;
        }
        return $url . (str_contains($url, '?') ? '&' : '?') . 'f=' . rawurlencode((string) $size);
    }

    /**
     * Payload for the lightbox: every image with its large variant and caption.
     *
     * @return array<int, array{thumb:string,full:string,alt:string}>
     */
    public function galleryPayload(): array
    {
        return collect($this->images)->values()->map(fn (string $url, int $i) => [
            'thumb' => $url,
            'full'  => $this->largeImage($url),
            'alt'   => $this->altFor($url, $i),
        ])->all();
    }

    /** Alt text for an image, falling back to a sensible description. */
    public function altFor(string $url, int $index = 0): string
    {
        $alt = $this->imageAlts[$url] ?? null;
        if ($alt !== null && trim($alt) !== '') {
            return $alt;
        }
        return $index === 0
            ? $this->name
            : $this->name . ' — photo ' . ($index + 1);
    }
}