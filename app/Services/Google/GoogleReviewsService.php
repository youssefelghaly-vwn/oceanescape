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

    /**
     * Which piece is missing, named specifically.
     *
     * "Not configured" sent me hunting in .env when the real problem was a
     * missing block in config/services.php. Say which.
     */
    public function configurationHint(): string
    {
        if ($this->apiKey === '' && empty($this->placeId)) {
            return 'Google reviews are not configured: services.google.maps_key and '
                 . 'services.google.place_id are both empty. If GOOGLE_MAPS_API_KEY is '
                 . 'set in .env, check that config/services.php has a `google` block.';
        }
        if ($this->apiKey === '') {
            return 'Google reviews are not configured: services.google.maps_key is empty.';
        }
        return 'Google reviews are not configured: services.google.place_id is empty.';
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
            'photos'     => [],
            'url'        => null,
            'error'      => null,
        ];

        if (!$this->isConfigured()) {
            /*
             * NOTE: use array_merge, not `+`.
             *
             * `$empty + ['error' => '...']` keeps the LEFT value when a key
             * exists in both arrays, and $empty already has 'error' => null.
             * That silently discarded every error message this class produced.
             */
            return array_merge($empty, [
                'error' => $this->configurationHint(),
            ]);
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
                                'googleMapsUri', 'reviews', 'photos',
                            ]),
                        ])
                        ->get("https://places.googleapis.com/v1/places/{$this->placeId}");

                    if (!$response->successful()) {
                        Log::warning('Google Places reviews request failed', [
                            'status' => $response->status(),
                            'body'   => mb_substr($response->body(), 0, 300),
                        ]);
                        return array_merge($empty, ['error' => 'Could not load reviews right now.']);
                    }

                    $data = $response->json();

                    return [
                        'configured' => true,
                        'rating'     => isset($data['rating']) ? (float) $data['rating'] : null,
                        'total'      => isset($data['userRatingCount']) ? (int) $data['userRatingCount'] : null,
                        'url'        => $data['googleMapsUri'] ?? null,
                        'reviews'    => $this->mapReviews($data['reviews'] ?? []),
                        'photos'     => $this->mapPhotos($data['photos'] ?? []),
                        'error'      => null,
                    ];
                } catch (\Throwable $e) {
                    Log::error('Google reviews fetch threw', ['message' => $e->getMessage()]);
                    return array_merge($empty, ['error' => 'Could not load reviews right now.']);
                }
            }
        );
    }

    /**
     * Resolve place photos into directly embeddable URLs.
     *
     * TWO THINGS WORTH KNOWING:
     *
     * 1. These are PLACE photos — the listing's photo set. The Places API does
     *    NOT return photos attached to individual reviews; that field does not
     *    exist. So these must not be presented as "this reviewer's photos".
     *
     * 2. A photo's `name` is not a URL. Fetching it needs the API key, and
     *    putting the key in an <img src> would both expose it and fail, since an
     *    IP-restricted key rejects browser requests. Instead we call the media
     *    endpoint server-side with `skipHttpRedirect=true`, which returns a
     *    plain lh3.googleusercontent.com URL that needs no key at all. Those are
     *    cached with the rest of the payload.
     *
     * @param array<int, array<string, mixed>> $photos
     * @return array<int, array<string, mixed>>
     */
    protected function mapPhotos(array $photos): array
    {
        $max = (int) config('services.google.max_photos', 12);

        return collect($photos)
            ->take($max)
            ->map(function (array $photo) {
                $name = $photo['name'] ?? null;
                if (!is_string($name)) {
                    return null;
                }

                $uri = $this->resolvePhotoUri($name);
                if ($uri === null) {
                    return null;
                }

                return [
                    'url'    => $uri,
                    'width'  => $photo['widthPx'] ?? null,
                    'height' => $photo['heightPx'] ?? null,
                    // Google requires attribution for place photos.
                    'author' => $photo['authorAttributions'][0]['displayName'] ?? null,
                    'author_url' => $photo['authorAttributions'][0]['uri'] ?? null,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    protected function resolvePhotoUri(string $photoName): ?string
    {
        return Cache::remember(
            'google:photo:' . md5($photoName),
            (int) config('services.google.cache_ttl', 21600),
            function () use ($photoName) {
                try {
                    $response = Http::timeout(10)->get(
                        "https://places.googleapis.com/v1/{$photoName}/media",
                        [
                            'key'              => $this->apiKey,
                            'maxWidthPx'       => 1600,
                            // Return JSON with the public URL instead of a 302,
                            // so no key ever reaches the browser.
                            'skipHttpRedirect' => 'true',
                        ]
                    );

                    return $response->successful()
                        ? $response->json('photoUri')
                        : null;
                } catch (\Throwable $e) {
                    Log::warning('Google place photo resolve failed', [
                        'photo' => $photoName, 'message' => $e->getMessage(),
                    ]);
                    return null;
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
            ->map(function (array $r) {
                $text  = $r['originalText']['text'] ?? $r['text']['text'] ?? '';
                $words = (int) config('services.google.excerpt_words', 38);

                return [
                    'author'      => $r['authorAttribution']['displayName'] ?? 'A guest',
                    'photo'       => $r['authorAttribution']['photoUri'] ?? null,
                    'profile_url' => $r['authorAttribution']['uri'] ?? null,
                    'rating'      => (int) ($r['rating'] ?? 0),
                    'text'        => $text,
                    // Trimmed on the server so the card markup stays simple and
                    // the full text is still available for the modal.
                    'excerpt'     => \Illuminate\Support\Str::words($text, $words, '…'),
                    'truncated'   => str_word_count($text) > $words,
                    'relative'    => $r['relativePublishTimeDescription'] ?? null,
                    'published'   => $r['publishTime'] ?? null,
                    'url'         => $r['googleMapsUri'] ?? null,
                ];
            })
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