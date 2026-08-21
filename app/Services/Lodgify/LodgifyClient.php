<?php

namespace App\Services\Lodgify;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Single point of contact with Lodgify's REST API.
 *
 * TWO transports:
 *   http()       -> authenticated api.lodgify.com/v2 (X-ApiKey). Officially
 *                   documented. Prefer this whenever an endpoint exists.
 *   publicHttp() -> checkout.lodgify.com/api/v1 (no key). Undocumented and
 *                   behind Cloudflare, which BLOCKS non-browser user agents
 *                   with HTTP 403. We send browser-like headers to get past
 *                   it. Treat as best-effort fallback only.
 */
class LodgifyClient
{
    protected string $apiKey;
    protected string $baseUrl;
    protected string $checkoutUrl;
    protected string $propertyUrl;
    protected string $ratesUrl;
    protected int $timeout;
    protected int $retries;
    protected int $retryDelay;

    /**
     * Cloudflare in front of checkout.lodgify.com rejects requests that don't
     * look like a browser. These mirror what Chrome sends when Lodgify's own
     * frontend calls the endpoint.
     */
    protected const BROWSER_HEADERS = [
        'User-Agent'         => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
        'Accept'             => 'application/json, text/plain, */*',
        'Accept-Language'    => 'en-US,en;q=0.9',
        'Sec-Fetch-Dest'     => 'empty',
        'Sec-Fetch-Mode'     => 'cors',
        'Sec-Fetch-Site'     => 'cross-site',
        'Sec-Ch-Ua'          => '"Chromium";v="126", "Not:A-Brand";v="24"',
        'Sec-Ch-Ua-Mobile'   => '?0',
        'Sec-Ch-Ua-Platform' => '"macOS"',
    ];

    public function __construct()
    {
        $this->apiKey      = (string) config('lodgify.api_key');
        $this->baseUrl     = rtrim((string) config('lodgify.base_url'), '/');
        $this->checkoutUrl = rtrim((string) config('lodgify.checkout_base_url'), '/');
        $this->propertyUrl = rtrim((string) config('lodgify.property_base_url', 'https://property.lodgify.com'), '/');
        $this->ratesUrl    = rtrim((string) config('lodgify.rates_base_url', 'https://rates.lodgify.com'), '/');
        $this->timeout     = (int) config('lodgify.timeout');
        $this->retries     = (int) config('lodgify.retries');
        $this->retryDelay  = (int) config('lodgify.retry_delay_ms');

        if ($this->apiKey === '') {
            Log::warning('Lodgify API key is not configured. Set LODGIFY_API_KEY in .env');
        }
    }

    protected function http(): PendingRequest
    {
        return Http::withHeaders([
            'X-ApiKey' => $this->apiKey,
            'Accept'   => 'application/json',
        ])
            ->timeout($this->timeout)
            ->retry($this->retries, $this->retryDelay, throw: false);
    }

    /**
     * Public checkout transport. Browser-like headers + Referer/Origin
     * matching the public site, because Cloudflare 403s bare Guzzle.
     */
    protected function publicHttp(): PendingRequest
    {
        $siteOrigin = rtrim((string) config('lodgify.public_site_origin', 'https://oceanescapecottages.ca'), '/');

        return Http::withHeaders(array_merge(self::BROWSER_HEADERS, [
            'Referer' => $siteOrigin . '/',
            'Origin'  => $siteOrigin,
        ]))
            ->timeout($this->timeout)
            ->retry($this->retries, $this->retryDelay, throw: false);
    }

    // =========================================================================
    // Properties
    // =========================================================================

    public function listProperties(): array
    {
        $response = $this->http()->get("{$this->baseUrl}/v2/properties", [
            'includeInOut' => 'true',
        ]);
        $this->assertOk($response, 'listProperties');
        $body = $response->json();
        return $body['items'] ?? $body ?? [];
    }

    public function getProperty(int|string $propertyId): array
    {
        $response = $this->http()->get("{$this->baseUrl}/v2/properties/{$propertyId}");
        $this->assertOk($response, "getProperty:{$propertyId}");
        return $response->json() ?? [];
    }

    /**
     * ROOM info via the Public API V1 — the endpoint that actually carries the
     * full photo gallery, amenities and occupancy.
     * https://docs.lodgify.com/reference/propertiesapi_get_get
     *
     * VERIFIED response (trimmed):
     *   {
     *     "images": [ { "text": "Living room", "url": "//l.icdbcdn.com/oh/{uuid}.png?f=32" }, ... ],
     *     "amenities": {
     *        "room":          [ { "name":"RoomsBedroom", "prefix":"3", "bracket":"Private", "text":"3 Bedroom" } ],
     *        "cooking":       [ { "name":"CookingEatingRefrigerator", "text":"Refrigerator" } ],
     *        "entertainment": [ ... ], "heating": [ ... ], "parking": [ ... ],
     *        "laundry": [], "outside": [], ...          ← empty categories included
     *     },
     *     "description": "...",
     *     "id": 903506, "name": "Cottage1",
     *     "max_people": 6, "units": 1,
     *     "bedrooms": 0, "bathrooms": 0,               ← often 0; see amenities.room prefixes
     *     "has_wifi": true, "has_parking": true,
     *     "pets_allowed": false, "adults_only": true,
     *     "min_price": 129.52, "original_min_price": 150.0
     *   }
     *
     * NOTE ON PRICES: `min_price` / `max_price` are CURRENCY-CONVERTED. The
     * `original_*` fields are the figures configured on the property. Always
     * display the originals.
     *
     * This is per ROOM TYPE, so a multi-room property needs one call per room.
     */
    public function getRoomInfo(int|string $propertyId, int|string $roomId): array
    {
        $response = $this->http()->get(
            "{$this->baseUrl}/v1/properties/{$propertyId}/rooms/{$roomId}"
        );
        $this->assertOk($response, "getRoomInfo:{$propertyId}/{$roomId}");
        return $response->json() ?? [];
    }

    /**
     * Property info via V1. Carries `image_url` plus key facts; images live on
     * the rooms, so getRoomInfo() is usually what you want.
     */
    public function getPropertyV1(int|string $propertyId): array
    {
        $response = $this->http()->get("{$this->baseUrl}/v1/properties/{$propertyId}");
        $this->assertOk($response, "getPropertyV1:{$propertyId}");
        return $response->json() ?? [];
    }

    /**
     * Add-ons offered with a booking.
     *
     * ENDPOINT: GET /v1/properties/{id}/rates/addons
     * Documented, API-key authenticated, no session required.
     *
     * Two near-misses worth recording so nobody retries them:
     *   /v1/properties/{id}/addons                       -> empty body
     *   rates.lodgify.com/api/v2/rates/addons/property/  -> 401/403, dashboard
     *                                                       session only
     *
     * VERIFIED response:
     *   [{
     *     "id": 155523,
     *     "name": "early check in ",
     *     "description": "...",
     *     "charge_type": "SingleCharge",
     *     "rate_type": "Fixed",
     *     "frequency": "PerStay",
     *     "max_quantity": null,
     *     "percentage": null,
     *     "amount": 8.6452839975793204,       <- CONVERTED, do not display
     *     "original_amount": 10.00,           <- the configured price
     *     "image_url": "//l.icdbcdn.com/oh/....png?f=32",
     *     "currency": { "code": "USD", "symbol": "$  ", ... }
     *   }]
     *
     * The amount/original_amount split is the same trap as
     * min_price/original_min_price on the property endpoint: `amount` is
     * converted at the account's forex rate, `original_amount` is what the
     * owner actually set. Charging from `amount` would undercharge by ~13.5%.
     */
    public function getAddons(int|string $propertyId): array
    {
        $response = $this->http()
            ->withHeaders(['Accept' => 'text/plain'])
            ->get("{$this->baseUrl}/v1/properties/{$propertyId}/rates/addons");

        $this->assertOk($response, "getAddons:{$propertyId}");
        return $response->json() ?? [];
    }

    /**
     * Payment methods/policies configured for the property ("Available payments"
     * in the docs sidebar). Needed to tell a guest what they will be charged and
     * when, before handing them to checkout.
     *
     * SHAPE UNVERIFIED — see /debug/lodgify/raw/payments/{id}.
     */
    public function getPaymentOptions(int|string $propertyId): array
    {
        $response = $this->http()->get("{$this->baseUrl}/v1/properties/{$propertyId}/payments");
        $this->assertOk($response, "getPaymentOptions:{$propertyId}");
        return $response->json() ?? [];
    }

    // =========================================================================
    // Availability - AUTHENTICATED (preferred)
    // =========================================================================

    /**
     * Authenticated availability for one property over a date range.
     *
     * VERIFY THIS ENDPOINT against https://docs.lodgify.com/reference/
     * The v2 availability path and response shape must be confirmed against
     * your account. Adjust normaliseAvailabilityPeriods() to match reality.
     *
     * @return array<int, array<string,mixed>> per-day rows in the same shape
     *         the public endpoint returns (date / isAvailable / minimalStay)
     */
    public function getAvailability(
        int|string $propertyId,
        string $startDate,
        string $endDate,
    ): array {
        $response = $this->http()->get(
            "{$this->baseUrl}/v2/availability/{$propertyId}",
            ['start' => $startDate, 'end' => $endDate]
        );

        $this->assertOk($response, "getAvailability:{$propertyId}");
        return $this->normaliseAvailabilityPeriods($response->json() ?? [], $startDate, $endDate);
    }

    /**
     * Convert Lodgify's period-based availability into per-day rows so the
     * rest of the app treats authenticated and public data identically.
     *
     * @param array<int, array<string,mixed>> $periods
     * @return array<int, array<string,mixed>>
     */
    /**
     * Convert Lodgify's availability response into per-day rows so the rest of
     * the app treats authenticated and public data identically.
     *
     * Lodgify returns a PROPERTY WRAPPER whose date ranges live one level down
     * in `periods`:
     *
     *   [ { "user_id":812665, "property_id":738423, "room_type_id":805539,
     *       "periods":[ { "start":"2026-08-13","end":"2026-08-14",
     *                     "available":0,"bookings":[{...}] }, ... ] } ]
     *
     * `available` is a UNIT COUNT: 0 = booked, >=1 = bookable.
     * Period ranges are treated as INCLUSIVE of both endpoints.
     *
     * @param array<int, mixed> $response raw decoded JSON
     * @return array<int, array<string,mixed>>
     */
    protected function normaliseAvailabilityPeriods(array $response, string $startDate, string $endDate): array
    {
        // ---- 1. flatten to a flat list of period rows -----------------------
        $periods = [];
        foreach ($response as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if (isset($entry['periods']) && is_array($entry['periods'])) {
                foreach ($entry['periods'] as $p) {
                    if (is_array($p)) {
                        $periods[] = $p;
                    }
                }
                continue;
            }
            // already-flat shape
            if (isset($entry['start']) || isset($entry['from'])) {
                $periods[] = $entry;
            }
        }

        if ($periods === []) {
            Log::warning('Lodgify availability returned no usable periods', [
                'top_level_keys' => array_keys($response[0] ?? []),
            ]);
            return [];   // signal "no data" so the caller can fall back
        }

        // ---- 2. seed every day in range as UNCOVERED ------------------------
        $byDate = [];
        $cursor = new \DateTimeImmutable($startDate);
        $end    = new \DateTimeImmutable($endDate);
        while ($cursor <= $end) {
            $byDate[$cursor->format('Y-m-d')] = [
                'date'                => $cursor->format('Y-m-d'),
                'isAvailable'         => false,
                'isCheckInAvailable'  => false,
                'isCheckOutAvailable' => false,
                'minimalStay'         => 1,
                'maximumStay'         => null,
                '_covered'            => false,
            ];
            $cursor = $cursor->modify('+1 day');
        }

        // ---- 3. apply each period ------------------------------------------
        foreach ($periods as $period) {
            $pStart = $period['start'] ?? $period['from'] ?? null;
            $pEnd   = $period['end']   ?? $period['to']   ?? $pStart;
            if (!$pStart) {
                continue;
            }

            $units   = (int) ($period['available'] ?? 0);
            $isFree  = $units >= 1;
            $minStay = (int) ($period['minimum_stay'] ?? $period['minimalStay'] ?? 0);

            try {
                $c = new \DateTimeImmutable($pStart);
                $e = new \DateTimeImmutable($pEnd);
            } catch (\Throwable) {
                continue;
            }

            while ($c <= $e) {
                $key = $c->format('Y-m-d');
                if (isset($byDate[$key])) {
                    $byDate[$key]['isAvailable']         = $isFree;
                    $byDate[$key]['isCheckInAvailable']  = $isFree;
                    $byDate[$key]['isCheckOutAvailable'] = $isFree;
                    $byDate[$key]['minimalStay']         = $minStay > 0 ? $minStay : 1;
                    $byDate[$key]['_covered']            = true;
                }
                $c = $c->modify('+1 day');
            }
        }

        // ---- 4. report coverage gaps ---------------------------------------
        $gaps = array_keys(array_filter($byDate, fn($d) => !$d['_covered']));
        if ($gaps !== []) {
            Log::info('Lodgify availability had uncovered days (treated as booked)', [
                'gap_count' => count($gaps),
                'first_gaps' => array_slice($gaps, 0, 5),
            ]);
        }

        return array_values($byDate);
    }

    /**
     * Rate SETTINGS for a property: the configured seasonal rate periods,
     * default rate, min-stay rules. Drives the "Seasonal rates" table.
     *
     * VERIFY path/shape against https://docs.lodgify.com/reference/
     */
    public function getRateSettings(int|string $propertyId): array
    {
        $response = $this->http()->get("{$this->baseUrl}/v2/rates/settings", [
            'houseId' => (string) $propertyId,
        ]);
        $this->assertOk($response, "getRateSettings:{$propertyId}");
        return $response->json() ?? [];
    }

    /**
     * Public per-property rate summary used by Lodgify's own frontend.
     * Contains defaultRate.dailyPrice + rates[] seasonal periods.
     * (This is the shape we captured from the browser network tab.)
     */
    public function getPublicRates(int|string $propertyId): array
    {
        $response = $this->publicHttp()->get(
            "{$this->checkoutUrl}/api/v1/checkout/{$propertyId}"
        );
        $this->assertOk($response, "getPublicRates:{$propertyId}");
        return $response->json() ?? [];
    }

    /**
     * Authenticated rates calendar - the only source of PER-DAY PRICES.
     *
     * NOTE ON PARAMETER NAMES: unlike most of the v2 API, Lodgify's rate
     * endpoints expect PascalCase query parameters, and this one rejects the
     * request with HTTP 400 "All fields are required" unless BOTH the house and
     * the room type are supplied. If Lodgify changes this, run
     * /debug/lodgify/probe/rates/{propertyId} to find the working combination
     * and set LODGIFY_RATES_PARAM_STYLE accordingly.
     */
    public function getRatesCalendar(
        int|string $propertyId,
        string $startDate,
        string $endDate,
        int|string|null $roomTypeId = null
    ): array {
        $style = (string) config('lodgify.rates_param_style', 'pascal');
        $query = $this->ratesCalendarQuery($style, $propertyId, $roomTypeId, $startDate, $endDate);

        $response = $this->http()->get("{$this->baseUrl}/v2/rates/calendar", $query);
        $this->assertOk($response, "getRatesCalendar:{$propertyId}");
        return $response->json() ?? [];
    }

    /**
     * The candidate query-parameter shapes for /v2/rates/calendar.
     * Kept in one place so the probe and the real call can never drift.
     *
     * @return array<string, string>
     */
    protected function ratesCalendarQuery(
        string $style,
        int|string $propertyId,
        int|string|null $roomTypeId,
        string $startDate,
        string $endDate
    ): array {
        return match ($style) {
            'pascal' => array_filter([
                'HouseId'    => (string) $propertyId,
                'RoomTypeId' => $roomTypeId !== null ? (string) $roomTypeId : null,
                'StartDate'  => $startDate,
                'EndDate'    => $endDate,
            ]),
            'pascal_property' => array_filter([
                'PropertyId' => (string) $propertyId,
                'RoomTypeId' => $roomTypeId !== null ? (string) $roomTypeId : null,
                'StartDate'  => $startDate,
                'EndDate'    => $endDate,
            ]),
            'snake' => array_filter([
                'house_id'     => (string) $propertyId,
                'room_type_id' => $roomTypeId !== null ? (string) $roomTypeId : null,
                'start_date'   => $startDate,
                'end_date'     => $endDate,
            ]),
            'camel' => array_filter([
                'houseId'    => (string) $propertyId,
                'roomTypeId' => $roomTypeId !== null ? (string) $roomTypeId : null,
                'startDate'  => $startDate,
                'endDate'    => $endDate,
            ]),
            'camel_property' => array_filter([
                'propertyId' => (string) $propertyId,
                'roomTypeId' => $roomTypeId !== null ? (string) $roomTypeId : null,
                'startDate'  => $startDate,
                'endDate'    => $endDate,
            ]),
            default => array_filter([
                'propertyId' => (string) $propertyId,
                'roomTypeId' => $roomTypeId !== null ? (string) $roomTypeId : null,
                'start'      => $startDate,
                'end'        => $endDate,
            ]),
        };
    }

    /**
     * Try every known parameter shape against /v2/rates/calendar and report
     * which ones succeed. Surfaced at /debug/lodgify/probe/rates/{id}.
     *
     * This exists because the endpoint returns a generic 400 rather than naming
     * the missing field, so probing is faster than guessing.
     *
     * @return array<int, array<string, mixed>>
     */
    public function probeRatesCalendar(
        int|string $propertyId,
        int|string|null $roomTypeId,
        string $startDate,
        string $endDate
    ): array {
        $styles = ['pascal', 'pascal_property', 'camel', 'camel_property', 'snake', 'current'];
        $results = [];

        foreach ($styles as $style) {
            // with room type
            $q = $this->ratesCalendarQuery($style, $propertyId, $roomTypeId, $startDate, $endDate);
            $r = $this->http()->get("{$this->baseUrl}/v2/rates/calendar", $q);
            $results[] = [
                'style'      => $style,
                'with_room'  => true,
                'params'     => array_keys($q),
                'url'        => "{$this->baseUrl}/v2/rates/calendar?" . http_build_query($q),
                'status'     => $r->status(),
                'ok'         => $r->successful(),
                'body'       => $r->successful()
                    ? mb_substr((string) json_encode($r->json()), 0, 900)
                    : mb_substr($r->body(), 0, 220),
            ];

            // without room type, to learn whether it is genuinely required
            $q2 = $this->ratesCalendarQuery($style, $propertyId, null, $startDate, $endDate);
            $r2 = $this->http()->get("{$this->baseUrl}/v2/rates/calendar", $q2);
            $results[] = [
                'style'      => $style,
                'with_room'  => false,
                'params'     => array_keys($q2),
                'status'     => $r2->status(),
                'ok'         => $r2->successful(),
                'body'       => $r2->successful()
                    ? mb_substr((string) json_encode($r2->json()), 0, 400)
                    : mb_substr($r2->body(), 0, 160),
            ];
        }

        return $results;
    }

    // =========================================================================
    // Availability - PUBLIC (fallback; Cloudflare-guarded)
    // =========================================================================

    /**
     * The endpoint Lodgify's own frontend uses. Works from a browser but sits
     * behind Cloudflare, so server-side calls need browser-like headers and
     * may still be 403'd. Prefer getAvailability().
     *
     * @return array<int, array<string, mixed>>
     */
    public function getPublicCalendar(
        int|string $propertyId,
        string $startDate,
        int|string|null $roomId = null
    ): array {
        $query = [
            'propertyId' => (string) $propertyId,
            'startDate'  => $startDate,
        ];
        if ($roomId !== null) {
            $query['roomId'] = (string) $roomId;
        }

        $response = $this->publicHttp()->get(
            "{$this->checkoutUrl}/api/v1/checkout/calendar",
            $query
        );

        $this->assertOk($response, "getPublicCalendar:{$propertyId}");
        $body = $response->json();
        return $body['calendar'] ?? [];
    }

    // =========================================================================
    // Pricing
    // =========================================================================

    /**
     * Authenticated quote — the authoritative price for a stay.
     *
     * PARAMETER SHAPE (verified by probing; this is not PHP bracket syntax):
     *   roomTypes[0].Id      REQUIRED. Omit it and Lodgify answers 400
     *                        "Missing Unit Ids".
     *   roomTypes[0].People  optional.
     *
     * Lodgify's API is ASP.NET, so a list of complex objects binds with DOT
     * notation. `roomTypes[0][id]` — the obvious PHP idiom — silently fails.
     *
     * Business-rule rejections also arrive as 400 with a useful message, e.g.
     * "The minimum stay for this rental is 6 days". Those are worth showing to
     * the guest verbatim, so LodgifyApiException carries the body through.
     */
    public function getQuote(
        int|string $propertyId,
        string $arrival,
        string $departure,
        int $adults = 2,
        int $children = 0,
        int $pets = 0,
        int|string|null $roomTypeId = null,
        array $addOnIds = [],
    ): array {
        $query = [
            'arrival'                   => $arrival,
            'departure'                 => $departure,
            'guest_breakdown[adults]'   => $adults,
            'guest_breakdown[children]' => $children,
            'guest_breakdown[pets]'     => $pets,
        ];

        if ($roomTypeId !== null) {
            $query['roomTypes[0].Id']     = (string) $roomTypeId;
            $query['roomTypes[0].People'] = $adults + $children;
        }

        // Add-ons participate in the quote (`add_ons` / `add_ons_subtotal`).
        foreach (array_values($addOnIds) as $i => $addOnId) {
            $query["addOns[{$i}].Id"] = (string) $addOnId;
        }

        $response = $this->http()->get("{$this->baseUrl}/v2/quote/{$propertyId}", $query);
        $this->assertOk($response, "getQuote:{$propertyId}");

        $json = $response->json() ?? [];
        // The endpoint returns a LIST with one quote per room-type combination.
        return array_is_list($json) ? ($json[0] ?? []) : $json;
    }

    public function getPublicCheckoutPrice(
        int|string $propertyId,
        string $arrival,
        string $departure,
        int $guests = 2,
        string $currency = 'CAD',
        string $language = 'EN'
    ): array {
        $response = $this->publicHttp()->get(
            "{$this->checkoutUrl}/api/v1/checkout/price",
            [
                'propertyId' => (string) $propertyId,
                'arrival'    => $arrival,
                'departure'  => $departure,
                'guests'     => $guests,
                'currency'   => $currency,
                'language'   => $language,
            ]
        );
        $this->assertOk($response, "getPublicCheckoutPrice:{$propertyId}");
        return $response->json() ?? [];
    }

    // =========================================================================
    // Bookings
    // =========================================================================

    /**
     * Writes get their own transport: longer timeout, and CRUCIALLY no retries.
     *
     * http() applies ->retry($this->retries, ...). Retrying a non-idempotent POST is how
     * you end up with two reservations for the same nights. Retry belongs at the job
     * level, guarded on bookings.lodgify_booking_id being null — see
     * App\Jobs\CreateLodgifyBooking.
     */
    protected function writeHttp(): PendingRequest
    {
        return Http::withHeaders([
            'X-ApiKey' => $this->apiKey,
            'Accept'   => 'application/json',
        ])->timeout((int) config('lodgify.write.timeout', 30))
          ->asJson();
    }

    /**
     * Create a reservation.
     *
     * PATH CORRECTED: this previously posted to /v2/reservations/bookings, which is the
     * v2 LIST endpoint, not a create endpoint. The documented create route is
     * POST /v1/reservation/booking. The old path had never failed in production only
     * because nothing ever called this method.
     *
     * The payload is built by LodgifyBookingWriter from a config-driven field map,
     * because the request-body field names are not verified against a live account.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createBooking(array $payload): array
    {
        $path = (string) config('lodgify.write.create_booking_path', '/v1/reservation/booking');

        $response = $this->writeHttp()->post("{$this->baseUrl}{$path}", $payload);
        $this->assertOk($response, 'createBooking');

        return $response->json() ?? [];
    }

    /**
     * Flip a reservation to Booked.
     *
     * Per Lodgify's docs this "changes the status of a booking to Booked and updates the
     * availability calendar accordingly" — i.e. THIS is the call that actually blocks the
     * dates. Until it succeeds the reservation is Open and the nights remain sellable.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function markBookingBooked(int|string $bookingId, array $payload = []): array
    {
        $path = str_replace(
            '{id}',
            rawurlencode((string) $bookingId),
            (string) config('lodgify.write.mark_booked_path', '/v1/reservation/booking/{id}/book')
        );

        $response = $this->writeHttp()->put("{$this->baseUrl}{$path}", $payload);
        $this->assertOk($response, "markBookingBooked:{$bookingId}");

        return $response->json() ?? [];
    }

    /**
     * Record a payment against a reservation.
     *
     * ⚠ No public endpoint for this has been confirmed. When
     * `lodgify.write.record_payment_path` is null we do not call anything — guessing a
     * URL and POSTing money-shaped data at it is worse than not trying. The caller
     * treats a null return as "not recorded" and surfaces it to an admin.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null  null when no endpoint is configured
     */
    public function recordBookingPayment(int|string $bookingId, array $payload): ?array
    {
        $configured = config('lodgify.write.record_payment_path');

        if (blank($configured)) {
            return null;
        }

        $path = str_replace('{id}', rawurlencode((string) $bookingId), (string) $configured);

        $response = $this->writeHttp()->post("{$this->baseUrl}{$path}", $payload);
        $this->assertOk($response, "recordBookingPayment:{$bookingId}");

        return $response->json() ?? [];
    }

    /**
     * Delete/release a reservation, used when an unpaid deposit link lapses so the
     * nights go back on sale.
     *
     * @return array<string, mixed>
     */
    public function deleteBooking(int|string $bookingId): array
    {
        $path = str_replace(
            '{id}',
            rawurlencode((string) $bookingId),
            (string) config('lodgify.write.delete_booking_path', '/v1/reservation/booking/{id}')
        );

        $response = $this->writeHttp()->delete("{$this->baseUrl}{$path}");
        $this->assertOk($response, "deleteBooking:{$bookingId}");

        return $response->json() ?? [];
    }

    /**
     * Discover the real create-booking contract without committing to a shape.
     *
     * Sends a series of candidate payloads and reports exactly what Lodgify says to each,
     * in the same spirit as probeRatesCalendar() and probeBookings(). Surfaced through
     * `php artisan lodgify:probe-booking-write`.
     *
     * ⚠ A SUCCESSFUL ATTEMPT CREATES A REAL RESERVATION. The caller is responsible for
     * requiring explicit confirmation and for telling the operator to delete what it
     * makes — every candidate is deliberately labelled as a test in the guest name.
     *
     * @param  array<string, mixed>  $base  identifying fields (property, room, dates)
     * @return array<int, array<string, mixed>>
     */
    public function probeBookingWrite(array $base): array
    {
        $path = (string) config('lodgify.write.create_booking_path', '/v1/reservation/booking');
        $url  = "{$this->baseUrl}{$path}";

        $guest = [
            'name'         => 'API PROBE — DELETE ME',
            'email'        => 'api-probe@example.invalid',
            'phone'        => '+10000000000',
            'country_code' => 'CA',
        ];

        $candidates = [
            'snake_case + nested guest' => [
                'property_id' => $base['property_id'],
                'arrival'     => $base['arrival'],
                'departure'   => $base['departure'],
                'status'      => 'Open',
                'source'      => 'Website',
                'guest'       => $guest,
                'rooms'       => [['room_type_id' => $base['room_type_id'], 'people' => 2]],
            ],
            'snake_case, flat guest' => [
                'property_id'  => $base['property_id'],
                'arrival'      => $base['arrival'],
                'departure'    => $base['departure'],
                'status'       => 'Open',
                'guest_name'   => $guest['name'],
                'guest_email'  => $guest['email'],
                'rooms'        => [['room_type_id' => $base['room_type_id'], 'people' => 2]],
            ],
            'PascalCase' => [
                'PropertyId' => $base['property_id'],
                'Arrival'    => $base['arrival'],
                'Departure'  => $base['departure'],
                'Status'     => 'Open',
                'Guest'      => [
                    'Name'  => $guest['name'],
                    'Email' => $guest['email'],
                ],
                'Rooms'      => [['RoomTypeId' => $base['room_type_id'], 'People' => 2]],
            ],
            'camelCase' => [
                'propertyId' => $base['property_id'],
                'arrival'    => $base['arrival'],
                'departure'  => $base['departure'],
                'status'     => 'Open',
                'guest'      => $guest,
                'rooms'      => [['roomTypeId' => $base['room_type_id'], 'people' => 2]],
            ],
            'houseId naming' => [
                'house_id'     => $base['property_id'],
                'room_type_id' => $base['room_type_id'],
                'arrival'      => $base['arrival'],
                'departure'    => $base['departure'],
                'status'       => 'Open',
                'guest'        => $guest,
            ],
        ];

        $results = [];

        foreach ($candidates as $label => $payload) {
            $response = $this->writeHttp()->post($url, $payload);
            $json     = $response->successful() ? $response->json() : null;

            $results[] = [
                'attempt'        => $label,
                'url'            => $url,
                'status'         => $response->status(),
                'ok'             => $response->successful(),
                'sent'           => $payload,
                // On success this is the created reservation — including its id, which
                // is what you need to delete it again.
                'response'       => $json,
                'created_id'     => is_array($json) ? ($json['id'] ?? $json['booking_id'] ?? null) : null,
                'body_excerpt'   => $response->successful() ? null : mb_substr($response->body(), 0, 400),
            ];

            // Stop at the first shape Lodgify accepts — no reason to create more.
            if ($response->successful()) {
                break;
            }
        }

        return $results;
    }

    public function getBooking(int|string $bookingId): array
    {
        $response = $this->http()->get("{$this->baseUrl}/v2/reservations/bookings/{$bookingId}");
        $this->assertOk($response, "getBooking:{$bookingId}");
        return $response->json() ?? [];
    }

    // =========================================================================
    // Diagnostics
    // =========================================================================

    /**
     * Probe every transport so you can see at a glance which works from THIS
     * server. Surfaced via /debug/lodgify. This is how you tell a Cloudflare
     * block apart from a bad API key apart from a wrong endpoint path.
     */
    public function diagnose(int|string $samplePropertyId): array
    {
        $out = [
            'has_api_key'  => $this->apiKey !== '',
            'base_url'     => $this->baseUrl,
            'checkout_url' => $this->checkoutUrl,
        ];

        $auth = $this->http()->get("{$this->baseUrl}/v2/properties", ['size' => 1]);
        $out['authenticated_properties'] = [
            'status' => $auth->status(),
            'ok'     => $auth->successful(),
            'body'   => $auth->successful() ? 'ok' : mb_substr($auth->body(), 0, 300),
        ];

        $pub = $this->publicHttp()->get("{$this->checkoutUrl}/api/v1/checkout/calendar", [
            'propertyId' => (string) $samplePropertyId,
            'startDate'  => now()->toDateString(),
        ]);
        $out['public_checkout_calendar'] = [
            'status' => $pub->status(),
            'ok'     => $pub->successful(),
            'server' => $pub->header('Server'),
            'cf_ray' => $pub->header('Cf-Ray'),
            'body'   => $pub->successful() ? 'ok' : mb_substr($pub->body(), 0, 300),
        ];

        $avail = $this->http()->get("{$this->baseUrl}/v2/availability/{$samplePropertyId}", [
            'start' => now()->toDateString(),
            'end'   => now()->addDays(30)->toDateString(),
        ]);
        $out['authenticated_availability'] = [
            'status' => $avail->status(),
            'ok'     => $avail->successful(),
            'body'   => $avail->successful()
                ? mb_substr((string) json_encode($avail->json()), 0, 500)
                : mb_substr($avail->body(), 0, 300),
        ];

        return $out;
    }

    // =========================================================================
    // Photo gallery (v3 API on property.lodgify.com)
    // =========================================================================

    /**
     * A property's FULL photo gallery.
     *
     * This lives on a DIFFERENT HOST and a DIFFERENT API VERSION from
     * everything else: property.lodgify.com/api/v3/... rather than
     * api.lodgify.com/v2/... . The v2 property endpoint only carries
     * `image_url` (the cover), so this is the only source for the gallery.
     *
     * Verified response shape:
     *   {
     *     "success": true, "statusCode": "OK",
     *     "data": {
     *       "defaultLanguage": "en",
     *       "property": {
     *         "id": 836351,
     *         "name": { "en": "Cottage1" },
     *         "isMultipleRoomType": false,
     *         "images": [ { "imageId": 15196129,
     *                       "id": "223b2f6f-a526-464c-b522-aebda7c7e8e3",
     *                       "orderNumber": 0, ... }, ... ]
     *       },
     *       "roomTypes": [ { "id": 903506, "name": {...}, "images": [] } ]
     *     }
     *   }
     *
     * `id` is the CDN asset id: https://l.icdbcdn.com/oh/{id}.png
     *
     * Tries the public transport first (browser-like headers, no key) and falls
     * back to the authenticated one, since it is not documented which this
     * endpoint expects.
     */
    public function getPropertyImages(int|string $propertyId): array
    {
        foreach ($this->v3ImageAttempts($propertyId) as $attempt) {
            if ($attempt['success']) {
                return $attempt['json'];
            }
        }
        Log::warning('Lodgify v3 images: no transport authorised', ['property' => $propertyId]);
        return [];
    }

    /**
     * Every way we know of to authenticate against the v3 images endpoint,
     * with the outcome of each. Used by getPropertyImages() and by
     * /debug/lodgify/probe/photos so failures are legible.
     *
     * CRITICAL: this endpoint returns HTTP 200 with `success: false` and
     * `statusCode: "HTTP_Unauthorized"` when the caller isn't authorised. A
     * plain `$response->successful()` check therefore reports a false positive,
     * which is why every attempt inspects the BODY as well as the status.
     *
     * @return array<int, array{label:string,status:int,success:bool,statusCode:?string,message:?string,json:array}>
     */
    public function v3ImageAttempts(int|string $propertyId): array
    {
        $url = "{$this->propertyUrl}/api/v3/property/{$propertyId}/images/all";

        $transports = [
            // 1. no credentials, browser-like headers
            'public' => fn() => $this->publicHttp()->get($url),

            // 2. the Public API key, in case v3 accepts it
            'api-key' => fn() => $this->publicHttp()
                ->withHeaders(['X-ApiKey' => $this->apiKey])
                ->get($url),

            // 3. API key as a bearer token
            'bearer-api-key' => fn() => $this->publicHttp()
                ->withToken($this->apiKey)
                ->get($url),
        ];

        // 4. a dashboard session cookie, if one has been supplied. See the
        //    LODGIFY_DASHBOARD_COOKIE note in config/lodgify.php before using
        //    this — it is a session credential, not an API credential.
        $cookie = (string) config('lodgify.dashboard_cookie', '');
        if ($cookie !== '') {
            $transports['session-cookie'] = fn() => $this->publicHttp()
                ->withHeaders(['Cookie' => $cookie])
                ->get($url);
        }

        $results = [];
        foreach ($transports as $label => $call) {
            try {
                $response = $call();
            } catch (\Throwable $e) {
                $results[] = [
                    'label'      => $label,
                    'status'     => 0,
                    'success'    => false,
                    'statusCode' => null,
                    'message'    => $e->getMessage(),
                    'json'       => [],
                ];
                continue;
            }

            $json = is_array($response->json()) ? $response->json() : [];

            // Body-level success is authoritative here, not the HTTP status.
            $ok = $response->successful()
                && ($json['success'] ?? false) === true
                && !empty($json['data']);

            $results[] = [
                'label'      => $label,
                'status'     => $response->status(),
                'success'    => $ok,
                'statusCode' => $json['statusCode'] ?? null,
                'message'    => $json['message'] ?? null,
                'json'       => $ok ? $json : [],
            ];

            if ($ok) {
                break;   // no point trying weaker credentials
            }
        }

        return $results;
    }

    /**
     * Fetch an arbitrary public Lodgify page as HTML    /**
     * Fetch an arbitrary public Lodgify page as HTML, with browser-like headers
     * so Cloudflare doesn't reject us. Used to harvest gallery images, which the
     * Public API does not expose.
     */
    public function fetchPublicPage(string $url): ?string
    {
        $response = $this->publicHttp()
            ->withHeaders(['Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'])
            ->get($url);

        if (!$response->successful()) {
            Log::warning('Lodgify public page returned non-200', [
                'url' => $url,
                'status' => $response->status(),
            ]);
            return null;
        }
        return $response->body();
    }

    /**
     * Probe every plausible endpoint for a property's FULL photo gallery.
     *
     * /v2/properties/{id} only returns `image_url` (the cover), so the gallery
     * must come from elsewhere. This tries the candidates and reports how many
     * image URLs each one yielded, so we can wire up the winner instead of
     * guessing. Surfaced at /debug/lodgify/probe/photos/{id}.
     *
     * @return array<int, array<string, mixed>>
     */
    public function probePhotos(int|string $propertyId, int|string|null $roomId = null): array
    {
        $b = $this->baseUrl;
        $c = $this->checkoutUrl;

        $candidates = [
            ['auth', "{$b}/v2/properties/{$propertyId}",                    []],
            ['auth', "{$b}/v2/properties/{$propertyId}",                    ['includeInOut' => 'true', 'includeImages' => 'true']],
            ['auth', "{$b}/v2/properties/{$propertyId}/images",             []],
            ['auth', "{$b}/v2/properties/{$propertyId}/photos",             []],
            ['auth', "{$b}/v2/properties/{$propertyId}/rooms",              []],
            ['auth', "{$b}/v2/properties",                                  ['includeInOut' => 'true']],
            ['auth', "{$b}/v1/properties/{$propertyId}",                    []],
            ['auth', "{$b}/v1/properties",                                  []],
            ['auth', "{$b}/v1/properties/{$propertyId}/images",             []],
            ['pub',  "{$c}/api/v1/checkout/{$propertyId}",                  []],
        ];

        if ($roomId !== null) {
            $candidates[] = ['auth', "{$b}/v2/properties/{$propertyId}/rooms/{$roomId}", []];
            $candidates[] = ['auth', "{$b}/v2/rooms/{$roomId}",                          []];
            $candidates[] = ['auth', "{$b}/v2/rooms/{$roomId}/images",                   []];
        }

        $results = [];
        foreach ($candidates as [$transport, $url, $query]) {
            $client = $transport === 'auth' ? $this->http() : $this->publicHttp();
            $r = $client->get($url, $query);

            $json   = $r->successful() ? $r->json() : null;
            $found  = is_array($json) ? $this->harvestImageUrls($json) : [];

            $results[] = [
                'transport'   => $transport === 'auth' ? 'authenticated' : 'public',
                'url'         => $url . ($query ? '?' . http_build_query($query) : ''),
                'status'      => $r->status(),
                'ok'          => $r->successful(),
                'image_count' => count($found),
                'images'      => array_slice($found, 0, 12),
                'top_level_keys' => is_array($json)
                    ? (array_is_list($json)
                        ? ['(list of ' . count($json) . ')'] + array_keys(is_array($json[0] ?? null) ? $json[0] : [])
                        : array_keys($json))
                    : null,
                'body_excerpt' => $r->successful() ? null : mb_substr($r->body(), 0, 200),
            ];
        }

        // the v3 gallery endpoint, every transport
        foreach ($this->v3ImageAttempts($propertyId) as $attempt) {
            $found = $attempt['success'] ? $this->harvestV3Ids($attempt['json']) : [];
            $results[] = [
                'transport'   => 'v3/' . $attempt['label'],
                'url'         => "{$this->propertyUrl}/api/v3/property/{$propertyId}/images/all",
                'status'      => $attempt['status'],
                'ok'          => $attempt['success'],
                'image_count' => count($found),
                'images'      => array_slice($found, 0, 12),
                'top_level_keys' => $attempt['success'] ? ['success', 'statusCode', 'data'] : null,
                'body_excerpt' => $attempt['success']
                    ? null
                    : trim(($attempt['statusCode'] ?? '') . ' ' . ($attempt['message'] ?? '')),
            ];
        }

        // best first
        usort($results, fn($a, $b) => $b['image_count'] <=> $a['image_count']);
        return $results;
    }

    /**
     * Asset ids out of a successful v3 gallery payload, for the probe output.
     *
     * @return string[]
     */
    public function harvestV3Ids(array $payload): array
    {
        $data = $payload['data'] ?? [];
        $ids  = [];
        foreach ((array) ($data['property']['images'] ?? []) as $img) {
            if (is_array($img) && !empty($img['id'])) {
                $ids[] = (string) $img['id'];
            }
        }
        foreach ((array) ($data['roomTypes'] ?? []) as $rt) {
            foreach ((array) ($rt['images'] ?? []) as $img) {
                if (is_array($img) && !empty($img['id'])) {
                    $ids[] = (string) $img['id'];
                }
            }
        }
        return array_values(array_unique($ids));
    }

    /**
     * Walk an arbitrary decoded JSON structure and collect anything that looks
     * like an image URL. Deliberately shape-agnostic — used by the probe so we
     * find images regardless of how a given endpoint nests them.
     *
     * @return string[]
     */
    public function harvestImageUrls(mixed $node, int $depth = 0): array
    {
        if ($depth > 8 || !is_array($node)) {
            return [];
        }

        $out = [];
        foreach ($node as $key => $value) {
            if (is_string($value)) {
                $looksLikeKey = is_string($key)
                    && preg_match('/(image|photo|picture|thumb|cover|url)/i', $key);
                $looksLikeUrl = (bool) preg_match('#(icdbcdn|lodgify).*\.(jpe?g|png|webp|avif)#i', $value)
                    || (bool) preg_match('#^(https?:)?//.+\.(jpe?g|png|webp|avif)(\?|$)#i', $value);
                if ($looksLikeUrl && ($looksLikeKey || str_contains($value, 'icdbcdn'))) {
                    $out[] = $value;
                }
                continue;
            }
            $out = array_merge($out, $this->harvestImageUrls($value, $depth + 1));
        }

        return array_values(array_unique($out));
    }

    /**
     * Return the RAW, unmapped JSON from any of the endpoints we use, so you
     * can inspect actual field names instead of trusting my guesses. Powers
     * the /debug/lodgify/raw/* routes.
     *
     * @return array{url:string,status:int,ok:bool,json:mixed,body_excerpt:string}
     */
    public function raw(string $what, int|string $id, array $extra = []): array
    {
        [$url, $query, $useAuth] = match ($what) {
            'properties'   => ["{$this->baseUrl}/v2/properties", ['includeInOut' => 'true'], true],
            'property'     => ["{$this->baseUrl}/v2/properties/{$id}", [], true],
            'rooms'        => ["{$this->baseUrl}/v2/properties/{$id}/rooms", [], true],
            'images-v3'    => ["{$this->propertyUrl}/api/v3/property/{$id}/images/all", [], false],
            'property-v1'  => ["{$this->baseUrl}/v1/properties/{$id}", [], true],
            'room-v1'      => ["{$this->baseUrl}/v1/properties/{$id}/rooms/" . ($extra['roomId'] ?? 0), [], true],
            'addons'       => ["{$this->baseUrl}/v1/properties/{$id}/rates/addons", [], true],
            'payments'     => ["{$this->baseUrl}/v1/properties/{$id}/payments", [], true],
            'availability' => ["{$this->baseUrl}/v2/availability/{$id}", [
                'start' => $extra['start'] ?? now()->toDateString(),
                'end'   => $extra['end']   ?? now()->addDays(30)->toDateString(),
            ], true],
            'rate-settings' => ["{$this->baseUrl}/v2/rates/settings", ['houseId' => (string) $id], true],
            'public-rates' => ["{$this->checkoutUrl}/api/v1/checkout/{$id}", [], false],
            'rates'        => [
                "{$this->baseUrl}/v2/rates/calendar",
                $this->ratesCalendarQuery(
                    (string) ($extra['style'] ?? config('lodgify.rates_param_style', 'pascal')),
                    $id,
                    $extra['roomTypeId'] ?? null,
                    $extra['start'] ?? now()->toDateString(),
                    $extra['end']   ?? now()->addDays(30)->toDateString(),
                ),
                true
            ],
            'quote'        => ["{$this->baseUrl}/v2/quote/{$id}", [
                'arrival'   => $extra['arrival']   ?? now()->addDays(30)->toDateString(),
                'departure' => $extra['departure'] ?? now()->addDays(32)->toDateString(),
                'guest_breakdown[adults]' => $extra['adults'] ?? 2,
            ], true],
            'calendar'     => ["{$this->checkoutUrl}/api/v1/checkout/calendar", array_filter([
                'propertyId' => (string) $id,
                'startDate'  => $extra['start'] ?? now()->toDateString(),
                'roomId'     => $extra['roomId'] ?? null,
            ]), false],
            'price'        => ["{$this->checkoutUrl}/api/v1/checkout/price", [
                'propertyId' => (string) $id,
                'arrival'    => $extra['arrival']   ?? now()->addDays(30)->toDateString(),
                'departure'  => $extra['departure'] ?? now()->addDays(32)->toDateString(),
                'guests'     => $extra['guests']    ?? 2,
                'currency'   => $extra['currency']  ?? 'CAD',
                'language'   => 'EN',
            ], false],
            default        => throw new \InvalidArgumentException("Unknown raw target: {$what}"),
        };

        $response = ($useAuth ? $this->http() : $this->publicHttp())->get($url, $query);

        return [
            'url'          => $url . (empty($query) ? '' : '?' . http_build_query($query)),
            'transport'    => $useAuth ? 'authenticated (X-ApiKey)' : 'public checkout',
            'status'       => $response->status(),
            'ok'           => $response->successful(),
            'json'         => $response->successful() ? $response->json() : null,
            'body_excerpt' => $response->successful() ? '' : mb_substr($response->body(), 0, 500),
        ];
    }

    public function ping(): array
    {
        $response = $this->http()->get("{$this->baseUrl}/v2/properties", ['size' => 1]);
        return [
            'ok'          => $response->successful(),
            'status'      => $response->status(),
            'base_url'    => $this->baseUrl,
            'has_api_key' => $this->apiKey !== '',
        ];
    }

    // =========================================================================

    protected function assertOk(Response $response, string $context): void
    {
        if ($response->successful()) {
            return;
        }
        Log::error("Lodgify API error [{$context}]", [
            'status' => $response->status(),
            'server' => $response->header('Server'),
            'cf_ray' => $response->header('Cf-Ray'),
            'body'   => mb_substr($response->body(), 0, 500),
        ]);
        throw new LodgifyApiException(
            "Lodgify [{$context}] failed: HTTP {$response->status()}",
            $response->status(),
            $response->body(),
        );
    }


// =========================================================================
// Reservations (read-only)
// =========================================================================

    /**
     * List bookings.
     *
     * DOCUMENTED PATH: GET /v2/reservations/bookings
     *
     * Query parameters below are BEST GUESSES based on Lodgify's conventions
     * elsewhere in v2. Confirm against docs.lodgify.com and adjust — recall that
     * /v2/rates/calendar wanted PascalCase while /v2/quote wanted ASP.NET dot
     * notation, so nothing here should be assumed.
     *
     * @param array<string, mixed> $filters
     */
    public function listBookings(array $filters = []): array
    {
        $query = array_filter([
            'page'           => $filters['page'] ?? 1,
            'size'           => $filters['size'] ?? 50,
            'includeCount'   => 'true',
            // Server-side filters, if supported. Anything unsupported is simply
            // ignored by Lodgify and filtered again on our side.
            'stayFilter'     => $filters['stay'] ?? null,        // Upcoming|Current|Historic|All
            'trash'          => 'false',
            'houseId'        => $filters['property_id'] ?? null,
            'updatedSince'   => $filters['updated_since'] ?? null,
        ], fn($v) => $v !== null && $v !== '');

        $response = $this->http()->get("{$this->baseUrl}/v2/reservations/bookings", $query);
        $this->assertOk($response, 'listBookings');

        return $response->json() ?? [];
    }


    /**
     * Discover the reservations endpoint's real shape and supported filters.
     *
     * Surfaced at /debug/lodgify/probe/bookings. Reports which parameter
     * combinations are accepted and what the top-level keys look like, so the
     * mapper can be written against reality rather than convention.
     *
     * @return array<int, array<string, mixed>>
     */
    public function probeBookings(): array
    {
        $url = "{$this->baseUrl}/v2/reservations/bookings";

        $attempts = [
            'bare'                 => [],
            'paged'                => ['page' => 1, 'size' => 5],
            'stayFilter=Upcoming'  => ['stayFilter' => 'Upcoming', 'size' => 5],
            'stayFilter=All'       => ['stayFilter' => 'All', 'size' => 5],
            'PascalCase paging'    => ['Page' => 1, 'Size' => 5],
            'includeCount'         => ['size' => 5, 'includeCount' => 'true'],
            'v1 path'              => null,   // handled below
        ];

        $results = [];

        foreach ($attempts as $label => $query) {
            $target = $label === 'v1 path'
                ? "{$this->baseUrl}/v1/reservation/booking"
                : $url;
            $query ??= [];

            $response = $this->http()->get($target, $query);
            $json = $response->successful() ? $response->json() : null;

            // Find the list, wherever it hides.
            $items = null;
            if (is_array($json)) {
                if (array_is_list($json)) {
                    $items = $json;
                } else {
                    foreach (['items', 'data', 'bookings', 'results'] as $key) {
                        if (isset($json[$key]) && is_array($json[$key])) {
                            $items = $json[$key];
                            break;
                        }
                    }
                }
            }

            $results[] = [
                'attempt'        => $label,
                'url'            => $target . ($query ? '?' . http_build_query($query) : ''),
                'status'         => $response->status(),
                'ok'             => $response->successful(),
                'top_level_keys' => is_array($json) && !array_is_list($json) ? array_keys($json) : null,
                'item_count'     => is_array($items) ? count($items) : null,
                // The single most useful thing: one real record, so field names
                // stop being guesswork.
                'first_item'     => is_array($items[0] ?? null) ? $items[0] : null,
                'body_excerpt'   => $response->successful() ? null : mb_substr($response->body(), 0, 250),
            ];
        }

        return $results;
    }
}
