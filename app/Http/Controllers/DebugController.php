<?php

namespace App\Http\Controllers;

use App\Services\Lodgify\LodgifyClient;
use App\Services\Lodgify\LodgifyRepository;
use App\Services\Lodgify\PropertyImageResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * NOT for production. Gate behind auth or remove before shipping.
 *
 * Routes:
 *   /debug/lodgify                       transport health + mapped cottages
 *   /debug/lodgify/raw/{what}/{id?}      RAW unmapped Lodgify JSON
 *   /debug/lodgify/why?arrival=&departure=  per-cottage per-night explain
 *   /debug/lodgify/flush                 clear cached Lodgify data
 */
class DebugController extends Controller
{
    protected const JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    public function lodgify(Request $request, LodgifyClient $client, LodgifyRepository $repo): JsonResponse
    {
        $sampleId = (int) $request->query('property', 738423);

        $out = ['diagnose' => $client->diagnose($sampleId)];

        try {
            $out['cottages'] = $repo->allCottages()->map(fn ($c) => [
                'id' => $c->id,
                'slug' => $c->slug,
                'name' => $c->name,
                'room_ids' => array_column($c->rooms, 'id'),
                'currency' => $c->currency,
                'max_guests' => $c->maxGuests,
                'bedrooms' => $c->bedrooms,
                'pet_friendly' => $c->petFriendly,
                'hero_image' => $c->heroImage,
            ])->all();
        } catch (\Throwable $e) {
            $out['cottages'] = ['error' => $e->getMessage()];
        }

        /*
         * Ids the LIST endpoint advertises but the DETAIL endpoint 404s. Surfaced here so
         * they can be cleaned up in Lodgify rather than inferred from the log.
         */
        $out['phantom_properties'] = $repo->phantomProperties();

        try {
            $days = $repo->aggregateAvailability(now()->startOfMonth()->toDateString());
            $out['aggregate_count'] = $days->count();
            $out['aggregate_errors'] = $repo->lastErrors();
            $out['aggregate_summary'] = [
                'fully_booked_days' => $days->filter->isFullyBooked()->count(),
                'limited_days' => $days->filter(fn ($d) => $d->isLimited())->count(),
                'open_days' => $days->filter(fn ($d) => $d->availableCount > 0)->count(),
            ];
            $out['aggregate_sample'] = $days->take(10)->map->toArray()->all();
        } catch (\Throwable $e) {
            $out['aggregate_sample'] = ['error' => $e->getMessage()];
        }

        return response()->json($out, 200, [], self::JSON_FLAGS);
    }

    /**
     * Raw, unmapped Lodgify JSON so you can read the real field names.
     *
     * GET /debug/lodgify/raw/properties
     * GET /debug/lodgify/raw/property/738423
     * GET /debug/lodgify/raw/availability/738423?start=2026-09-01&end=2026-09-30
     * GET /debug/lodgify/raw/calendar/738423?start=2026-09-01&roomId=805539
     * GET /debug/lodgify/raw/rates/738423?start=2026-09-01&end=2026-09-30
     * GET /debug/lodgify/raw/quote/738423?arrival=2026-09-07&departure=2026-09-09
     * GET /debug/lodgify/raw/price/738423?arrival=2026-09-07&departure=2026-09-09
     */
    public function raw(Request $request, LodgifyClient $client, string $what, ?int $id = null): JsonResponse
    {
        $allowed = ['properties', 'property', 'property-v1', 'rooms', 'room-v1', 'addons', 'addons-v1', 'payments', 'images-v3', 'availability', 'calendar', 'rates', 'rate-settings', 'public-rates', 'quote', 'price'];
        if (! in_array($what, $allowed, true)) {
            return response()->json([
                'error' => "Unknown target '{$what}'",
                'allowed' => $allowed,
            ], 422, [], self::JSON_FLAGS);
        }

        try {
            $result = $client->raw($what, $id ?? 0, $request->query());
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500, [], self::JSON_FLAGS);
        }

        // Surface the top-level keys prominently — that is usually what you
        // actually need when reconciling my mapper against reality.
        $json = $result['json'];
        $result['shape'] = [
            'type' => is_array($json) ? (array_is_list($json) ? 'list' : 'object') : gettype($json),
            'count' => is_array($json) ? count($json) : null,
            'top_level_keys' => is_array($json)
                ? (array_is_list($json)
                    ? array_keys(is_array($json[0] ?? null) ? $json[0] : [])
                    : array_keys($json))
                : null,
        ];

        return response()->json($result, 200, [], self::JSON_FLAGS);
    }

    /**
     * Explain, cottage by cottage and night by night, why a search returns
     * what it returns.
     *
     * GET /debug/lodgify/why?arrival=2026-09-07&departure=2026-09-09
     */
    public function why(Request $request, LodgifyRepository $repo): JsonResponse
    {
        $arrival = (string) $request->query('arrival', now()->addDays(30)->toDateString());
        $departure = (string) $request->query('departure', now()->addDays(32)->toDateString());

        $explain = $repo->explainAvailability($arrival, $departure);

        return response()->json([
            'arrival' => $arrival,
            'departure' => $departure,
            'bookable_now' => collect($explain)->where('bookable', true)->pluck('name')->values(),
            'errors' => $repo->lastErrors(),
            'per_cottage' => $explain,
        ], 200, [], self::JSON_FLAGS);
    }

    /**
     * Probe /v2/rates/calendar with every known parameter shape.
     *
     * GET /debug/lodgify/probe/rates/738423
     * GET /debug/lodgify/probe/rates/738423?roomTypeId=805539&start=2026-09-01&end=2026-10-31
     *
     * Look for the row with ok:true — its `style` is what belongs in
     * LODGIFY_RATES_PARAM_STYLE.
     */
    public function probeRates(
        Request $request,
        LodgifyClient $client,
        LodgifyRepository $repo,
        int $id
    ): JsonResponse {
        $roomTypeId = $request->query('roomTypeId');
        if ($roomTypeId === null) {
            // default to the cottage's primary room type
            $roomTypeId = $repo->cottage($id)?->primaryRoomId();
        }

        $start = (string) $request->query('start', now()->toDateString());
        $end = (string) $request->query('end', now()->addDays(60)->toDateString());

        $results = $client->probeRatesCalendar($id, $roomTypeId, $start, $end);

        $working = collect($results)->where('ok', true)->values();

        return response()->json([
            'property_id' => $id,
            'room_type_id' => $roomTypeId,
            'range' => [$start, $end],
            'working_styles' => $working->map(fn ($r) => [
                'style' => $r['style'],
                'with_room' => $r['with_room'],
                'params' => $r['params'],
            ])->all(),
            'recommendation' => $working->isEmpty()
                ? 'No parameter shape worked — check the endpoint path against docs.lodgify.com/reference/'
                : 'Set LODGIFY_RATES_PARAM_STYLE='.$working->first()['style'],
            'all_attempts' => $results,
        ], 200, [], self::JSON_FLAGS);
    }

    /**
     * Find which endpoint returns a property's FULL photo gallery.
     *
     * GET /debug/lodgify/probe/photos/836351
     *
     * Read `results[0]` — highest image_count wins. Send me that entry's `url`
     * and `top_level_keys` and the mapper can be pointed at it.
     */
    public function probePhotos(
        Request $request,
        LodgifyClient $client,
        LodgifyRepository $repo,
        int $id
    ): JsonResponse {
        $roomId = $request->query('roomId') ?? $repo->cottage($id)?->primaryRoomId();

        $results = $client->probePhotos($id, $roomId);
        $best = $results[0] ?? null;

        return response()->json([
            'property_id' => $id,
            'room_id' => $roomId,
            'best_source' => $best && $best['image_count'] > 1
                ? ['url' => $best['url'], 'count' => $best['image_count']]
                : null,
            'recommendation' => $best && $best['image_count'] > 1
                ? "Gallery found at {$best['url']} ({$best['image_count']} images)"
                : 'No endpoint returned more than the cover image. The gallery may not be exposed by the API on this plan.',
            'results' => $results,
        ], 200, [], self::JSON_FLAGS);
    }

    /**
     * Show what EVERY image strategy returns for a property, so you can see
     * which source is actually feeding the gallery.
     *
     * GET /debug/lodgify/images/836351
     */
    public function images(
        LodgifyRepository $repo,
        PropertyImageResolver $resolver,
        LodgifyClient $client,
        int $id
    ): JsonResponse {
        $cottage = $repo->cottage($id);
        if (! $cottage) {
            return response()->json(['error' => "Cottage {$id} not found"], 404, [], self::JSON_FLAGS);
        }

        // Pass the cottage's own images as the `api` baseline, otherwise that
        // row reads 0 while resolved_count reads 1, which is confusing.
        $apiImages = $cottage->heroImage ? [$cottage->heroImage] : [];
        $byStrategy = $resolver->resolveWithSource($id, $cottage->slug, $apiImages);
        $winner = collect($byStrategy)->filter(fn ($s) => $s['count'] > 1)->keys()->first();

        return response()->json([
            'property_id' => $id,
            'slug' => $cottage->slug,
            'configured_order' => config('lodgify.image_strategies'),
            'active_source' => $winner ?? 'api (cover only)',
            'v3_attempts' => collect($client->v3ImageAttempts($id))->map(fn ($a) => [
                'transport' => $a['label'],
                'status' => $a['status'],
                'success' => $a['success'],
                'statusCode' => $a['statusCode'],
                'message' => $a['message'],
            ])->all(),
            'resolved_count' => count($cottage->images),
            'resolved' => $cottage->images,
            'by_strategy' => $byStrategy,
        ], 200, [], self::JSON_FLAGS);
    }

    public function flush(LodgifyRepository $repo): JsonResponse
    {
        $repo->flushCache();

        return response()->json(['flushed' => true], 200, [], self::JSON_FLAGS);
    }
}
