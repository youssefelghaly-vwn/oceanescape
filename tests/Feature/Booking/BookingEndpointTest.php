<?php

namespace Tests\Feature\Booking;

use App\Models\Booking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Validation and guards on the public POST /booking endpoint. */
class BookingEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function valid(array $overrides = []): array
    {
        $arrival = now()->addDays(60);

        return array_merge([
            'slug' => 'sea-glass-738423',
            'arrival' => $arrival->toDateString(),
            'departure' => $arrival->copy()->addDays(3)->toDateString(),
            'adults' => 2,
            'guest_name' => 'Alex Morgan',
            'guest_email' => 'alex@example.com',
            'guest_phone' => '+19025551234',
            'terms_accepted' => 1,
        ], $overrides);
    }

    #[Test]
    public function the_honeypot_blocks_a_bot(): void
    {
        $this->post('/booking', $this->valid(['website_url' => 'http://spam.example']))
            ->assertSessionHasErrors('website_url');

        $this->assertSame(0, Booking::count());
    }

    #[Test]
    public function terms_must_be_accepted(): void
    {
        // We are creating a reservation in someone's name; that needs a recorded yes.
        $this->post('/booking', $this->valid(['terms_accepted' => 0]))
            ->assertSessionHasErrors('terms_accepted');

        $this->assertSame(0, Booking::count());
    }

    #[Test]
    public function it_rejects_a_departure_before_arrival(): void
    {
        $this->post('/booking', $this->valid([
            'arrival' => now()->addDays(30)->toDateString(),
            'departure' => now()->addDays(29)->toDateString(),
        ]))->assertSessionHasErrors('departure');
    }

    #[Test]
    public function it_rejects_a_past_arrival(): void
    {
        $this->post('/booking', $this->valid([
            'arrival' => now()->subDay()->toDateString(),
            'departure' => now()->addDays(3)->toDateString(),
        ]))->assertSessionHasErrors('arrival');
    }

    #[Test]
    public function it_rejects_an_absurdly_long_stay(): void
    {
        $this->post('/booking', $this->valid([
            'arrival' => now()->addDays(10)->toDateString(),
            'departure' => now()->addDays(200)->toDateString(),
        ]))->assertSessionHasErrors('departure');
    }

    #[Test]
    public function contact_details_are_required(): void
    {
        $this->post('/booking', [
            'slug' => 'x', 'arrival' => now()->addDays(10)->toDateString(),
            'departure' => now()->addDays(12)->toDateString(), 'adults' => 2,
            'terms_accepted' => 1,
        ])->assertSessionHasErrors(['guest_name', 'guest_email', 'guest_phone']);
    }

    #[Test]
    public function the_lodgify_hosted_checkout_route_no_longer_exists(): void
    {
        /*
         * Lodgify's hosted checkout has been removed from the project entirely: no
         * /book/{slug} redirect, no feature flag, no fallback. This asserts the route is
         * genuinely gone rather than merely unused, so it cannot be reintroduced by
         * accident.
         */
        $this->get('/book/sea-glass-738423?arrival=2026-11-02&departure=2026-11-05')
            ->assertNotFound();

        $this->assertFalse(
            Route::has('booking.redirect'),
            'the booking.redirect route should not be registered'
        );
    }

    #[Test]
    public function no_route_sends_a_guest_to_a_lodgify_domain(): void
    {
        // The whole point of the change: a guest never leaves our domain to pay.
        foreach (Route::getRoutes() as $route) {
            $this->assertStringNotContainsString(
                'lodgify.com',
                $route->uri(),
                "route {$route->uri()} references a Lodgify domain"
            );
        }
    }

    #[Test]
    public function a_failed_submission_lands_back_on_the_details_form(): void
    {
        /*
         * The POST has nowhere sensible to fall back to on its own: a bare back() depends
         * on the Referer header and would bounce to "/" without it, silently swallowing the
         * errors. The redirect target is therefore explicit.
         */
        $response = $this->post('/booking', $this->valid(['guest_email' => 'not-an-email']));

        $response->assertRedirectContains('/booking/details/sea-glass-738423');
        $response->assertSessionHasErrors('guest_email');
    }

    #[Test]
    public function the_confirmation_page_is_not_reachable_without_a_booking_in_session(): void
    {
        // The reference lives in the session, not the URL, so it cannot be guessed.
        $this->get('/booking/submitted')->assertRedirect(route('cottages.index'));
    }

    #[Test]
    public function repeated_attempts_are_rate_limited(): void
    {
        /*
         * Each attempt can create a Lodgify reservation and a Stripe session, so the limit
         * is deliberately tight. Keyed on IP and email — see BookingServiceProvider.
         */
        for ($i = 0; $i < 3; $i++) {
            $this->post('/booking', $this->valid(['guest_email' => 'burst@example.com']));
        }

        $this->post('/booking', $this->valid(['guest_email' => 'burst@example.com']))
            ->assertStatus(429);
    }
}
