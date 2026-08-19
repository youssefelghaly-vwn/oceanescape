<?php

/**
 * Add to config/services.php
 */

return [

    // ... existing services ...

    'google' => [
        /*
        | Places API (New) key. Restrict it in the Google Cloud console to the
        | Places API and to your server's IP — an unrestricted key on a public
        | site is a billing incident waiting to happen.
        */
        'maps_key' => env('GOOGLE_MAPS_API_KEY'),

        /*
        | The PLACES id, which is not the same as the entity id in a Google
        | Travel URL. Discover it once with:
        |   app(App\Services\Google\GoogleReviewsService::class)
        |       ->findPlaceId('Ocean Escape Cottages Lockeport NS');
        */
        'place_id' => env('GOOGLE_PLACE_ID'),

        'cache_ttl' => (int) env('GOOGLE_REVIEWS_CACHE', 21600),   // 6 hours

        // Words shown on a review card before "Read full review".
        'excerpt_words' => (int) env('GOOGLE_EXCERPT_WORDS', 38),

        // Place photos to pull. These are the LISTING's photos — the Places API
        // does not expose photos attached to individual reviews.
        'max_photos' => (int) env('GOOGLE_MAX_PHOTOS', 12),
    ],

];