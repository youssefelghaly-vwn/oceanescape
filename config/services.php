<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'google' => [
        'maps_key'  => env('GOOGLE_MAPS_API_KEY'),
        'place_id'  => env('GOOGLE_PLACE_ID'),
        'cache_ttl' => (int) env('GOOGLE_REVIEWS_CACHE', 21600),
    ],

    /*
    |--------------------------------------------------------------------------
    | Stripe
    |--------------------------------------------------------------------------
    | We use HOSTED Stripe Checkout Sessions, not the embedded Payment Element.
    |
    | That choice is deliberate and worth keeping: card data never touches this server,
    | which keeps us in PCI SAQ A rather than SAQ A-EP. Lodgify's hosted checkout used to
    | keep us out of PCI scope by taking the payment for us; now that it is gone, hosted
    | Stripe Checkout is what preserves the same property.
    |
    | `webhook_secret` is the signing secret for the endpoint, NOT the API key.
    | Stripe issues a different one per endpoint; using the wrong value makes every
    | webhook fail signature verification and silently strands paid bookings.
    */
    'stripe' => [
        'key'    => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),

        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),

        /*
        | Reject webhooks whose signature timestamp is older than this, to bound
        | replay of a captured request. Stripe's own default is 300s.
        */
        'webhook_tolerance' => (int) env('STRIPE_WEBHOOK_TOLERANCE', 300),

        /*
        | Stripe API version.
        |
        | Null means "use whatever version stripe/stripe-php itself pins"
        | (\Stripe\Util\ApiVersion::CURRENT), which is the correct default: the SDK's
        | typed objects are written against that exact version, so overriding it with a
        | different string is how you get fields the SDK cannot deserialise.
        |
        | Only set STRIPE_API_VERSION to deliberately hold an older version during an
        | upgrade, and move it in step with the composer package — never independently.
        */
        'api_version' => env('STRIPE_API_VERSION'),
    ],

];
