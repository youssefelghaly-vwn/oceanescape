<?php

namespace Tests\Feature\Booking;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Lodgify's hosted checkout is gone, and stays gone.
 *
 * These assert absence rather than behaviour, which is unusual but warranted: "we removed
 * it" is easy to half-do and easy to undo by accident, and the failure mode is a guest
 * being sent off-site to pay through a system we no longer reconcile against.
 */
class NoLodgifyCheckoutTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Asserts on the FILE, not class_exists().
     *
     * class_exists() consults the composer classmap, which still lists a deleted file until
     * `composer dump-autoload` runs — so it errors on a missing include rather than
     * returning false. The file's absence is the real assertion anyway.
     */
    #[Test]
    public function the_hosted_checkout_classes_are_deleted(): void
    {
        foreach ([
            'app/Services/Lodgify/LodgifyCheckout.php',
            'app/Http/Controllers/BookingRedirectController.php',
            'app/Http/Controllers/Admin/CheckoutIntentController.php',
            'app/Models/CheckoutIntent.php',
            'resources/views/admin/checkouts/index.blade.php',
        ] as $path) {
            $this->assertFileDoesNotExist(base_path($path));
        }
    }

    #[Test]
    public function the_checkout_intents_table_is_gone(): void
    {
        // It existed only to track guests we had lost to the redirect.
        $this->assertFalse(Schema::hasTable('checkout_intents'));
        $this->assertFalse(Schema::hasColumn('bookings', 'checkout_intent_id'));
    }

    #[Test]
    public function no_named_route_points_at_the_old_flow(): void
    {
        foreach (['booking.redirect', 'admin.checkouts.index'] as $name) {
            $this->assertFalse(Route::has($name), "route {$name} should be gone");
        }
    }

    #[Test]
    public function the_feature_flag_is_gone(): void
    {
        // There is no "off" any more — this is the only booking path.
        $this->assertNull(config('booking.direct_payments_enabled'));
    }

    #[Test]
    public function the_hosted_checkout_settings_are_gone(): void
    {
        $this->assertNull(config('lodgify.checkout_slug'));
        $this->assertNull(config('lodgify.checkout_currency'));
        $this->assertNull(config('lodgify.checkout_grace_minutes'));
    }

    #[Test]
    public function the_public_read_fallback_host_is_deliberately_kept(): void
    {
        /*
         * Deliberate exception, and worth pinning so nobody "finishes the job" by deleting
         * it: checkout.lodgify.com also serves READ-ONLY calendar and price endpoints, used
         * server-to-server as a fallback when the authenticated v2 API fails. No guest is
         * ever sent there. Removing it would degrade availability and pricing, not payments.
         */
        $this->assertSame('https://checkout.lodgify.com', config('lodgify.checkout_base_url'));
    }

    #[Test]
    public function the_booking_flow_is_reachable_and_is_the_only_one(): void
    {
        $this->assertTrue(Route::has('booking.details'));
        $this->assertTrue(Route::has('booking.store'));
        $this->assertTrue(Route::has('booking.pay'));
        $this->assertTrue(Route::has('webhooks.stripe'));
    }
}
