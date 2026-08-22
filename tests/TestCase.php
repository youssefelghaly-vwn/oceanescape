<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        /*
         * Fake Stripe credentials — never real ones, and never a live key in a test run.
         * (There is no direct-payments flag any more: Lodgify's hosted checkout has been
         * removed, so this is the only booking path there is.)
         */
        config()->set('services.stripe.secret', 'sk_test_fake_for_tests');
        config()->set('services.stripe.webhook_secret', 'whsec_fake_for_tests');
        config()->set('booking.alert_email', 'ops@example.test');

        /*
         * Views use @vite(), which needs a built public/build/manifest.json. Tests assert
         * on rendered copy, not on asset bundling, so stub Vite out rather than requiring
         * `npm run build` before the suite can pass.
         */
        $this->withoutVite();
    }
}
