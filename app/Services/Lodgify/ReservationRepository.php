<?php

namespace App\Services\Lodgify;

use App\DTO\Reservation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Read-only access to Lodgify reservations.
 *
 * NO LOCAL WRITES, deliberately. Lodgify is a channel manager: bookings arrive
 * from Airbnb, Booking.com, the phone and the website, and any local copy would
 * be stale the moment it was written. Everything here is fetched and cached for
 * a short TTL instead.
 *
 * ⚠ FIELD NAMES ARE UNVERIFIED. mapReservation() accepts several candidates per
 * field and keeps the raw payload alongside, so a wrong guess degrades to a
 * missing value rather than an exception. Run /debug/lodgify/probe/bookings and
 * correct the mapper against a real record.
 */
class ReservationRepository
{
    protected const CACHE_VERSION = 'v1';

    public function __construct(
        protected LodgifyClient $client,
        protected LodgifyRepository $properties,
    ) {}

    /**
     * Every reservation Lodgify will give us, paginated through and cached.
     *
     * Filtering happens in PHP rather than via query parameters, because we have
     * not confirmed which filters Lodgify honours. Six cottages produce a small
     * enough set for that to be fine; revisit if the volume grows.
     *
     * @return Collection<int, Reservation>
     */
    public function all(bool $fresh = false): Collection
    {
        $key = self::CACHE_VERSION . ':reservations:all';
        $ttl = (int) config('lodgify.cache.reservations', 300);

        if ($fresh) {
            Cache::forget($key);
        }

        $raw = Cache::remember($key, $ttl, function () {
            $items = [];
            $page = 1;

            do {
                try {
                    $payload = $this->client->listBookings(['page' => $page, 'size' => 50, 'stay' => 'All']);
                } catch (\Throwable $e) {
                    Log::error('Lodgify reservations fetch failed', [
                        'page' => $page, 'message' => $e->getMessage(),
                    ]);
                    break;
                }

                $batch = $this->extractItems($payload);
                if ($batch === []) {
                    break;
                }

                $items = array_merge($items, $batch);
                $page++;

                /*
                 * `count` comes back null, so there is no total to page against —
                 * we keep going while a full batch arrives and stop when a short
                 * one does.
                 */

                // Hard stop: a paging bug must not loop forever against a paid API.
                if ($page > 40) {
                    Log::warning('Reservation paging hit the safety limit');
                    break;
                }
            } while (count($batch) >= 50);

            return $items;
        });

        return collect($raw ?? [])
            ->map(fn ($r) => is_array($r) ? $this->mapReservation($r) : null)
            ->filter()
            // Lodgify keeps deleted bookings in the feed; they are not real.
            ->reject(fn (Reservation $r) => $r->isDeleted)
            ->sortByDesc(fn (Reservation $r) => $r->arrival?->timestamp ?? 0)
            ->values();
    }

    /** One reservation, by Lodgify id. */
    public function find(string $id): ?Reservation
    {
        $raw = Cache::remember(
            self::CACHE_VERSION . ":reservation:{$id}",
            (int) config('lodgify.cache.reservations', 300),
            function () use ($id) {
                try {
                    return $this->client->getBooking($id);
                } catch (\Throwable $e) {
                    Log::warning('Lodgify booking fetch failed', ['id' => $id, 'message' => $e->getMessage()]);
                    return null;
                }
            }
        );

        if (empty($raw)) {
            // Fall back to the list, in case the detail endpoint differs.
            return $this->all()->firstWhere('id', $id);
        }

        return $this->mapReservation($raw);
    }

    /**
     * Reservations belonging to an email address.
     *
     * ⚠ CALLERS MUST HAVE VERIFIED EMAIL OWNERSHIP FIRST. An email address is
     * not a secret, so matching on it alone would let anyone who signs up with a
     * guest's address read that guest's name, phone, dates and totals. The
     * profile controller gates this behind Laravel's email verification.
     *
     * @return Collection<int, Reservation>
     */
    public function forEmail(string $email): Collection
    {
        $needle = strtolower(trim($email));
        if ($needle === '') {
            return collect();
        }

        return $this->all()
            // Lodgify allows bookings with no email (phone reservations, some
            // channels). Those can never be matched to an account — no silent
            // partial matching, which could attach a stay to the wrong person.
            ->filter(fn (Reservation $r) => $r->isMatchable())
            ->filter(fn (Reservation $r) => strtolower($r->guestEmail) === $needle)
            ->values();
    }

    /**
     * Admin search across the fields someone would actually recall.
     *
     * @param array<string, mixed> $filters
     * @return Collection<int, Reservation>
     */
    public function search(array $filters = []): Collection
    {
        $results = $this->all(!empty($filters['fresh']));

        if ($term = trim((string) ($filters['q'] ?? ''))) {
            $needle = mb_strtolower($term);
            $results = $results->filter(function (Reservation $r) use ($needle) {
                foreach ([$r->id, $r->guestName, $r->guestEmail, $r->guestPhone, $r->propertyName] as $field) {
                    if ($field && str_contains(mb_strtolower((string) $field), $needle)) {
                        return true;
                    }
                }
                return false;
            });
        }

        // Email is called out separately because it is the most common lookup:
        // a guest phones up and gives their address, nothing else.
        if ($email = trim((string) ($filters['email'] ?? ''))) {
            $needle = mb_strtolower($email);
            $results = $results->filter(
                fn (Reservation $r) => str_contains(mb_strtolower((string) $r->guestEmail), $needle)
            );
        }

        if ($status = $filters['status'] ?? null) {
            $results = $results->filter(
                fn (Reservation $r) => strcasecmp((string) $r->status, $status) === 0
            );
        }

        if ($timeframe = $filters['timeframe'] ?? null) {
            if ($timeframe !== 'all') {
                $results = $results->filter(fn (Reservation $r) => $r->timeframe() === $timeframe);
            }
        }

        if ($propertyId = $filters['property_id'] ?? null) {
            $results = $results->filter(fn (Reservation $r) => (int) $r->propertyId === (int) $propertyId);
        }

        if ($source = $filters['source'] ?? null) {
            $results = $results->filter(
                fn (Reservation $r) => strcasecmp((string) $r->source, $source) === 0
            );
        }

        // Arrival window
        if ($from = $filters['from'] ?? null) {
            $results = $results->filter(
                fn (Reservation $r) => $r->arrival && $r->arrival->gte(Carbon::parse($from)->startOfDay())
            );
        }
        if ($to = $filters['to'] ?? null) {
            $results = $results->filter(
                fn (Reservation $r) => $r->arrival && $r->arrival->lte(Carbon::parse($to)->endOfDay())
            );
        }

        if (!empty($filters['unpaid'])) {
            $results = $results->filter(fn (Reservation $r) => ($r->amountDue ?? 0) > 0);
        }

        return $this->sort($results, $filters['sort'] ?? 'arrival', $filters['dir'] ?? 'desc');
    }

    /**
     * @param Collection<int, Reservation> $results
     * @return Collection<int, Reservation>
     */
    protected function sort(Collection $results, string $sort, string $dir): Collection
    {
        $key = match ($sort) {
            'guest'    => fn (Reservation $r) => mb_strtolower((string) $r->guestName),
            'property' => fn (Reservation $r) => mb_strtolower((string) $r->propertyName),
            'total'    => fn (Reservation $r) => $r->total ?? 0,
            'created'  => fn (Reservation $r) => $r->createdAt?->timestamp ?? 0,
            default    => fn (Reservation $r) => $r->arrival?->timestamp ?? 0,
        };

        return $dir === 'asc'
            ? $results->sortBy($key)->values()
            : $results->sortByDesc($key)->values();
    }

    /** Distinct values for the filter selects, derived from the data itself. */
    public function filterOptions(): array
    {
        $all = $this->all();

        return [
            'statuses'   => $all->pluck('status')->filter()->unique()->sort()->values()->all(),
            'sources'    => $all->pluck('source')->filter()->unique()->sort()->values()->all(),
            'properties' => $all->filter(fn (Reservation $r) => $r->propertyId)
                                ->mapWithKeys(fn (Reservation $r) => [$r->propertyId => $r->propertyName ?: ('Property ' . $r->propertyId)])
                                ->sort()
                                ->all(),
        ];
    }

    /** Headline counts for the admin index. */
    public function stats(): array
    {
        $all = $this->all();

        return [
            'total'    => $all->count(),
            'upcoming' => $all->filter(fn (Reservation $r) => $r->timeframe() === 'upcoming')->count(),
            'current'  => $all->filter(fn (Reservation $r) => $r->timeframe() === 'current')->count(),
            'past'     => $all->filter(fn (Reservation $r) => $r->timeframe() === 'past')->count(),
            'unpaid'   => $all->filter(fn (Reservation $r) => ($r->amountDue ?? 0) > 0)->count(),
        ];
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_VERSION . ':reservations:all');
    }

    // =====================================================================
    // Mapping
    // =====================================================================

    /** @return array<int, mixed> */
    protected function extractItems(array $payload): array
    {
        if (array_is_list($payload)) {
            return $payload;
        }
        foreach (['items', 'data', 'bookings', 'results'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                return $payload[$key];
            }
        }
        return [];
    }

    /**
     * Normalise one Lodgify booking.
     *
     * Written against a VERIFIED payload — see the Reservation DTO docblock for
     * the full shape. Three things that caught out the first draft:
     *
     *   1. `guest_breakdown` is nested inside rooms[0], NOT at the top level.
     *      Reading it from the root silently returned 0 for children, infants
     *      and pets on every booking.
     *   2. There is no reference / confirmation-code field at all.
     *   3. `guest.email` can be null, so a booking may be unmatchable to any
     *      user account.
     */
    protected function mapReservation(array $raw): ?Reservation
    {
        $id = $raw['id'] ?? null;
        if ($id === null) {
            Log::info('Reservation skipped: no id', ['keys' => array_keys($raw)]);
            return null;
        }

        $guest = is_array($raw['guest'] ?? null) ? $raw['guest'] : [];
        $rooms = collect($raw['rooms'] ?? [])->filter(fn ($r) => is_array($r))->values()->all();

        // Guest counts live on the ROOM, not the booking.
        $breakdown = is_array($rooms[0]['guest_breakdown'] ?? null)
            ? $rooms[0]['guest_breakdown']
            : [];

        $arrival   = $this->date($raw['arrival'] ?? null);
        $departure = $this->date($raw['departure'] ?? null);

        $propertyId = (int) ($raw['property_id'] ?? 0) ?: null;

        // Property names are not on the booking; fill from our cottage cache.
        $propertyName = null;
        if ($propertyId) {
            try {
                $propertyName = $this->properties->cottage($propertyId)?->name;
            } catch (\Throwable) {
                // cosmetic; the id is shown instead
            }
        }

        $subtotalsRaw = is_array($raw['subtotals'] ?? null) ? $raw['subtotals'] : [];
        $subtotals = [];
        foreach (['stay', 'promotions', 'fees', 'taxes', 'addons', 'vat'] as $key) {
            $subtotals[$key] = (float) ($subtotalsRaw[$key] ?? 0);
        }

        // Cancellation and payment wording sit under the attached quote.
        $policyRaw = is_array($raw['quote']['policy'] ?? null) ? $raw['quote']['policy'] : [];
        $policy = [
            'name'           => $this->cleanText($policyRaw['name'] ?? null),
            'payments'       => $this->cleanText($policyRaw['payments'] ?? null),
            'cancellation'   => $this->cleanText($policyRaw['cancellation'] ?? null),
            'damage_deposit' => $this->cleanText($policyRaw['damage_deposit'] ?? null),
        ];

        $total = $this->float($raw['total_amount'] ?? null);
        $paid  = $this->float($raw['amount_paid'] ?? null);
        $due   = $this->float($raw['amount_due'] ?? null);
        if ($due === null && $total !== null && $paid !== null) {
            $due = round($total - $paid, 2);
        }

        return new Reservation(
            id:     (string) $id,
            status: $this->nullIfBlank($raw['status'] ?? null),
            // source_text is usually empty; source carries the channel.
            source: $this->nullIfBlank($raw['source_text'] ?? null)
                    ?? $this->nullIfBlank($raw['source'] ?? null),

            propertyId:   $propertyId,
            propertyName: $propertyName,
            roomTypeId:   (int) ($rooms[0]['room_type_id'] ?? 0) ?: null,

            arrival:      $arrival,
            departure:    $departure,
            nights:       ($arrival && $departure) ? $arrival->diffInDays($departure) : null,
            checkInTime:  $this->nullIfBlank($raw['check_in']['time'] ?? null),
            checkOutTime: $this->nullIfBlank($raw['check_out']['time'] ?? null),

            guestName:    $this->nullIfBlank($guest['name'] ?? null),
            guestEmail:   $this->nullIfBlank($guest['email'] ?? null),
            guestPhone:   $this->nullIfBlank($guest['phone'] ?? null),
            guestCountry: $this->nullIfBlank($guest['country_code'] ?? null),

            adults:   (int) ($breakdown['adults'] ?? $rooms[0]['people'] ?? 0),
            children: (int) ($breakdown['children'] ?? 0),
            infants:  (int) ($breakdown['infants'] ?? 0),
            pets:     (int) ($breakdown['pets'] ?? 0),

            total:      $total,
            amountPaid: $paid,
            amountDue:  $due,
            currency:   $this->nullIfBlank($raw['currency_code'] ?? null) ?? 'CAD',

            subtotals: $subtotals,
            policy:    $policy,

            createdAt:  $this->date($raw['created_at'] ?? null),
            canceledAt: $this->date($raw['canceled_at'] ?? null),
            notes:      $this->nullIfBlank($raw['notes'] ?? null),
            isDeleted:  (bool) ($raw['is_deleted'] ?? false),

            rooms:    $rooms,
            // These are null on the list endpoint and populated on the detail
            // endpoint, so both shapes are tolerated.
            addOns:   collect($raw['quote']['addon_items'] ?? $raw['add_ons'] ?? [])
                        ->filter(fn ($a) => is_array($a))->values()->all(),
            payments: collect($raw['quote']['scheduled_transactions'] ?? $raw['transactions'] ?? [])
                        ->filter(fn ($p) => is_array($p))->values()->all(),

            raw: $raw,
        );
    }

    protected function nullIfBlank(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    /** Lodgify's policy text contains hard newlines mid-sentence. */
    protected function cleanText(mixed $value): ?string
    {
        $value = $this->nullIfBlank($value);
        return $value === null ? null : preg_replace('/\s*\n\s*/', ' ', $value);
    }

    /** First non-empty value among several candidate keys, dot notation allowed. */
    protected function firstOf(array $data, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = str_contains($key, '.') ? data_get($data, $key) : ($data[$key] ?? null);
            if (is_scalar($value) && trim((string) $value) !== '') {
                return (string) $value;
            }
        }
        return null;
    }

    protected function float(?string $value): ?float
    {
        return ($value === null || !is_numeric($value)) ? null : (float) $value;
    }

    protected function date(?string $value): ?Carbon
    {
        if ($value === null) {
            return null;
        }
        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}