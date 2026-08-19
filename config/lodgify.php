<?php

return [
    'api_key'  => env('LODGIFY_API_KEY'),
    'base_url' => env('LODGIFY_BASE_URL', 'https://api.lodgify.com'),

    // Public checkout endpoints (no API key, but behind Cloudflare).
    'checkout_base_url' => env('LODGIFY_CHECKOUT_URL', 'https://checkout.lodgify.com'),

    /*
    | The photo gallery lives on a SEPARATE HOST running a v3 API:
    |   GET property.lodgify.com/api/v3/property/{id}/images/all
    | The v2 property endpoint only returns `image_url` (the cover).
    */
    'property_base_url' => env('LODGIFY_PROPERTY_URL', 'https://property.lodgify.com'),

    /*
    | Add-ons live on yet another host. The documented endpoint
    | (/v1/properties/{id}/addons) returns an empty body even when add-ons are
    | configured; the data is at
    |   GET rates.lodgify.com/api/v2/rates/addons/property/{id}
    */
    'rates_base_url' => env('LODGIFY_RATES_URL', 'https://rates.lodgify.com'),

    /*
    | Add-on names and descriptions are localised under
    | translations.{lang}. This is the language to display, with a fallback to
    | whichever translation exists.
    */
    'addons_locale' => env('LODGIFY_ADDONS_LOCALE', 'en'),

    /*
    | Where add-ons come from, tried in order until one yields something.
    |
    |   api       rates.lodgify.com — session-locked, returns 401/403 for every
    |             API-key variant. Left in the chain so it lights up if Lodgify
    |             ever exposes a public route.
    |   manifest  config/lodgify-addons.php — mirrored by hand.
    */
    'addon_strategies' => explode(',', env('LODGIFY_ADDON_STRATEGIES', 'api,manifest')),
    /*
    | The working endpoint is GET /v1/properties/{id}/rates/addons — documented,
    | API-key authenticated. `manifest` stays in the chain only as an override
    | for extras you offer that Lodgify does not know about.
    */

    /*
    | The v3 images endpoint authenticates with a DASHBOARD SESSION, not the
    | Public API key: called without one it answers HTTP 200 with
    | { "success": false, "statusCode": "HTTP_Unauthorized" }.
    |
    | You CAN paste a session cookie here to make it work, but understand what
    | that means before you do:
    |   - it is your own login session, so it expires and must be re-pasted
    |   - it grants far more than read access to photos
    |   - it belongs in .env, never in version control
    | It is fine for a one-off harvest of image URLs. It is not something to
    | run a production site on — prefer the `local` or `manifest` strategy.
    |
    | Format: the full Cookie header copied from a dashboard request, e.g.
    |   LODGIFY_DASHBOARD_COOKIE="ARRAffinity=...; .AspNet.Cookies=..."
    */
    'dashboard_cookie' => env('LODGIFY_DASHBOARD_COOKIE', ''),

    /*
    | Sent as Referer/Origin on public checkout calls. Cloudflare is far more
    | likely to allow the request when it looks like it came from your site.
    */
    'public_site_origin' => env('LODGIFY_PUBLIC_SITE_ORIGIN', 'https://oceanescapecottages.ca'),

    /*
    | When true, availability is fetched from the AUTHENTICATED v2 endpoint
    | first and only falls back to the Cloudflare-guarded public endpoint if
    | that fails. Set false to skip straight to the public endpoint.
    */
    'prefer_authenticated_availability' => env('LODGIFY_PREFER_AUTH_AVAILABILITY', true),

    /*
    | Query-parameter naming style for /v2/rates/calendar. Lodgify's rate
    | endpoints use PascalCase, unlike the rest of the v2 API, and reject the
    | request with a generic HTTP 400 when a field is missing or misnamed.
    |
    | Run /debug/lodgify/probe/rates/{propertyId} to discover the working value.
    | Options: pascal, pascal_property, camel, camel_property, snake, current
    */
    'rates_param_style' => env('LODGIFY_RATES_PARAM_STYLE', 'pascal'),

    /*
    | Lodgify's /v2/properties LIST endpoint returns trimmed records, usually
    | without images. When true, any cottage missing an image or occupancy data
    | is topped up from /v2/properties/{id} (cached for property_detail TTL).
    */
    'hydrate_property_details' => env('LODGIFY_HYDRATE_DETAILS', true),

    /*
    | Merge /v1/properties/{id}/rooms/{roomId} into the property payload. This is
    | where the image gallery, amenities and `max_people` actually live — with it
    | off you get the cover image only and no occupancy cap.
    | Costs one extra cached call per cottage per property_detail TTL.
    */
    'merge_room_data' => env('LODGIFY_MERGE_ROOM_DATA', true),

    /*
    | Lodgify's CDN (l.icdbcdn.com) takes an `f=` transform preset. The API
    | hands back `f=32`, which is a thumbnail — too small for a hero image.
    | This value replaces it. Set to null to keep whatever Lodgify sent.
    |
    | Try a few values on a real URL before settling: some presets are named
    | rather than numeric, so verify the image actually loads.
    */
    'image_size_param' => env('LODGIFY_IMAGE_SIZE', null),

    /*
    | Preset used for full-screen lightbox images. Grid thumbnails can stay small,
    | but a lightbox needs the real thing. Verify the value renders before
    | shipping — the CDN silently returns the original for unknown presets.
    */
    'image_size_large' => env('LODGIFY_IMAGE_SIZE_LARGE', '1600'),

    /*
    |--------------------------------------------------------------------------
    | Photo gallery sources
    |--------------------------------------------------------------------------
    | RESOLVED: the full gallery comes from the DOCUMENTED Public API V1 room
    | endpoint, /v1/properties/{id}/rooms/{roomId}, which returns
    | [{ text, url }] — images WITH alt text. That is merged into the property
    | payload by LodgifyRepository::cottageRaw(), so the `api` strategy now
    | carries the whole gallery.
    |
    | Strategies are tried in order until one returns more than one image:
    |
    |   manifest  config/lodgify-images.php or storage/app/lodgify-images.json
    |   local     public/assets/cottages/{propertyId}/*.jpg (natural sort)
    |   api       the merged Lodgify payload — the full gallery  ← default source
    |
    | Also available but no longer needed:
    |   api_v3    property.lodgify.com/api/v3/... — session-authenticated, returns
    |             HTTP 200 with success:false unless you supply a dashboard cookie
    |   scrape    the public Lodgify-hosted rental page — pointless once the
    |             Lodgify website builder is retired, since there is no page left
    |
    | manifest and local stay first so you can override any cottage's gallery
    | with your own assets without touching code.
    */
    'image_strategies' => explode(',', env('LODGIFY_IMAGE_STRATEGIES', 'manifest,local,api')),

    /*
    | The v3 gallery gives an asset UUID rather than a full URL, so the CDN URL
    | is built as {image_cdn_base}/{uuid}.{ext}. Override the extension if your
    | photos are not PNG and the payload does not name the file.
    */
    'image_cdn_base'          => env('LODGIFY_IMAGE_CDN', 'https://l.icdbcdn.com/oh'),
    'image_default_extension' => env('LODGIFY_IMAGE_EXT', 'png'),

    'image_manifest_path' => env('LODGIFY_IMAGE_MANIFEST', 'lodgify-images.json'),

    'public_site_locale' => env('LODGIFY_PUBLIC_LOCALE', 'en'),

    /*
    | Per-property public page URL, for when the slug or host differs from the
    | derived one. Keyed by Lodgify property id.
    */
    'public_page_overrides' => [
        // 836351 => 'https://your-site.lodgify.com/en/cottage1',
    ],

    /*
    | How far either side of the requested dates to look when offering
    | alternative-length stays (tier 3 of search fallback).
    */
    'alternative_window_days' => (int) env('LODGIFY_ALTERNATIVE_WINDOW', 30),

    'timeout'        => (int) env('LODGIFY_TIMEOUT', 15),
    'retries'        => (int) env('LODGIFY_RETRIES', 2),
    'retry_delay_ms' => (int) env('LODGIFY_RETRY_DELAY_MS', 300),

    'cache' => [
        'properties_list' => (int) env('LODGIFY_CACHE_PROPERTIES', 3600),
        'property_detail' => (int) env('LODGIFY_CACHE_PROPERTY_DETAIL', 3600),
        'availability'    => (int) env('LODGIFY_CACHE_AVAILABILITY', 300),
        'quote'           => (int) env('LODGIFY_CACHE_QUOTE', 60),
        'rate_settings'   => (int) env('LODGIFY_CACHE_RATE_SETTINGS', 3600),
        'images'          => (int) env('LODGIFY_CACHE_IMAGES', 21600),
    ],

    'cache_tag' => 'lodgify',

    'availability_window_days' => (int) env('LODGIFY_AVAILABILITY_WINDOW', 90),
    'nearby_window_days'       => (int) env('LODGIFY_NEARBY_WINDOW', 14),
    'limited_threshold'        => (int) env('LODGIFY_LIMITED_THRESHOLD', 2),

    'checkout_slug' => env('LODGIFY_CHECKOUT_SLUG', 'scott-seely'),

    /*
    | Currency for the checkout. Leave as CAD: passing something else makes the
    | summary show a converted figure beside the rental's real price, which
    | confuses more than it helps.
    */
    'checkout_currency' => env('LODGIFY_CHECKOUT_CURRENCY', 'CAD'),

    /*
    | How long a redirect stays "in flight" before it counts as abandoned.
    | Lodgify's checkout is three steps, so allow a generous window.
    */
    'checkout_grace_minutes' => (int) env('LODGIFY_CHECKOUT_GRACE', 90),
    'reservations' => (int) env('LODGIFY_CACHE_RESERVATIONS', 300),

];
