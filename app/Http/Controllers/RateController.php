<?php

namespace App\Http\Controllers;

use App\Services\Lodgify\LodgifyRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class RateController extends Controller
{
    public function __construct(protected LodgifyRepository $lodgify) {}

    /**
     * Per-day price, availability AND STAY RULES for one cottage.
     *
     * The `rules` block is what lets the calendar enforce Lodgify's own
     * constraints in the UI (min/max stay, max occupancy) instead of letting a
     * guest build an invalid selection and only discovering it at quote time.
     *
     * GET /api/cottage/{slug}/rates?start=YYYY-MM-01&months=2
     */
    public function month(Request $request, string $slug): JsonResponse
    {
        $request->validate([
            'start'  => ['required', 'date_format:Y-m-d'],
            'months' => ['sometimes', 'integer', 'min:1', 'max:3'],
        ]);

        $cottage = $this->lodgify->cottageBySlug($slug);
        if (!$cottage) {
            throw new NotFoundHttpException("Cottage not found: {$slug}");
        }

        $start  = Carbon::parse($request->query('start'))->startOfMonth();
        $months = (int) $request->query('months', 2);
        $end    = $start->copy()->addMonths($months)->endOfMonth();

        $empty = [
            'cottage'  => $this->cottageMeta($cottage),
            'start'    => $start->toDateString(),
            'days'     => [],
            'rules'    => $this->rulesFor($cottage, []),
            'degraded' => true,
        ];

        try {
            $days     = $this->lodgify->rateCalendar($cottage, $start->toDateString(), $end->toDateString());
            $settings = $this->lodgify->rateSettings($cottage, $start->toDateString(), $end->toDateString());

            return response()->json([
                'cottage'  => $this->cottageMeta($cottage, $settings),
                'start'    => $start->toDateString(),
                'end'      => $end->toDateString(),
                'days'     => $days->map->toArray()->all(),
                'rules'    => $this->rulesFor($cottage, $settings),
                'degraded' => !empty($this->lodgify->lastErrors()),
                'notes'    => app()->environment(['local', 'staging']) ? $this->lodgify->lastErrors() : [],
            ])->header('Cache-Control', 'public, max-age=60');
        } catch (\Throwable $e) {
            Log::error('cottage rates month failed', ['slug' => $slug, 'message' => $e->getMessage()]);
            if (app()->environment(['local', 'staging'])) {
                $empty['notes'] = [$e->getMessage()];
            }
            return response()->json($empty, 200);
        }
    }

    /**
     * Live quote. Also returns the per-night SEGMENTS so the breakdown can show
     * "100 x 1 night" + "150 x 3 nights" rather than an averaged nightly rate.
     *
     * GET /api/cottage/{slug}/quote?arrival=&departure=&adults=&children=&pets=
     */
    public function quote(Request $request, string $slug): JsonResponse
    {
        $validated = $request->validate([
            'arrival'   => ['required', 'date_format:Y-m-d'],
            'departure' => ['required', 'date_format:Y-m-d', 'after:arrival'],
            'adults'    => ['sometimes', 'integer', 'min:1', 'max:30'],
            'children'  => ['sometimes', 'integer', 'min:0', 'max:30'],
            'pets'      => ['sometimes', 'integer', 'min:0', 'max:10'],
            'addons'    => ['sometimes', 'string'],
        ]);

        $cottage = $this->lodgify->cottageBySlug($slug);
        if (!$cottage) {
            throw new NotFoundHttpException("Cottage not found: {$slug}");
        }

        $arrival   = $validated['arrival'];
        $departure = $validated['departure'];
        $adults    = (int) ($validated['adults'] ?? 2);
        $children  = (int) ($validated['children'] ?? 0);
        $pets      = (int) ($validated['pets'] ?? 0);

        // "155523:1,155524:2" -> ['155523','155524']
        $addOnIds = collect(explode(',', (string) ($validated['addons'] ?? '')))
            ->map(fn ($p) => trim(explode(':', $p)[0] ?? ''))
            ->filter()
            ->values()
            ->all();

        if ($cottage->maxGuests > 0 && ($adults + $children) > $cottage->maxGuests) {
            return response()->json([
                'ok'         => false,
                'reason'     => 'occupancy',
                'message'    => "This cottage sleeps up to {$cottage->maxGuests} guests.",
                'max_guests' => $cottage->maxGuests,
            ], 200);
        }

        try {
            $segments = $this->nightlySegments($cottage, $arrival, $departure);
            $raw = $this->lodgify->quote($cottage->id, $arrival, $departure, $adults, $children, $pets, $addOnIds);

            if (!$raw) {
                /*
                 * Distinguish "Lodgify says no" from "our request failed".
                 * Lodgify's own wording ("The minimum stay for this rental is
                 * 6 days") tells the guest what to change; a generic
                 * "unavailable" hides a bug on our side behind a claim about
                 * the cottage.
                 */
                $guestMessage = $this->lodgify->lastGuestMessage();

                return response()->json([
                    'ok'       => false,
                    'reason'   => $guestMessage ? 'rejected' : 'error',
                    'message'  => $guestMessage
                        ?? 'We couldn\'t price those dates just now. Please try again or contact us.',
                    'segments' => $segments,
                ], 200);
            }

            $parsed = ($raw['_source'] ?? null) === 'v2'
                ? $this->parseV2Quote($raw, $cottage)
                : $this->parsePublicQuote($raw, $cottage);

            $parsed['ok']       = true;
            $parsed['segments'] = $segments;

            return response()->json($parsed)->header('Cache-Control', 'private, max-age=30');
        } catch (\Throwable $e) {
            Log::warning('cottage quote failed', ['slug' => $slug, 'message' => $e->getMessage()]);
            return response()->json([
                'ok'      => false,
                'reason'  => 'error',
                'message' => 'We couldn\'t price those dates just now. Please try again or contact us.',
            ], 200);
        }
    }

    /**
     * Parse the AUTHENTICATED /v2/quote payload.
     *
     * VERIFIED SHAPE — note it is a different world from the public endpoint:
     *   {
     *     "total_including_vat": 600.0, "currency_code": "USD",
     *     "room_types": [ { "room_type_id":903506, "subtotal":600.0,
     *       "price_types": [
     *         { "type":0, "description":"Room rate", "subtotal":600.0,
     *           "prices":[ {"description":"Cottage1","amount":600.0} ] },
     *         { "type":1, "description":"Promotion", "is_negative":true, ... },
     *         { "type":2, "description":"Fees",  ... },
     *         { "type":4, "description":"Taxes", ... }
     *       ] } ],
     *     "add_ons": [], "add_ons_subtotal": 0.0,
     *     "scheduled_payments": [ {"date_due":"On agreement","amount":300.0} ],
     *     "security_deposit": 0.0,
     *     "cancellation_policy_text": "...", "security_deposit_text": "..."
     *   }
     *
     * `type` is the grouping key: 0 room rate, 1 promotion, 2 fees, 4 taxes.
     *
     * @return array<string, mixed>
     */
    protected function parseV2Quote(array $raw, $cottage): array
    {
        $nights = Carbon::parse($raw['date_arrival'] ?? '')->diffInDays(
            Carbon::parse($raw['date_departure'] ?? '')
        ) ?: null;

        $rental = 0.0;
        $fees = [];
        $taxes = [];
        $promotions = [];

        foreach ((array) ($raw['room_types'] ?? []) as $roomType) {
            foreach ((array) ($roomType['price_types'] ?? []) as $group) {
                $type     = (int) ($group['type'] ?? -1);
                $negative = (bool) ($group['is_negative'] ?? false);

                foreach ((array) ($group['prices'] ?? []) as $line) {
                    $amount = (float) ($line['amount'] ?? 0);
                    if ($amount === 0.0) {
                        continue;
                    }
                    $entry = [
                        'name'  => $line['description'] ?? $group['description'] ?? 'Charge',
                        'value' => $negative ? -abs($amount) : $amount,
                    ];

                    match ($type) {
                        0       => $rental += $amount,
                        1       => $promotions[] = $entry,
                        2       => $fees[]       = $entry,
                        4       => $taxes[]      = $entry,
                        default => $fees[]       = $entry,
                    };
                }
            }
        }

        $addOns = collect((array) ($raw['add_ons'] ?? []))->map(fn ($a) => [
            'name'  => $a['description'] ?? $a['name'] ?? 'Extra',
            'value' => (float) ($a['amount'] ?? $a['subtotal'] ?? 0),
        ])->all();

        $total = (float) ($raw['total_including_vat'] ?? $raw['amount_gross'] ?? 0);

        return [
            'source'   => 'v2',
            'currency' => $raw['currency_code'] ?? $cottage->currency ?? 'USD',
            'nights'   => $nights,
            'nightly'  => $nights ? round($rental / $nights, 2) : null,
            'rental'   => $rental,
            'fees'     => $fees,
            'taxes'    => $taxes,
            'promotions' => $promotions,
            'lodgify_addons' => $addOns,
            'addons_subtotal' => (float) ($raw['add_ons_subtotal'] ?? 0),
            'total'    => $total,
            'due_now'  => collect((array) ($raw['scheduled_payments'] ?? []))
                            ->firstWhere('is_current', true)['amount'] ?? null,
            'schedule' => collect((array) ($raw['scheduled_payments'] ?? []))->map(fn ($p) => [
                'name'   => $p['date_due'] ?? 'Payment',
                'amount' => (float) ($p['amount'] ?? 0),
                'status' => $p['status'] ?? null,
            ])->all(),
            'security_deposit'      => (float) ($raw['security_deposit'] ?? 0),
            'security_deposit_text' => $raw['security_deposit_text'] ?? null,
            'cancellation_policy'   => $raw['cancellation_policy_text'] ?? null,
        ];
    }

    /**
     * Parse the PUBLIC checkout price payload (the fallback shape).
     *
     * @return array<string, mixed>
     */
    protected function parsePublicQuote(array $raw, $cottage): array
    {
        return [
            'source'   => 'public',
            'currency' => $raw['currencyCode'] ?? $cottage->currency ?? 'USD',
            'nights'   => data_get($raw, 'rentalPrice.nights'),
            'nightly'  => data_get($raw, 'rentalPrice.nightlyPrice'),
            'rental'   => data_get($raw, 'rentalPrice.total'),
            'fees'     => data_get($raw, 'fees.details', []),
            'taxes'    => data_get($raw, 'localTaxes.details', []),
            'promotions' => data_get($raw, 'rentalPrice.promotions', []),
            'lodgify_addons'  => [],
            'addons_subtotal' => 0.0,
            'total'    => data_get($raw, 'totalPrice.total'),
            'due_now'  => data_get($raw, 'totalPrice.amountToPay'),
            'schedule' => collect(data_get($raw, 'scheduledPayments.payments', []))->map(fn ($p) => [
                'name'   => $p['name'] ?? 'Payment',
                'amount' => (float) ($p['amount'] ?? 0),
                'status' => $p['status'] ?? null,
            ])->all(),
            'security_deposit'      => 0.0,
            'security_deposit_text' => null,
            'cancellation_policy'   => null,
        ];
    }

    /**
     * Add-ons available for a cottage.    /**
     * Add-ons available for a cottage.
     *
     * GET /api/cottage/{slug}/addons
     */
    public function addons(string $slug): JsonResponse
    {
        $cottage = $this->lodgify->cottageBySlug($slug);
        if (!$cottage) {
            throw new NotFoundHttpException("Cottage not found: {$slug}");
        }

        try {
            return response()->json([
                'currency' => $cottage->currency ?? 'USD',
                'addons'   => $this->lodgify->addons($cottage),
            ])->header('Cache-Control', 'public, max-age=300');
        } catch (\Throwable $e) {
            Log::warning('cottage addons failed', ['slug' => $slug, 'message' => $e->getMessage()]);
            return response()->json(['currency' => $cottage->currency ?? 'USD', 'addons' => []], 200);
        }
    }

    /**
     * Group the nights of a stay into runs that share the same nightly rate.
     *
     * Example for Aug 27 -> Aug 31 with a season starting Aug 28:
     *   [ {price:100, nights:1, start:'2026-08-27', end:'2026-08-27', subtotal:100},
     *     {price:150, nights:3, start:'2026-08-28', end:'2026-08-30', subtotal:450} ]
     *
     * @return array<int, array<string, mixed>>
     */
    protected function nightlySegments($cottage, string $arrival, string $departure): array
    {
        try {
            $days = $this->lodgify->rateCalendar($cottage, $arrival, $departure);
        } catch (\Throwable) {
            return [];
        }

        $segments = [];
        $cursor   = Carbon::parse($arrival);
        $end      = Carbon::parse($departure);   // departure night is not charged

        while ($cursor->lt($end)) {
            $d   = $cursor->toDateString();
            $day = $days->get($d);
            $price = $day?->price;

            $last = $segments === [] ? null : $segments[count($segments) - 1];

            if ($last !== null && $last['price'] === $price && $last['season'] === $day?->seasonName) {
                $segments[count($segments) - 1]['nights']++;
                $segments[count($segments) - 1]['end'] = $d;
                $segments[count($segments) - 1]['subtotal'] = $price === null
                    ? null
                    : round($price * $segments[count($segments) - 1]['nights'], 2);
            } else {
                $segments[] = [
                    'price'    => $price,
                    'season'   => $day?->seasonName,
                    'start'    => $d,
                    'end'      => $d,
                    'nights'   => 1,
                    'subtotal' => $price,
                ];
            }
            $cursor->addDay();
        }

        return $segments;
    }

    protected function cottageMeta($cottage, array $settings = []): array
    {
        return [
            'id'       => $cottage->id,
            'slug'     => $cottage->slug,
            'name'     => $cottage->name,
            'currency' => $settings['currency_code'] ?? $cottage->currency ?? 'USD',
        ];
    }

    /**
     * Booking constraints, all sourced from Lodgify — never hardcoded.
     */
    protected function rulesFor($cottage, array $settings): array
    {
        return [
            'max_guests'     => $cottage->maxGuests ?: null,
            'pets_allowed'   => $cottage->petFriendly,
            'check_in_hour'  => $settings['check_in_hour']  ?? null,
            'check_out_hour' => $settings['check_out_hour'] ?? null,
            'vat'            => $settings['vat'] ?? null,
            'vat_exclusive'  => $settings['is_vat_exclusive'] ?? null,
            'currency'       => $settings['currency_code'] ?? $cottage->currency ?? 'USD',
        ];
    }
}