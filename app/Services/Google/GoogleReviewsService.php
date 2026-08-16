<?php

namespace App\Services\Google;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Google reviews for the property.
 *
 * IMPORTANT — WHAT THIS CAN AND CANNOT DO
 *
 * There is no public, key-free Google reviews API. Scraping the Maps or Travel
 * page violates Google's Terms of Service and breaks whenever their markup
 * changes, so this uses the official Places API, which requires an API key.
 *
 * The Places API returns AT MOST 5 REVIEWS per place, chosen by Google. That is
 * a hard cap, not a paging limit — there is no parameter to get more. For the
 * full set you need the Google Business Profile API, which requires ownership
 * verification of the listing.
 *
 * So: this gives you the rating, the total review count, and up to five
 * reviews. When the Business Profile API is connected later, add a strategy
 * here and the view needs no changes.
 *
 * Setup:
 *   1. Google Cloud console -> enable "Places API (New)"
 *   2. Create an API key, restricted to that API and to your server's IP
 *   3. GOOGLE_MAPS_API_KEY=... and GOOGLE_PLACE_ID=... in .env
 *
 * Finding the place id: the id in your Travel URL (CgoIh6u565O9mIY6EAE) is a
 * Google Travel entity id, NOT a Places id. Use findPlaceId() below, or
 * https://developers.google.com/maps/documentation/places/web-service/place-id
 */
class GoogleReviewsService
{
    protected string $apiKey;
    protected ?string $placeId;

    public function __construct()
    {
        $this->apiKey  = (string) config('services.google.maps_key', '');
        $this->placeId = config('services.google.place_id');
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '' && !empty($this->placeId);
    }

    /**
     * Rating, total count, and up to five reviews.
     *
     * @return array{
     *   configured: bool, rating: ?float, total: ?int,
     *   reviews: array<int, array<string, mixed>>, url: ?string, error: ?string
     * }
     */
    public function fetch(): array
    {
        $empty = [
            'configured' => $this->isConfigured(),
            'rating'     => null,
            'total'      => null,
            'reviews'    => [],
            'url'        => null,
            'error'      => null,
        ];

        if (!$this->isConfigured()) {
            return $empty + ['error' => 'Google reviews are not configured yet.'];
        }

        return Cache::remember(
            'google:reviews:' . $this->placeId,
            (int) config('services.google.cache_ttl', 21600),   // 6h
            function () use ($empty) {
                try {
                    $response = Http::timeout(10)
                        ->withHeaders([
                            'X-Goog-Api-Key'   => $this->apiKey,
                            // Field mask is REQUIRED by Places API (New); asking
                            // for fewer fields is also cheaper per call.
                            'X-Goog-FieldMask' => implode(',', [
                                'id', 'displayName', 'rating', 'userRatingCount',
                                'googleMapsUri', 'reviews',
                            ]),
                        ])
                        ->get("https://places.googleapis.com/v1/places/{$this->placeId}");

                    if (!$response->successful()) {
                        Log::warning('Google Places reviews request failed', [
                            'status' => $response->status(),
                            'body'   => mb_substr($response->body(), 0, 300),
                        ]);
                        return $empty + ['error' => 'Could not load reviews right now.'];
                    }

                    $data = $response->json();

                    return [
                        'configured' => true,
                        'rating'     => isset($data['rating']) ? (float) $data['rating'] : null,
                        'total'      => isset($data['userRatingCount']) ? (int) $data['userRatingCount'] : null,
                        'url'        => $data['googleMapsUri'] ?? null,
                        'reviews'    => $this->mapReviews($data['reviews'] ?? []),
                        'error'      => null,
                    ];
                } catch (\Throwable $e) {
                    Log::error('Google reviews fetch threw', ['message' => $e->getMessage()]);
                    return $empty + ['error' => 'Could not load reviews right now.'];
                }
            }
        );
    }

    /**
     * @param array<int, array<string, mixed>> $reviews
     * @return array<int, array<string, mixed>>
     */
    protected function mapReviews(array $reviews): array
    {
        return collect($reviews)
            ->map(fn (array $r) => [
                'author'      => $r['authorAttribution']['displayName'] ?? 'A guest',
                'photo'       => $r['authorAttribution']['photoUri'] ?? null,
                'profile_url' => $r['authorAttribution']['uri'] ?? null,
                'rating'      => (int) ($r['rating'] ?? 0),
                'text'        => $r['originalText']['text'] ?? $r['text']['text'] ?? '',
                'relative'    => $r['relativePublishTimeDescription'] ?? null,
                'published'   => $r['publishTime'] ?? null,
                'url'         => $r['googleMapsUri'] ?? null,
            ])
            // A star rating with no words tells a reader nothing.
            ->filter(fn (array $r) => trim($r['text']) !== '')
            ->values()
            ->all();
    }

    /**
     * One-off helper to discover the Places id from a name and address.
     * Run it from tinker, put the result in .env, and forget about it.
     *
     *   app(App\Services\Google\GoogleReviewsService::class)
     *       ->findPlaceId('Ocean Escape Cottages, 1 Gull Rock Road, Lockeport NS');
     */
    public function findPlaceId(string $query): ?string
    {
        if ($this->apiKey === '') {
            return null;
        }

        $response = Http::timeout(10)
            ->withHeaders([
                'X-Goog-Api-Key'   => $this->apiKey,
                'X-Goog-FieldMask' => 'places.id,places.displayName,places.formattedAddress',
            ])
            ->post('https://places.googleapis.com/v1/places:searchText', [
                'textQuery' => $query,
            ]);

        if (!$response->successful()) {
            Log::warning('Place id lookup failed', ['body' => mb_substr($response->body(), 0, 300)]);
            return null;
        }

        return $response->json('places.0.id');
    }
}
