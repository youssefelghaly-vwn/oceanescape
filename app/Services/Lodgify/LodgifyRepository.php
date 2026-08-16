<?php

namespace App\Services\Lodgify;

use App\DTO\AvailabilityDay;
use App\DTO\Cottage;
use App\DTO\RateDay;
use App\DTO\RateSeason;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Repository between LodgifyClient and the rest of the app.
 *
 * Two hard rules learned the hard way:
 *
 * 1. ONLY PRIMITIVES GO IN THE CACHE. DTOs are built on read from cached
 *    arrays, so changing a DTO signature can never poison the cache with
 *    __PHP_Incomplete_Class.
 *
 * 2. ONE FAILING COTTAGE MUST NOT TAKE DOWN THE WHOLE CALENDAR. Every
 *    per-cottage fetch is wrapped in safe() so a 403/500/timeout on one
 *    property degrades that property only. Failures are logged and surfaced
 *    in the response payload rather than thrown.
 */
class LodgifyRepository
{
    /**
     * Bump this whenever the cached SHAPE changes, to invalidate old entries
     * without needing a manual cache:clear on deploy.
     */
    protected const CACHE_VERSION = 'v3';

    /** @var array<int, string> collected non-fatal errors from the last operation */
    protected array $lastErrors = [];

    /**
     * The most recent guest-facing rejection from Lodgify, e.g.
     * "The minimum stay for this rental is 6 days".
     *
     * Kept separate from $lastErrors, which is diagnostics. This is copy we are
     * willing to show a customer.
     */
    protected ?string $lastGuestMessage = null;

    public function __construct(
        protected LodgifyClient $client,
        protected PropertyImageResolver $imageResolver,
    ) {}

    /** Non-fatal errors from the most recent call, for surfacing in debug output. */
    public function lastErrors(): array
    {
        return $this->lastErrors;
    }

    /** Lodgify's own explanation for the last rejection, if it is fit to show. */
    public function lastGuestMessage(): ?string
    {
        return $this->lastGuestMessage;
    }

    // =========================================================================
    // Cottages
    // =========================================================================

    /** @return Collection<int, Cottage> */
    public function allCottages(): Collection
    {
        $rawList = $this->rememberArray(
            'properties:all:raw',
            (int) config('lodgify.cache.properties_list'),
            fn () => $this->client->listProperties()
        );

        /*
         * Map each cottage EXACTLY ONCE, from the merged payload.
         *
         * This used to map twice — first from the thin list payload, then again
         * from the merged detail. That was a real bug: mapCottage() runs the
         * image resolver, which caches its result, so the first pass wrote
         * "1 image" (the list only carries `image_url`) into the cache and the
         * second pass got a cache hit instead of the full gallery.
         *
         * The list payload is now only used for ids, and as a fallback if the
         * detail/room fetch for a given cottage fails.
         */
        return collect($rawList ?? [])
            ->map(function ($listEntry) {
                if (!is_array($listEntry)) {
                    return null;
                }
                $id = (int) ($listEntry['id'] ?? 0);
                if ($id === 0) {
                    return $this->mapCottage($listEntry);
                }
                $merged = config('lodgify.hydrate_property_details', true)
                    ? $this->cottageRaw($id)
                    : null;

                return $this->mapCottage($merged ?: $listEntry);
            })
            ->filter()
            ->values();
    }

    public function cottage(int $id): ?Cottage
    {
        $raw = $this->cottageRaw($id);
        return $raw ? $this->mapCottage($raw) : null;
    }

    /**
     * The property payload MERGED with its room-type payload.
     *
     * Neither endpoint alone is sufficient:
     *   /v2/properties/{id}          address, lat/lng, currency, room ids,
     *                                and a single `image_url` cover
     *   /v1/properties/{id}/rooms/{rid}  the FULL image gallery (with alt text),
     *                                amenities, max_people, per-room facts
     *
     * Room fields win where they are populated, but never for identity
     * (`id`, `slug`) and never when the room reports 0 for a count the property
     * already knows.
     *
     * @return array<string, mixed>|null
     */
    protected function cottageRaw(int $id): ?array
    {
        $property = $this->rememberArray(
            "property:{$id}:raw",
            (int) config('lodgify.cache.property_detail'),
            fn () => $this->safe("getProperty:{$id}", fn () => $this->client->getProperty($id))
        );

        if (!$property) {
            return null;
        }

        if (!config('lodgify.merge_room_data', true)) {
            return $property;
        }

        $roomId = $property['rooms'][0]['id'] ?? null;
        if (!$roomId) {
            return $property;
        }

        $room = $this->rememberArray(
            "room:{$id}:{$roomId}:raw",
            (int) config('lodgify.cache.property_detail'),
            fn () => $this->safe(
                "getRoomInfo:{$id}/{$roomId}",
                fn () => $this->client->getRoomInfo($id, $roomId)
            )
        );

        return $room ? $this->mergePropertyAndRoom($property, $room) : $property;
    }

    /**
     * @param array<string,mixed> $property
     * @param array<string,mixed> $room
     * @return array<string,mixed>
     */
    protected function mergePropertyAndRoom(array $property, array $room): array
    {
        $merged = $property;

        // Room wins outright for these — the property endpoint either omits
        // them or carries a thinner version.
        foreach ([
            'images', 'amenities', 'max_people', 'units', 'has_wifi',
            'has_parking', 'adults_only', 'breakfast_included', 'has_meal_plan',
            'area', 'area_unit',
            'min_price', 'original_min_price', 'max_price', 'original_max_price',
        ] as $key) {
            if (array_key_exists($key, $room) && $room[$key] !== null && $room[$key] !== []) {
                $merged[$key] = $room[$key];
            }
        }

        // Counts: only override when the room actually reports a non-zero.
        foreach (['bedrooms', 'bathrooms'] as $key) {
            if (!empty($room[$key])) {
                $merged[$key] = $room[$key];
            }
        }

        // Description: prefer whichever is longer — they are often both set but
        // the room copy tends to be the marketing text.
        $roomDesc = is_string($room['description'] ?? null) ? trim($room['description']) : '';
        $propDesc = is_string($property['description'] ?? null) ? trim($property['description']) : '';
        if ($roomDesc !== '' && mb_strlen($roomDesc) >= mb_strlen($propDesc)) {
            $merged['description'] = $roomDesc;
        }

        // Pets: allowed if EITHER level says so.
        $merged['pets_allowed'] = !empty($property['pets_allowed']) || !empty($room['pets_allowed']);

        // Keep the room id available for downstream calls.
        $merged['_room_id'] = $room['id'] ?? null;

        return $merged;
    }

    public function cottageBySlug(string $slug): ?Cottage
    {
        return $this->allCottages()->firstWhere('slug', $slug);
    }

    // =========================================================================
    // Availability
    // =========================================================================

    /**
     * Per-cottage day-by-day availability, keyed by YYYY-MM-DD.
     *
     * Strategy: try the AUTHENTICATED v2 endpoint first (documented, not
     * Cloudflare-guarded). If that fails, fall back to the public checkout
     * calendar. If both fail, return an empty array — the caller treats
     * missing data as "unknown", not as "booked".
     *
     * @return array<string, array<string, mixed>>
     */
    public function cottageAvailability(Cottage $cottage, string $startDate, ?string $endDate = null): array
    {
        $endDate ??= Carbon::parse($startDate)
            ->addDays((int) config('lodgify.availability_window_days', 90))
            ->toDateString();

        $key = "avail:{$cottage->id}:{$startDate}:{$endDate}";

        $rows = $this->rememberArray(
            $key,
            (int) config('lodgify.cache.availability'),
            function () use ($cottage, $startDate, $endDate) {
                // 1. authenticated (preferred)
                if (config('lodgify.prefer_authenticated_availability', true)) {
                    $auth = $this->safe(
                        "getAvailability:{$cottage->id}",
                        fn () => $this->client->getAvailability($cottage->id, $startDate, $endDate)
                    );
                    if (!empty($auth)) {
                        return $auth;
                    }
                }

                // 2. public checkout fallback
                $public = $this->safe(
                    "getPublicCalendar:{$cottage->id}",
                    fn () => $this->client->getPublicCalendar(
                        $cottage->id,
                        $startDate,
                        $cottage->primaryRoomId()
                    )
                );

                return $public ?? [];
            }
        );

        return collect($rows ?? [])->keyBy('date')->all();
    }

    /**
     * Aggregated availability across ALL cottages.
     *
     * @return Collection<string, AvailabilityDay>
     */
    public function aggregateAvailability(string $startDate): Collection
    {
        $key = "avail:aggregate:{$startDate}:raw";

        $agg = $this->rememberArray(
            $key,
            (int) config('lodgify.cache.availability'),
            function () use ($startDate) {
                $cottages = $this->allCottages();
                $total = $cottages->count();
                if ($total === 0) {
                    return ['total' => 0, 'days' => [], 'errors' => ['no cottages returned by Lodgify']];
                }

                $days   = [];
                $errors = [];
                $succeeded = 0;

                foreach ($cottages as $cottage) {
                    $calendar = $this->cottageAvailability($cottage, $startDate);
                    if (empty($calendar)) {
                        $errors[] = "no availability data for cottage {$cottage->id} ({$cottage->name})";
                        continue;
                    }
                    $succeeded++;
                    foreach ($calendar as $date => $day) {
                        $days[$date] ??= ['available' => 0, 'min_stay' => 999, 'ci' => false, 'co' => false];
                        if (!empty($day['isAvailable'])) {
                            $days[$date]['available']++;
                            $ms = (int) ($day['minimalStay'] ?? 1);
                            if ($ms > 0 && $ms < $days[$date]['min_stay']) {
                                $days[$date]['min_stay'] = $ms;
                            }
                        }
                        if (!empty($day['isCheckInAvailable']))  $days[$date]['ci'] = true;
                        if (!empty($day['isCheckOutAvailable'])) $days[$date]['co'] = true;
                    }
                }

                // IMPORTANT: only count cottages we actually got data for, so a
                // failed fetch doesn't make every day look "fully booked".
                return [
                    'total'  => $succeeded,
                    'days'   => $days,
                    'errors' => $errors,
                ];
            }
        );

        $this->lastErrors = $agg['errors'] ?? [];
        $total = (int) ($agg['total'] ?? 0);

        return collect($agg['days'] ?? [])->map(fn ($v, $date) => new AvailabilityDay(
            date: $date,
            totalCottages: $total,
            availableCount: (int) $v['available'],
            minStay: ($v['min_stay'] ?? 999) === 999 ? 1 : (int) $v['min_stay'],
            checkInAllowed: (bool) ($v['ci'] ?? false),
            checkOutAllowed: (bool) ($v['co'] ?? false),
        ));
    }

    /** @return Collection<int, Cottage> */
    public function cottagesFreeFor(string $arrival, string $departure): Collection
    {
        $cottages  = $this->allCottages();
        $startDate = min($arrival, Carbon::today()->toDateString());

        return $cottages->filter(function (Cottage $cottage) use ($arrival, $departure, $startDate) {
            $days = $this->cottageAvailability($cottage, $startDate);
            if (empty($days)) {
                return false; // no data -> don't claim it's bookable
            }
            $cursor = Carbon::parse($arrival);
            $end    = Carbon::parse($departure);
            while ($cursor->lt($end)) {
                $day = $days[$cursor->toDateString()] ?? null;
                if (!$day || empty($day['isAvailable'])) {
                    return false;
                }
                $cursor->addDay();
            }
            return true;
        })->values();
    }

    /**
     * @return Collection<int, array{cottage:Cottage,arrival:string,departure:string,offset_days:int}>
     */
    public function nearbyMatches(string $arrival, string $departure, int $window = 14): Collection
    {
        $nights = Carbon::parse($arrival)->diffInDays(Carbon::parse($departure));
        if ($nights < 1) {
            return collect();
        }

        $matches = collect();
        for ($offset = 1; $offset <= $window; $offset++) {
            foreach ([-$offset, $offset] as $delta) {
                $newArrival   = Carbon::parse($arrival)->addDays($delta);
                $newDeparture = $newArrival->copy()->addDays($nights);
                if ($newArrival->isPast()) {
                    continue;
                }

                foreach ($this->cottagesFreeFor($newArrival->toDateString(), $newDeparture->toDateString()) as $cottage) {
                    $matches->push([
                        'cottage'     => $cottage,
                        'arrival'     => $newArrival->toDateString(),
                        'departure'   => $newDeparture->toDateString(),
                        'offset_days' => abs($delta),
                    ]);
                }
            }
        }

        return $matches->groupBy(fn ($m) => $m['cottage']->id)
            ->map(fn ($group) => $group->sortBy('offset_days')->first())
            ->sortBy('offset_days')
            ->values();
    }

    // =========================================================================
    // Pricing
    // =========================================================================

    /**
     * A priced quote for a stay.
     *
     * Prefers the AUTHENTICATED endpoint, whose payload is richer than the
     * public one: it itemises fees and taxes separately, carries add-ons, the
     * payment schedule, the security deposit and the cancellation-policy text.
     *
     * `_source` records which endpoint answered, because the two have entirely
     * different shapes and the caller has to parse accordingly.
     *
     * @param array<int, string|int> $addOnIds
     */
    public function quote(
        int $cottageId,
        string $arrival,
        string $departure,
        int $adults = 2,
        int $children = 0,
        int $pets = 0,
        array $addOnIds = [],
    ): ?array {
        $addonKey = $addOnIds === [] ? '' : ':' . implode('-', $addOnIds);
        $key = "quote:v2:{$cottageId}:{$arrival}:{$departure}:{$adults}:{$children}:{$pets}{$addonKey}";

        return $this->rememberArray(
            $key,
            (int) config('lodgify.cache.quote'),
            function () use ($cottageId, $arrival, $departure, $adults, $children, $pets, $addOnIds) {
                $cottage  = $this->cottage($cottageId);
                $currency = $cottage?->currency ?? 'USD';

                $authQuote = $this->safe(
                    "getQuote:{$cottageId}",
                    fn () => $this->client->getQuote(
                        $cottageId, $arrival, $departure, $adults, $children, $pets,
                        $cottage?->primaryRoomId(),
                        $addOnIds,
                    )
                );
                if (!empty($authQuote)) {
                    $authQuote['_source'] = 'v2';
                    return $authQuote;
                }

                $public = $this->safe(
                    "getPublicCheckoutPrice:{$cottageId}",
                    fn () => $this->client->getPublicCheckoutPrice(
                        $cottageId, $arrival, $departure, $adults + $children, $currency
                    )
                );
                if (!empty($public)) {
                    $public['_source'] = 'public';
                    return $public;
                }

                return null;
            }
        );
    }

    // =========================================================================
    // Add-ons
    // =========================================================================

    /**
     * Optional extras a guest can add to a booking (cleaning, cot, late
     * checkout, transfers...).
     *
     * SHAPE NOT YET VERIFIED against a populated account, so the mapper accepts
     * a range of plausible field names and logs the raw keys once when it finds
     * something it does not recognise. Check /debug/lodgify/raw/addons/{id}.
     *
     * @return array<int, array<string, mixed>>
     */
    /**
     * Add-ons for a cottage.
     *
     * Sources are tried in the order configured by `lodgify.addon_strategies`:
     *
     *   api       rates.lodgify.com — currently returns 401/403 for every
     *             credential we can send, because it is locked to a dashboard
     *             session. Kept in the chain so it starts working the moment a
     *             public endpoint exists.
     *   manifest  config/lodgify-addons.php — mirrored by hand.
     *
     * @return array<int, array<string, mixed>>
     */
    public function addons(Cottage $cottage): array
    {
        foreach ((array) config('lodgify.addon_strategies', ['api', 'manifest']) as $strategy) {
            $addons = match ($strategy) {
                'api'      => $this->addonsFromApi($cottage),
                'manifest' => $this->addonsFromManifest($cottage),
                default    => [],
            };
            if ($addons !== []) {
                return $addons;
            }
        }
        return [];
    }

    /**
     * Add-ons mirrored in config/lodgify-addons.php.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function addonsFromManifest(Cottage $cottage): array
    {
        $entries = (array) (config("lodgify-addons.{$cottage->id}") ?? []);
        if ($entries === []) {
            return [];
        }

        return collect($entries)
            ->filter(fn ($e) => is_array($e) && !empty($e['name']))
            ->map(function (array $e) use ($cottage) {
                [$perNight, $perGuest] = $this->addonChargeScaling($e);
                $maxQty = (int) ($e['max_quantity'] ?? 0);

                return [
                    'id'           => (string) ($e['id'] ?? Str::slug($e['name'])),
                    'name'         => trim((string) $e['name']),
                    'description'  => isset($e['description']) ? trim((string) $e['description']) : null,
                    'price'        => (float) ($e['price'] ?? 0),
                    'currency'     => $e['currency'] ?? $cottage->currency ?? 'USD',
                    'per_night'    => $perNight,
                    'per_guest'    => $perGuest,
                    'charge_type'  => $e['charge_type'] ?? null,
                    'required'     => (bool) ($e['required'] ?? false),
                    'max_quantity' => $maxQty > 0 ? $maxQty : 10,
                    'image'        => isset($e['image']) && is_string($e['image'])
                                        ? (str_starts_with($e['image'], 'http') || str_starts_with($e['image'], '//')
                                            ? $this->normaliseImageUrl($e['image'])
                                            : asset(ltrim($e['image'], '/')))
                                        : null,
                    'source'       => 'manifest',
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function addonsFromApi(Cottage $cottage): array
    {
        $raw = $this->rememberArray(
            "addons:v2:{$cottage->id}",
            (int) config('lodgify.cache.property_detail'),
            fn () => $this->safe(
                "getAddons:{$cottage->id}",
                fn () => $this->client->getAddons($cottage->id)
            )
        );

        if (empty($raw) || !is_array($raw)) {
            return [];
        }

        // The v1 endpoint returns a bare list; tolerate an envelope too.
        $items = $raw;
        if (!array_is_list($raw)) {
            foreach (['addOns', 'addons', 'items', 'data', 'results'] as $key) {
                if (isset($raw[$key]) && is_array($raw[$key])) {
                    $items = $raw[$key];
                    break;
                }
            }
        }

        return collect($items)
            ->filter(fn ($i) => is_array($i))
            ->filter(fn (array $i) => $this->addonIsActive($i))
            ->map(fn (array $i) => $this->mapAddon($i, $cottage))
            ->filter()
            ->values()
            ->all();
    }

    protected function addonIsActive(array $raw): bool
    {
        $status = $raw['status'] ?? null;
        if (is_string($status)) {
            return strcasecmp($status, 'active') === 0;
        }
        if (array_key_exists('is_active', $raw)) {
            return (bool) $raw['is_active'];
        }
        return true;   // no status field: assume offered
    }

    /**
     * Map one add-on record.
     *
     * VERIFIED SHAPE (rates.lodgify.com/api/v2/rates/addons/property/{id}):
     *   {
     *     "id": 155523, "status": "Active", "chargeType": "PerStay",
     *     "value": 10.00, "maxQuantity": null,
     *     "image": { "url": "//l.icdbcdn.com/oh/....png?f=32" },
     *     "translations": { "en": { "name": "...", "description": "..." } }
     *   }
     *
     * @return array<string, mixed>|null
     */
    protected function mapAddon(array $raw, Cottage $cottage): ?array
    {
        // v1 gives a flat `name`; the dashboard shape nests under translations.
        $name = $this->firstString($raw, ['name', 'title', 'label']);
        $description = $this->firstString($raw, ['description', 'details', 'info']);

        if ($name === null) {
            [$name, $description] = $this->addonTranslation($raw);
        }
        if ($name === null) {
            Log::info('Lodgify add-on skipped: no name', ['keys' => array_keys($raw)]);
            return null;
        }

        /*
         * PRICE: `original_amount` is the figure the owner configured.
         * `amount` is converted at the account forex rate (we measured a
         * consistent 0.8645 factor), so displaying it would undercharge.
         * Same trap as min_price vs original_min_price on properties.
         */
        $price = $this->firstFloat($raw, [
            'original_amount', 'value', 'price', 'amount',
        ]) ?? 0.0;

        [$perNight, $perGuest] = $this->addonChargeScaling($raw);

        $maxQty = $raw['max_quantity'] ?? $raw['maxQuantity'] ?? $raw['max'] ?? null;
        $maxQty = is_numeric($maxQty) && (int) $maxQty > 0 ? (int) $maxQty : 10;

        $imageUrl = $raw['image_url'] ?? $raw['image']['url'] ?? $raw['imageUrl'] ?? null;

        // "Fixed" vs a percentage-of-stay charge.
        $rateType   = (string) ($raw['rate_type'] ?? $raw['rateType'] ?? 'Fixed');
        $percentage = $this->firstFloat($raw, ['percentage']);

        $currency = $raw['currency']['code']
            ?? $raw['currency_code']
            ?? (is_string($raw['currency'] ?? null) ? $raw['currency'] : null)
            ?? $cottage->currency
            ?? 'USD';

        return [
            'id'           => (string) ($raw['id'] ?? $raw['addon_id'] ?? Str::slug($name)),
            'name'         => trim($name),
            'description'  => $description !== null ? trim($description) : null,
            'price'        => $price,
            'currency'     => $currency,
            'per_night'    => $perNight,
            'per_guest'    => $perGuest,
            'is_percentage'=> strcasecmp($rateType, 'Percentage') === 0 || $percentage !== null,
            'percentage'   => $percentage,
            'charge_type'  => $raw['charge_type'] ?? $raw['chargeType'] ?? null,
            'frequency'    => $raw['frequency'] ?? null,
            'required'     => $this->addonIsMandatory($raw),
            'max_quantity' => $maxQty,
            'image'        => is_string($imageUrl) ? $this->normaliseImageUrl($imageUrl) : null,
            'source'       => 'api',
        ];
    }

    /**
     * Lodgify signals a compulsory extra through charge_type rather than a
     * boolean. "SingleCharge" and "PerStay" are optional extras; a mandatory
     * one reports itself as such.
     */
    protected function addonIsMandatory(array $raw): bool
    {
        foreach (['isMandatory', 'is_mandatory', 'mandatory', 'required'] as $key) {
            if (array_key_exists($key, $raw)) {
                return (bool) $raw[$key];
            }
        }
        $chargeType = strtolower((string) ($raw['charge_type'] ?? $raw['chargeType'] ?? ''));
        return str_contains($chargeType, 'mandatory') || str_contains($chargeType, 'compulsory');
    }

    /**
     * Name and description for the configured locale.
     *
     * Falls back to any available translation rather than showing nothing — a
     * guest seeing the wrong language beats a guest seeing a blank row.
     *
     * @return array{0: ?string, 1: ?string}
     */
    protected function addonTranslation(array $raw): array
    {
        $translations = $raw['translations'] ?? null;
        if (!is_array($translations) || $translations === []) {
            return [null, null];
        }

        $locale = (string) config('lodgify.addons_locale', 'en');
        $chosen = $translations[$locale]
            ?? $translations[strtolower($locale)]
            ?? collect($translations)->first(fn ($t) => is_array($t) && !empty($t['name']));

        if (!is_array($chosen)) {
            return [null, null];
        }

        $name = isset($chosen['name']) && trim((string) $chosen['name']) !== ''
            ? (string) $chosen['name'] : null;
        $desc = isset($chosen['description']) && trim((string) $chosen['description']) !== ''
            ? (string) $chosen['description'] : null;

        return [$name, $desc];
    }

    /**
     * How an add-on's charge scales, from Lodgify's `chargeType` enum.
     *
     * "PerStay" is the only value confirmed on a live account. The others are
     * inferred from the naming convention, so matching is done on substrings
     * and anything unrecognised is logged and treated as a ONE-OFF charge —
     * under-stating a cost is safer than over-charging a guest.
     *
     * @return array{0: bool, 1: bool} [perNight, perGuest]
     */
    /**
     * How an add-on scales, across TWO INDEPENDENT DIMENSIONS.
     *
     * Lodgify splits this across two fields that combine freely:
     *
     *   charge_type   SingleCharge | PerPerson   <- the UNIT dimension
     *   frequency     PerStay      | PerNight    <- the TIME dimension
     *
     * Which gives four real combinations, all four confirmed on a live account:
     *
     *   SingleCharge + PerStay    $10 flat                  (early check-in)
     *   SingleCharge + PerNight   $12 x nights
     *   PerPerson    + PerStay    $20 x guests
     *   PerPerson    + PerNight   $30 x guests x nights
     *
     * Reading only `frequency` — as an earlier version did — silently dropped
     * the per-person dimension and under-charged the last two by the guest
     * count. Each dimension must be read from its own field.
     *
     * @return array{0: bool, 1: bool} [perNight, perGuest]
     */
    protected function addonChargeScaling(array $raw): array
    {
        $chargeType = strtolower((string) (
            $raw['charge_type'] ?? $raw['chargeType'] ?? $raw['price_type'] ?? $raw['type'] ?? ''
        ));
        $frequency = strtolower((string) (
            $raw['frequency'] ?? $raw['unit'] ?? ''
        ));

        // Either field may carry either signal, so test both for both.
        $haystack = $chargeType . ' ' . $frequency;

        $perNight = str_contains($haystack, 'night') || str_contains($haystack, 'day');
        $perGuest = str_contains($haystack, 'person') || str_contains($haystack, 'guest')
                    || str_contains($haystack, 'people') || str_contains($haystack, 'pax');

        $recognised = $perNight || $perGuest
                      || str_contains($haystack, 'stay') || str_contains($haystack, 'single')
                      || str_contains($haystack, 'fixed') || str_contains($haystack, 'once')
                      || str_contains($haystack, 'booking');

        if (!$recognised && $haystack !== ' ') {
            Log::info('Lodgify add-on: unrecognised charge_type/frequency, treating as one-off', [
                'charge_type' => $raw['charge_type'] ?? null,
                'frequency'   => $raw['frequency'] ?? null,
            ]);
        }

        return [$perNight, $perGuest];
    }

    // =========================================================================
    // Resilience helper
    // =========================================================================

    /**
     * Run a Lodgify call in isolation. On failure: log it, record it in
     * lastErrors, and return null instead of propagating the exception.
     *
     * This is what stops a single 403 from turning into a 500 for the whole
     * calendar endpoint.
     */
    protected function safe(string $context, \Closure $callback): mixed
    {
        try {
            return $callback();
        } catch (LodgifyApiException $e) {
            $msg = "{$context}: HTTP {$e->status}";
            $this->lastErrors[] = $msg;
            // A 4xx often carries a reason worth repeating to the guest.
            $this->lastGuestMessage = $e->guestMessage() ?? $this->lastGuestMessage;
            Log::warning("Lodgify call failed but was isolated [{$context}]", [
                'status' => $e->status,
                'body'   => mb_substr($e->responseBody, 0, 300),
            ]);
            return null;
        } catch (\Throwable $e) {
            $msg = "{$context}: {$e->getMessage()}";
            $this->lastErrors[] = $msg;
            Log::warning("Lodgify call threw but was isolated [{$context}]", [
                'exception' => $e::class,
                'message'   => $e->getMessage(),
            ]);
            return null;
        }
    }

    // =========================================================================
    // Cache helpers
    // =========================================================================

    protected function rememberArray(string $key, int $ttl, \Closure $callback): mixed
    {
        $tag     = (string) config('lodgify.cache_tag', 'lodgify');
        $driver  = config('cache.default');
        $useTags = in_array($driver, ['redis', 'memcached'], true);

        $versioned    = self::CACHE_VERSION . ':' . $key;
        $store        = $useTags ? Cache::tags([$tag]) : Cache::store();
        $effectiveKey = $useTags ? $versioned : "{$tag}:{$versioned}";

        return $store->remember($effectiveKey, $ttl, function () use ($callback) {
            $value = $callback();
            if (is_object($value)) {
                throw new \LogicException(
                    'LodgifyRepository cache callback returned an object. Only '
                    . 'arrays/scalars/null may be cached. Got: ' . get_class($value)
                );
            }
            return $value;
        });
    }

    public function flushCache(): void
    {
        $tag    = (string) config('lodgify.cache_tag', 'lodgify');
        $driver = config('cache.default');
        if (in_array($driver, ['redis', 'memcached'], true)) {
            Cache::tags([$tag])->flush();
            return;
        }
        Cache::flush();
    }

    // =========================================================================
    // Mapping
    // =========================================================================

    protected function mapCottage(array $raw): Cottage
    {
        $id = (int) ($raw['id'] ?? 0);

        $roomsRaw = $raw['rooms'] ?? $raw['room_types'] ?? [];
        $rooms = collect($roomsRaw)->map(fn ($r) => [
            'id'        => (int) ($r['id'] ?? 0),
            'name'      => (string) ($r['name'] ?? ''),
            'maxGuests' => (int) ($r['max_people'] ?? $r['sleeps_max'] ?? $r['sleeps'] ?? 0),
        ])->all();

        [$apiImages, $imageAlts] = $this->extractImagesWithAlts($raw);
        $name = (string) ($raw['name'] ?? '');

        // Occupancy lives on the ROOM (`max_people`), not the property.
        $maxGuests = (int) (
            $raw['max_people'] ?? $raw['sleeps_max'] ?? $raw['sleeps']
            ?? $raw['max_guests'] ?? $raw['guests'] ?? $raw['persons'] ?? 0
        );
        if ($maxGuests === 0 && $rooms !== []) {
            $maxGuests = (int) collect($rooms)->sum('maxGuests');
        }

        /*
         * Bedroom/bathroom counts are frequently 0 on both endpoints, while the
         * real numbers sit in the amenity prefixes:
         *   { "name": "RoomsBedroom",  "prefix": "3" }
         *   { "name": "RoomsBathroom", "prefix": "2" }
         */
        $fromAmenities = $this->roomCountsFromAmenities($raw);

        $bedrooms  = (int) ($raw['bedrooms']  ?? $raw['bedroom_count']  ?? 0)
                     ?: ($fromAmenities['bedrooms']  ?? 0);
        $bathrooms = (int) ($raw['bathrooms'] ?? $raw['bathroom_count'] ?? 0)
                     ?: ($fromAmenities['bathrooms'] ?? 0);
        if ($bedrooms === 0 && count($rooms) > 1) {
            $bedrooms = count($rooms);
        }

        // Lodgify's `address` is a plain string on v2 property responses, but an
        // object on some others — handle both.
        $address = is_array($raw['address'] ?? null) ? $raw['address'] : [];
        $addressString = is_string($raw['address'] ?? null) ? $raw['address'] : null;

        $slug = $this->uniqueSlug($raw['slug'] ?? $name, $id);

        /*
         * The Public API exposes only the cover image, so the full gallery is
         * resolved separately (manifest / local files / public page scrape).
         * See PropertyImageResolver for why.
         */
        $images = $this->imageResolver->resolve($id, $slug, $apiImages);

        return new Cottage(
            id:   $id,
            // Two cottages can share a name, so the id keeps slugs unique
            // and routable. Without it, duplicates are unreachable.
            slug: $this->uniqueSlug($raw['slug'] ?? $name, $id),
            name: $name,
            description:      $this->firstString($raw, ['description', 'long_description', 'summary', 'text']),
            shortDescription: $this->firstString($raw, ['short_description', 'summary', 'tagline']),

            addressLine: $addressString
                         ?? $this->firstString($address, ['street', 'address', 'line1', 'address_line_1'])
                         ?? $this->firstString($raw, ['street', 'address_line']),
            city:        $address['city']     ?? $raw['city']     ?? null,
            state:       $address['state']    ?? $address['region'] ?? $raw['state'] ?? null,
            country:     $address['country']  ?? $raw['country']  ?? $raw['country_code'] ?? $address['country_code'] ?? null,
            postalCode:  $address['postal_code'] ?? $address['zip'] ?? $raw['zip'] ?? $raw['postal_code'] ?? null,
            latitude:    $this->firstFloat($raw, ['latitude', 'lat']) ?? $this->firstFloat($address, ['latitude', 'lat']),
            longitude:   $this->firstFloat($raw, ['longitude', 'lng', 'lon']) ?? $this->firstFloat($address, ['longitude', 'lng', 'lon']),

            bedrooms:     $bedrooms,
            bathrooms:    $bathrooms,
            maxGuests:    $maxGuests,
            propertyType: $this->firstString($raw, ['property_type', 'type', 'rental_type']),
            sizeSqm:      isset($raw['area']) ? (int) $raw['area'] : (isset($raw['size']) ? (int) $raw['size'] : null),

            petFriendly:     (bool) ($raw['pets_allowed'] ?? $raw['pet_friendly'] ?? false),
            smokingAllowed:  (bool) ($raw['smoking_allowed'] ?? false),
            partiesAllowed:  (bool) ($raw['party_allowed'] ?? $raw['events_allowed'] ?? false),
            // `adults_only` is the field Lodgify actually sets.
            childrenAllowed: !($raw['adults_only'] ?? false) && (bool) ($raw['children_allowed'] ?? true),
            checkInTime:     $this->firstString($raw, ['check_in_time', 'checkin_time', 'in_out_max_date']),
            checkOutTime:    $this->firstString($raw, ['check_out_time', 'checkout_time']),
            minStay:         isset($raw['min_stay']) ? (int) $raw['min_stay'] : null,
            maxStay:         isset($raw['max_stay']) ? (int) $raw['max_stay'] : null,
            houseRules:      $this->extractHouseRules($raw),

            heroImage: $images[0] ?? null,
            images:    $images,
            imageAlts: $imageAlts,

            rooms:            $rooms,
            /*
             * IMPORTANT: `min_price`/`max_price` are CURRENCY-CONVERTED by
             * Lodgify (we measured a consistent 0.8635 factor against the
             * originals). The `original_*` fields are the configured figures, so
             * those are what a guest should see.
             */
            baseNightlyPrice: $this->firstFloat($raw, [
                'original_min_price', 'price', 'rate', 'nightly_price', 'min_price',
            ]),
            currency:         $raw['currency_code'] ?? $raw['currency'] ?? null,

            amenities: $this->extractAmenities($raw),
        );
    }

    protected function firstString(array $raw, array $keys): ?string
    {
        foreach ($keys as $k) {
            $v = $raw[$k] ?? null;
            if (is_string($v) && trim($v) !== '') {
                return trim($v);
            }
        }
        return null;
    }

    protected function firstFloat(array $raw, array $keys): ?float
    {
        foreach ($keys as $k) {
            $v = $raw[$k] ?? null;
            if (is_numeric($v) && (float) $v !== 0.0) {
                return (float) $v;
            }
        }
        return null;
    }

    /**
     * Amenities grouped by category.
     *
     * Lodgify may return a flat list of strings, a list of objects with
     * name/prefix/text, or pre-grouped structures. We normalise all of them
     * into ['Category' => ['Amenity', ...]].
     *
     * @return array<string, string[]>
     */
    protected function extractAmenities(array $raw): array
    {
        $grouped = [];

        $push = function (string $group, ?string $label) use (&$grouped) {
            $label = trim((string) $label);
            if ($label === '') {
                return;
            }
            $group = $this->prettyAmenityGroup($group);
            $grouped[$group] ??= [];
            if (!in_array($label, $grouped[$group], true)) {
                $grouped[$group][] = $label;
            }
        };

        /*
         * The V1 room endpoint returns amenities as an object keyed by category,
         * with EMPTY categories included:
         *
         *   "amenities": {
         *     "room":    [ { "name":"RoomsBedroom", "prefix":"3",
         *                    "bracket":"Private", "text":"3 Bedroom" } ],
         *     "cooking": [ { "name":"CookingEatingRefrigerator",
         *                    "text":"Refrigerator" } ],
         *     "laundry": [], "outside": [], ...
         *   }
         *
         * `text` is the human label. `name` is an internal enum
         * ("RoomsBedroom"), so it must never be shown to a guest.
         */
        foreach (['amenities', 'facilities', 'features'] as $key) {
            $bucket = $raw[$key] ?? null;
            if (!is_array($bucket)) {
                continue;
            }

            // keyed-by-category object (the real shape)
            if (!array_is_list($bucket)) {
                foreach ($bucket as $group => $items) {
                    if (!is_array($items) || $items === []) {
                        continue;   // skip the empty categories
                    }
                    foreach ($items as $item) {
                        if (is_string($item)) {
                            $push((string) $group, $item);
                            continue;
                        }
                        if (!is_array($item)) {
                            continue;
                        }
                        $push((string) $group, $this->amenityLabel($item));
                    }
                }
                continue;
            }

            // flat list fallback
            foreach ($bucket as $item) {
                if (is_string($item)) {
                    $push('Features', $item);
                } elseif (is_array($item)) {
                    if (isset($item['items']) && is_array($item['items'])) {
                        $group = (string) ($item['name'] ?? $item['group'] ?? 'Features');
                        foreach ($item['items'] as $sub) {
                            $push($group, is_array($sub) ? $this->amenityLabel($sub) : (string) $sub);
                        }
                        continue;
                    }
                    $push((string) ($item['group'] ?? $item['category'] ?? 'Features'), $this->amenityLabel($item));
                }
            }
        }

        // A few useful booleans Lodgify exposes outside the amenities object.
        if (!empty($raw['has_wifi']))          $push('entertainment', 'Wi-Fi');
        if (!empty($raw['has_parking']))       $push('parking', 'Parking available');
        if (!empty($raw['pets_allowed']))      $push('policies', 'Pets allowed');
        if (!empty($raw['breakfast_included'])) $push('cooking', 'Breakfast included');
        if (!empty($raw['has_meal_plan']))     $push('cooking', 'Meal plan available');

        ksort($grouped);
        return $grouped;
    }

    /**
     * Human label for one amenity record.
     *
     * `text` is authoritative. Only fall back to composing from prefix/bracket
     * when it is missing — and never expose `name`, which is an internal enum.
     */
    protected function amenityLabel(array $item): ?string
    {
        $text = trim((string) ($item['text'] ?? ''));
        if ($text !== '') {
            $bracket = trim((string) ($item['bracket'] ?? ''));
            return $bracket !== '' ? "{$text} ({$bracket})" : $text;
        }

        // no text: build something readable from the enum name
        $name = trim((string) ($item['name'] ?? ''));
        if ($name === '') {
            return null;
        }
        $readable = trim(preg_replace('/(?<!^)[A-Z]/', ' $0', $name) ?? $name);
        $prefix   = trim((string) ($item['prefix'] ?? ''));

        return $prefix !== '' && is_numeric($prefix)
            ? "{$prefix} {$readable}"
            : $readable;
    }

    /** "further-info" -> "Further Info", "livingroom" -> "Living Room". */
    protected function prettyAmenityGroup(string $group): string
    {
        $group = trim($group);
        if ($group === '') {
            return 'Features';
        }
        $map = [
            'room'          => 'Rooms',
            'livingroom'    => 'Living Room',
            'further-info'  => 'Good to Know',
            'miscellaneous' => 'Other',
            'sanitary'      => 'Bathroom',
            'cooking'       => 'Kitchen & Dining',
            'entertainment' => 'Entertainment & Internet',
            'heating'       => 'Heating & Cooling',
            'outside'       => 'Outdoors',
            'sleeping'      => 'Sleeping',
            'laundry'       => 'Laundry',
            'parking'       => 'Parking',
            'policies'      => 'Policies',
        ];
        $key = strtolower($group);
        return $map[$key] ?? Str::headline(str_replace('-', ' ', $group));
    }

    /**
     * Bedroom/bathroom counts hidden in the amenity prefixes, e.g.
     *   { "name": "RoomsBedroom", "prefix": "3" }  ->  bedrooms = 3
     *
     * @return array{bedrooms?:int,bathrooms?:int}
     */
    protected function roomCountsFromAmenities(array $raw): array
    {
        $amenities = $raw['amenities'] ?? null;
        if (!is_array($amenities)) {
            return [];
        }

        $out = [];
        $scan = function (array $items) use (&$out) {
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $name   = (string) ($item['name'] ?? '');
                $prefix = $item['prefix'] ?? null;
                if (!is_numeric($prefix)) {
                    continue;
                }
                if (stripos($name, 'bedroom') !== false) {
                    $out['bedrooms'] = (int) $prefix;
                } elseif (stripos($name, 'bathroom') !== false) {
                    $out['bathrooms'] = (int) $prefix;
                }
            }
        };

        if (array_is_list($amenities)) {
            $scan($amenities);
        } else {
            foreach ($amenities as $items) {
                if (is_array($items)) {
                    $scan($items);
                }
            }
        }

        return $out;
    }

    /**
     * Free-text house rules, split into individual lines where possible.
     * @return string[]
     */
    protected function extractHouseRules(array $raw): array
    {
        $text = $this->firstString($raw, ['house_rules', 'rules', 'policy', 'terms', 'rental_agreement']);
        if ($text === null) {
            return [];
        }
        $clean = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</li>'], "\n", $text));
        return collect(preg_split('/\r\n|\r|\n|•|\u{2022}/u', $clean))
            ->map(fn ($l) => trim(html_entity_decode($l)))
            ->filter(fn ($l) => $l !== '' && mb_strlen($l) > 2)
            ->take(20)
            ->values()
            ->all();
    }

    protected function slugify(string $value): string
    {
        return Str::slug($value);
    }

    /**
     * Pull image URLs out of a Lodgify property payload.
     *
     * Lodgify is inconsistent here: images may be a list of objects (with any
     * of several key names), a list of plain strings, or single-image fields on
     * the property itself. URLs are frequently PROTOCOL-RELATIVE
     * (//l.icdbcdn.com/...) which breaks <img src> on an http:// dev host, so
     * we normalise the scheme.
     *
     * @return string[]
     */
    /**
     * Images plus their alt text.
     *
     * The V1 room endpoint gives `[{ text, url }]`, where `text` is the label
     * the owner typed in the dashboard — worth keeping rather than repeating the
     * cottage name on every <img>.
     *
     * @return array{0: string[], 1: array<string,string>}
     */
    protected function extractImagesWithAlts(array $raw): array
    {
        $pairs = [];

        $add = function (?string $url, ?string $alt) use (&$pairs) {
            if (!is_string($url) || trim($url) === '') {
                return;
            }
            $normalised = $this->normaliseImageUrl($url);
            if ($normalised === null) {
                return;
            }
            $pairs[$normalised] ??= trim((string) $alt);
        };

        foreach (['images', 'image_urls', 'photos', 'pictures', 'gallery'] as $key) {
            foreach ((array) ($raw[$key] ?? []) as $item) {
                if (is_string($item)) {
                    $add($item, null);
                    continue;
                }
                if (!is_array($item)) {
                    continue;
                }
                $url = null;
                foreach (['url', 'original_url', 'image_url', 'src', 'path', 'thumbnail_url', 'big_url'] as $k) {
                    if (!empty($item[$k]) && is_string($item[$k])) {
                        $url = $item[$k];
                        break;
                    }
                }
                $add($url, $item['text'] ?? $item['caption'] ?? $item['title'] ?? null);
            }
        }

        foreach (['image_url', 'main_image_url', 'thumbnail_url', 'thumbnail', 'cover_image'] as $key) {
            if (!empty($raw[$key]) && is_string($raw[$key])) {
                $add($raw[$key], null);
            }
        }

        foreach ((array) ($raw['rooms'] ?? $raw['room_types'] ?? []) as $room) {
            if (!is_array($room)) {
                continue;
            }
            foreach ((array) ($room['images'] ?? []) as $item) {
                if (is_string($item)) {
                    $add($item, null);
                } elseif (is_array($item)) {
                    $url = null;
                    foreach (['url', 'original_url', 'image_url', 'src', 'path'] as $k) {
                        if (!empty($item[$k]) && is_string($item[$k])) {
                            $url = $item[$k];
                            break;
                        }
                    }
                    $add($url, $item['text'] ?? null);
                }
            }
        }

        $urls = array_keys($pairs);
        $alts = array_filter($pairs, fn ($a) => $a !== '');

        return [$urls, $alts];
    }

    protected function extractImages(array $raw): array
    {
        $candidates = [];

        // a) list under a plausible key
        foreach (['images', 'image_urls', 'photos', 'pictures', 'gallery'] as $key) {
            foreach ((array) ($raw[$key] ?? []) as $item) {
                if (is_string($item)) {
                    $candidates[] = $item;
                } elseif (is_array($item)) {
                    foreach (['url', 'original_url', 'image_url', 'src', 'path', 'thumbnail_url', 'big_url'] as $k) {
                        if (!empty($item[$k]) && is_string($item[$k])) {
                            $candidates[] = $item[$k];
                            break;
                        }
                    }
                }
            }
        }

        // b) single-image fields on the property
        foreach (['image_url', 'main_image_url', 'thumbnail_url', 'thumbnail', 'cover_image'] as $key) {
            if (!empty($raw[$key]) && is_string($raw[$key])) {
                $candidates[] = $raw[$key];
            }
        }

        // c) images nested under room types
        foreach ((array) ($raw['rooms'] ?? $raw['room_types'] ?? []) as $room) {
            if (!is_array($room)) {
                continue;
            }
            foreach ((array) ($room['images'] ?? []) as $item) {
                if (is_string($item)) {
                    $candidates[] = $item;
                } elseif (is_array($item)) {
                    foreach (['url', 'original_url', 'image_url', 'src', 'path'] as $k) {
                        if (!empty($item[$k]) && is_string($item[$k])) {
                            $candidates[] = $item[$k];
                            break;
                        }
                    }
                }
            }
        }

        return collect($candidates)
            ->filter()
            ->map(fn (string $u) => $this->normaliseImageUrl($u))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Normalise a Lodgify image URL.
     *
     * Two quirks handled here:
     *
     * 1. PROTOCOL-RELATIVE URLS. Lodgify returns `//l.icdbcdn.com/oh/....png`
     *    with no scheme, which breaks <img src> on an http:// dev host.
     *
     * 2. SIZE PRESET. The `?f=NN` parameter is a CDN transform preset, and the
     *    value Lodgify hands back (f=32) is a small thumbnail — fine for a list
     *    row, far too low-res for a hero image. `lodgify.image_size_param`
     *    rewrites it; set it to null to leave whatever Lodgify sent untouched.
     */
    protected function normaliseImageUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        if (str_starts_with($url, '//')) {
            $url = 'https:' . $url;
        } elseif (str_starts_with($url, 'http://')) {
            $url = 'https://' . substr($url, 7);
        } elseif (!str_starts_with($url, 'https://')) {
            $url = 'https://l.icdbcdn.com/' . ltrim($url, '/');
        }

        $size = config('lodgify.image_size_param');
        if ($size !== null && $size !== '' && str_contains($url, 'icdbcdn')) {
            // replace an existing f= preset, or append one
            if (preg_match('/[?&]f=/', $url)) {
                $url = preg_replace('/([?&])f=[^&]*/', '$1f=' . rawurlencode((string) $size), $url);
            } else {
                $url .= (str_contains($url, '?') ? '&' : '?') . 'f=' . rawurlencode((string) $size);
            }
        }

        return $url;
    }

    /**
     * Contiguous runs of free nights for one cottage inside a date range.
     *
     * @return array<int, array{start:string,end:string,nights:int,min_stay:int}>
     */
    public function freeWindows(Cottage $cottage, string $from, string $to): array
    {
        $days = $this->cottageAvailability($cottage, min($from, Carbon::today()->toDateString()));
        if ($days === []) {
            return [];
        }

        $windows = [];
        $current = null;
        $cursor  = Carbon::parse($from);
        $end     = Carbon::parse($to);

        while ($cursor->lte($end)) {
            $key  = $cursor->toDateString();
            $day  = $days[$key] ?? null;
            $free = $day && !empty($day['isAvailable']);

            if ($free) {
                $minStay = (int) ($day['minimalStay'] ?? 1);
                if ($current === null) {
                    $current = ['start' => $key, 'end' => $key, 'nights' => 1, 'min_stay' => max(1, $minStay)];
                } else {
                    $current['end']      = $key;
                    $current['nights']  += 1;
                    $current['min_stay'] = max($current['min_stay'], max(1, $minStay));
                }
            } elseif ($current !== null) {
                $windows[] = $current;
                $current = null;
            }
            $cursor->addDay();
        }
        if ($current !== null) {
            $windows[] = $current;
        }

        // only windows long enough to actually book
        return array_values(array_filter($windows, fn ($w) => $w['nights'] >= $w['min_stay']));
    }

    // =========================================================================
    // Per-day rates (prices in the calendar) + seasonal rate periods
    // =========================================================================

    /**
     * Day-by-day price + availability for one cottage over a date range.
     *
     * Prices come from the AUTHENTICATED /v2/rates/calendar endpoint (the
     * public availability endpoint carries no pricing). Availability is merged
     * in from cottageAvailability() so a day can be priced but unbookable.
     *
     * Any day we cannot price returns price = null and the UI simply omits the
     * figure rather than inventing one.
     *
     * @return Collection<string, RateDay> keyed by YYYY-MM-DD
     */
    public function rateCalendar(Cottage $cottage, string $startDate, string $endDate): Collection
    {
        $parsed = $this->rateCalendarRaw($cottage, $startDate, $endDate);

        $perDay   = $parsed['days']     ?? [];
        $default  = $parsed['default']  ?? null;
        $settings = $parsed['settings'] ?? [];
        $currency = $settings['currency_code'] ?? $cottage->currency;

        /*
         * Availability must cover the REQUESTED range. Previously this asked for
         * today + availability_window_days, so any month beyond that window came
         * back with no data — and "no data" renders as unavailable, making the
         * whole of next year look fully booked.
         */
        $availability = $this->cottageAvailability($cottage, $startDate, $endDate);
        $seasons      = $this->seasons($cottage);

        $out    = collect();
        $cursor = Carbon::parse($startDate);
        $end    = Carbon::parse($endDate);

        while ($cursor->lte($end)) {
            $d = $cursor->toDateString();

            // Fall back to the default rate for days the calendar didn't cover.
            $rate  = $perDay[$d] ?? $default;
            $isDef = !isset($perDay[$d]) && $default !== null;
            $av    = $availability[$d] ?? null;

            $out->put($d, new RateDay(
                date:            $d,
                price:           $rate['price'] ?? null,
                currency:        $currency,
                available:       (bool) ($av['isAvailable'] ?? false),
                checkInAllowed:  (bool) ($av['isCheckInAvailable'] ?? false),
                checkOutAllowed: (bool) ($av['isCheckOutAvailable'] ?? false),
                // Prefer the rate calendar's min stay: it is what Lodgify
                // actually enforces at checkout for that specific night.
                minStay:         (int) ($rate['min_stay'] ?? $av['minimalStay'] ?? 1),
                maxStay:         isset($rate['max_stay']) ? (int) $rate['max_stay'] : null,
                seasonName:      $this->seasonNameFor($seasons, $d),
                isDefaultRate:   $isDef,
                pricePerAdditionalGuest: $rate['extra_guest_price'] ?? null,
                additionalGuestsStartFrom: $rate['extra_guest_from'] ?? null,
            ));
            $cursor->addDay();
        }

        return $out;
    }

    /**
     * The rate-settings block that accompanies the rate calendar: currency,
     * check-in/out hours, VAT, fees, taxes, promotions. Cached alongside the
     * per-day data so the page can show policy info without another call.
     *
     * @return array<string, mixed>
     */
    public function rateSettings(Cottage $cottage, ?string $startDate = null, ?string $endDate = null): array
    {
        $startDate ??= Carbon::today()->toDateString();
        $endDate   ??= Carbon::today()->addDays((int) config('lodgify.availability_window_days', 90))->toDateString();

        return $this->rateCalendarRaw($cottage, $startDate, $endDate)['settings'] ?? [];
    }

    /**
     * Fetch + parse the rate calendar once, cached. Returns primitives only.
     *
     * @return array{days:array<string,array<string,mixed>>,default:?array<string,mixed>,settings:array<string,mixed>}
     */
    protected function rateCalendarRaw(Cottage $cottage, string $startDate, string $endDate): array
    {
        $key = "ratesraw:{$cottage->id}:{$startDate}:{$endDate}";

        return $this->rememberArray(
            $key,
            (int) config('lodgify.cache.availability'),
            function () use ($cottage, $startDate, $endDate) {
                $raw = $this->safe(
                    "getRatesCalendar:{$cottage->id}",
                    fn () => $this->client->getRatesCalendar(
                        $cottage->id, $startDate, $endDate, $cottage->primaryRoomId()
                    )
                );
                return $raw === null
                    ? ['days' => [], 'default' => null, 'settings' => []]
                    : $this->normaliseRateCalendar($raw);
            }
        ) ?? ['days' => [], 'default' => null, 'settings' => []];
    }

    /**
     * Parse Lodgify's rates-calendar response.
     *
     * VERIFIED SHAPE (from a live account):
     *   {
     *     "calendar_items": [
     *       { "date": null, "is_default": true,
     *         "prices": [ { "min_stay":1, "max_stay":1125, "price_per_day":100.0,
     *                       "price_per_additional_guest":0.0,
     *                       "additional_guests_starts_from":0 } ] },
     *       { "date": "2026-08-28", "is_default": false,
     *         "prices": [ { "min_stay":2, "price_per_day":150.0, ... } ] },
     *       ...
     *     ],
     *     "rate_settings": { "check_in_hour":14, "check_out_hour":12,
     *                        "currency_code":"USD", "vat":0.0,
     *                        "fees":[], "taxes":[], "promotions":[] }
     *   }
     *
     * The important subtlety: PRICE AND MIN_STAY ARE NESTED inside `prices[]`,
     * not on the calendar item. The item with `date: null` is the default rate
     * used for any day the calendar doesn't explicitly cover.
     *
     * @return array{days:array<string,array<string,mixed>>,default:?array<string,mixed>,settings:array<string,mixed>}
     */
    protected function normaliseRateCalendar(array $raw): array
    {
        // Unwrap: response may be the object itself, or a list of per-room-type objects.
        $items    = null;
        $settings = [];

        if (isset($raw['calendar_items'])) {
            $items    = $raw['calendar_items'];
            $settings = $raw['rate_settings'] ?? [];
        } else {
            foreach ($raw as $entry) {
                if (is_array($entry) && isset($entry['calendar_items'])) {
                    $items    = $entry['calendar_items'];
                    $settings = $entry['rate_settings'] ?? [];
                    break;
                }
            }
        }

        if (!is_array($items)) {
            Log::info('Lodgify rates calendar: no calendar_items found', [
                'top_level_keys' => array_keys($raw),
            ]);
            return ['days' => [], 'default' => null, 'settings' => $settings];
        }

        $days    = [];
        $default = null;

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $price = $this->extractRatePrice($item);
            if ($price === null) {
                continue;
            }

            // date === null marks the default/fallback rate
            $date = $item['date'] ?? null;
            if ($date === null || !empty($item['is_default'])) {
                $default ??= $price;
                if ($date === null) {
                    continue;
                }
            }

            $days[(string) $date] = $price;
        }

        if ($days === []) {
            Log::info('Lodgify rates calendar: parsed 0 priced days', [
                'item_count'   => count($items),
                'sample_item'  => is_array($items[1] ?? $items[0] ?? null) ? ($items[1] ?? $items[0]) : null,
            ]);
        }

        return ['days' => $days, 'default' => $default, 'settings' => $settings];
    }

    /**
     * Pull the price block out of a single calendar item.
     *
     * @return array{price:?float,min_stay:?int,max_stay:?int,extra_guest_price:?float,extra_guest_from:?int}|null
     */
    protected function extractRatePrice(array $item): ?array
    {
        // Nested (the real shape)
        $prices = $item['prices'] ?? null;
        $block  = null;

        if (is_array($prices) && $prices !== []) {
            // Multiple entries can exist for guest-count tiers; the first is the base.
            $block = is_array($prices[0] ?? null) ? $prices[0] : null;
        }

        // Flat fallback, in case Lodgify ever inlines it
        if ($block === null) {
            $flatPrice = $this->firstFloat($item, ['price_per_day', 'daily_price', 'price', 'rate']);
            if ($flatPrice === null) {
                return null;
            }
            $block = $item;
        }

        $price = $this->firstFloat($block, ['price_per_day', 'daily_price', 'price', 'rate']);
        if ($price === null) {
            return null;
        }

        return [
            'price'             => $price,
            'min_stay'          => isset($block['min_stay']) ? (int) $block['min_stay'] : null,
            'max_stay'          => isset($block['max_stay']) ? (int) $block['max_stay'] : null,
            'extra_guest_price' => $this->firstFloat($block, ['price_per_additional_guest']),
            'extra_guest_from'  => isset($block['additional_guests_starts_from'])
                                     ? (int) $block['additional_guests_starts_from'] : null,
        ];
    }

    /**
     * Configured seasonal rate periods for a cottage.
     *
     * @return Collection<int, RateSeason>
     */
    public function seasons(Cottage $cottage): Collection
    {
        $raw = $this->rememberArray(
            "seasons:{$cottage->id}",
            (int) config('lodgify.cache.rate_settings'),
            function () use ($cottage) {
                // authenticated settings first
                $auth = $this->safe(
                    "getRateSettings:{$cottage->id}",
                    fn () => $this->client->getRateSettings($cottage->id)
                );
                if (!empty($auth)) {
                    return ['source' => 'auth', 'data' => $auth];
                }
                // public per-property rates (shape captured from the browser)
                $pub = $this->safe(
                    "getPublicRates:{$cottage->id}",
                    fn () => $this->client->getPublicRates($cottage->id)
                );
                return ['source' => 'public', 'data' => $pub ?? []];
            }
        ) ?? [];

        return $this->mapSeasons($raw['data'] ?? [], $cottage);
    }

    /** @return Collection<int, RateSeason> */
    protected function mapSeasons(array $data, Cottage $cottage): Collection
    {
        $seasons = collect();

        // Public shape: { roomTypes: { 805539: { defaultRate:{...}, rates:[...] } } }
        $roomTypes = $data['roomTypes'] ?? null;
        if (is_array($roomTypes)) {
            foreach ($roomTypes as $rt) {
                if (!is_array($rt)) {
                    continue;
                }
                $currency = $rt['defaultRate']['currency'] ?? $cottage->currency;

                foreach ((array) ($rt['rates'] ?? []) as $r) {
                    if (!is_array($r)) {
                        continue;
                    }
                    $seasons->push(new RateSeason(
                        name:     (string) ($r['name'] ?? 'Season'),
                        start:    $r['startDate'] ?? $r['start_date'] ?? $r['start'] ?? null,
                        end:      $r['endDate']   ?? $r['end_date']   ?? $r['end']   ?? null,
                        nightly:  $this->firstFloat($r, ['dailyPrice', 'daily_price', 'price_per_day', 'price']),
                        weekly:   $this->firstFloat($r, ['weeklyPrice', 'weekly_price']),
                        monthly:  $this->firstFloat($r, ['monthlyPrice', 'monthly_price']),
                        minStay:  isset($r['minStay']) ? (int) $r['minStay'] : (isset($r['min_stay']) ? (int) $r['min_stay'] : null),
                        currency: $r['currency'] ?? $currency,
                    ));
                }

                if (!empty($rt['defaultRate'])) {
                    $d = $rt['defaultRate'];
                    $seasons->push(new RateSeason(
                        name:      (string) ($d['name'] ?? 'Standard price'),
                        start:     null,
                        end:       null,
                        nightly:   $this->firstFloat($d, ['dailyPrice', 'daily_price', 'price']),
                        weekly:    $this->firstFloat($d, ['weeklyPrice', 'weekly_price']),
                        monthly:   $this->firstFloat($d, ['monthlyPrice', 'monthly_price']),
                        minStay:   isset($d['minStay']) ? (int) $d['minStay'] : null,
                        currency:  $d['currency'] ?? $currency,
                        isDefault: true,
                    ));
                }
            }
        }

        // Authenticated shape: list of { rate_settings / seasons / rates }
        if ($seasons->isEmpty()) {
            $candidates = [];
            foreach ($data as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                foreach (['seasons', 'rates', 'rate_settings', 'periods'] as $k) {
                    if (isset($entry[$k]) && is_array($entry[$k])) {
                        $candidates = array_merge($candidates, $entry[$k]);
                    }
                }
                if (isset($entry['start_date']) || isset($entry['name'])) {
                    $candidates[] = $entry;
                }
            }
            foreach ($candidates as $r) {
                if (!is_array($r)) {
                    continue;
                }
                $nightly = $this->firstFloat($r, ['price_per_day', 'daily_price', 'dailyPrice', 'price', 'rate']);
                if ($nightly === null && empty($r['name'])) {
                    continue;
                }
                $seasons->push(new RateSeason(
                    name:     (string) ($r['name'] ?? 'Season'),
                    start:    $r['start_date'] ?? $r['startDate'] ?? $r['start'] ?? null,
                    end:      $r['end_date']   ?? $r['endDate']   ?? $r['end']   ?? null,
                    nightly:  $nightly,
                    weekly:   $this->firstFloat($r, ['price_per_week', 'weekly_price', 'weeklyPrice']),
                    monthly:  $this->firstFloat($r, ['price_per_month', 'monthly_price', 'monthlyPrice']),
                    minStay:  isset($r['min_stay']) ? (int) $r['min_stay'] : null,
                    currency: $r['currency'] ?? $cottage->currency,
                ));
            }
        }

        // dated seasons first (chronological), default last
        return $seasons
            ->sortBy(fn (RateSeason $s) => $s->isDefault ? '9999-99-99' : ($s->start ?? '0000-00-00'))
            ->values();
    }

    /** @param Collection<int, RateSeason> $seasons */
    protected function seasonNameFor(Collection $seasons, string $date): ?string
    {
        foreach ($seasons as $s) {
            if ($s->isDefault || !$s->start || !$s->end) {
                continue;
            }
            if ($date >= $s->start && $date <= $s->end) {
                return $s->name;
            }
        }
        return $seasons->firstWhere('isDefault', true)?->name;
    }

    /**
     * Every cottage paired with its next few bookable windows, starting today.
     * Powers the availability chips on the cottages listing + home page and the
     * "NEXT OPEN ..." ticker.
     *
     * @return Collection<int, array{cottage:Cottage,windows:array<int, array{start:string,end:string,nights:int,min_stay:int}>}>
     */
    public function cottagesWithOpenings(int $windowsPerCottage = 3, ?int $daysAhead = null): Collection
    {
        $daysAhead ??= (int) config('lodgify.availability_window_days', 90);
        $from = Carbon::today()->toDateString();
        $to   = Carbon::today()->addDays($daysAhead)->toDateString();

        return $this->allCottages()->map(fn (Cottage $c) => [
            'cottage' => $c,
            'windows' => array_slice($this->freeWindows($c, $from, $to), 0, $windowsPerCottage),
        ])->values();
    }

    /**
     * Tier 3 fallback: when neither the exact dates nor a same-length shift
     * works, offer the closest bookable window of ANY length per cottage.
     *
     * Ranked by (a) proximity of its start to the requested arrival, then
     * (b) longest stay. One suggestion per cottage.
     *
     * @return Collection<int, array{cottage:Cottage,arrival:string,departure:string,nights:int,offset_days:int}>
     */
    public function alternativeStays(string $arrival, string $departure, int $window = 30): Collection
    {
        $requestedArrival = Carbon::parse($arrival);
        $from = $requestedArrival->copy()->subDays($window)->max(Carbon::today())->toDateString();
        $to   = Carbon::parse($departure)->addDays($window)->toDateString();

        $out = collect();

        foreach ($this->allCottages() as $cottage) {
            $best = null;
            foreach ($this->freeWindows($cottage, $from, $to) as $w) {
                $offset = abs($requestedArrival->diffInDays(Carbon::parse($w['start']), false));
                $score  = [$offset, -$w['nights']];
                if ($best === null || $score < $best['score']) {
                    $best = ['score' => $score, 'window' => $w, 'offset' => $offset];
                }
            }
            if ($best === null) {
                continue;
            }

            // departure is the morning after the last free night
            $departureDate = Carbon::parse($best['window']['end'])->addDay()->toDateString();

            $out->push([
                'cottage'     => $cottage,
                'arrival'     => $best['window']['start'],
                'departure'   => $departureDate,
                'nights'      => $best['window']['nights'],
                'offset_days' => $best['offset'],
            ]);
        }

        return $out->sortBy([
            fn ($a, $b) => $a['offset_days'] <=> $b['offset_days'],
            fn ($a, $b) => $b['nights'] <=> $a['nights'],
        ])->values();
    }

    /**
     * Slug that is guaranteed unique across properties. Ocean Escape has two
     * pairs of identically-named cottages, so a name-only slug collides and
     * makes the duplicates unreachable.
     */
    protected function uniqueSlug(string $value, int $id): string
    {
        $base = Str::slug($value);
        return $base === '' ? (string) $id : "{$base}-{$id}";
    }

    /**
     * Per-cottage, per-night availability for a date range, with the reason a
     * cottage was excluded. Powers /debug/lodgify/why so "no results" is never
     * a mystery.
     *
     * @return array<int, array<string,mixed>>
     */
    public function explainAvailability(string $arrival, string $departure): array
    {
        $startDate = min($arrival, Carbon::today()->toDateString());
        $out = [];

        foreach ($this->allCottages() as $cottage) {
            $days = $this->cottageAvailability($cottage, $startDate);

            $nights = [];
            $blocked = [];
            $cursor = Carbon::parse($arrival);
            $end    = Carbon::parse($departure);
            while ($cursor->lt($end)) {
                $k   = $cursor->toDateString();
                $day = $days[$k] ?? null;
                $free = $day && !empty($day['isAvailable']);
                $nights[$k] = $day === null ? 'no-data' : ($free ? 'free' : 'booked');
                if (!$free) {
                    $blocked[] = $k;
                }
                $cursor->addDay();
            }

            $out[] = [
                'id'            => $cottage->id,
                'name'          => $cottage->name,
                'slug'          => $cottage->slug,
                'room_id'       => $cottage->primaryRoomId(),
                'max_guests'    => $cottage->maxGuests,
                'pet_friendly'  => $cottage->petFriendly,
                'days_fetched'  => count($days),
                'nights'        => $nights,
                'bookable'      => $blocked === [] && $days !== [],
                'blocked_dates' => $blocked,
                'reason'        => $days === []
                    ? 'no availability data returned for this cottage'
                    : ($blocked === [] ? 'available' : 'blocked on ' . count($blocked) . ' night(s)'),
            ];
        }

        return $out;
    }
}