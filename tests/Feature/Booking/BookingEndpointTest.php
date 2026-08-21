<?php

namespace Tests\Feature\Booking;

use App\Models\Booking;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
    public function the_feature_flag_falls_back_to_the_lodgify_redirect(): void
    {
        /*
         * With direct payments off, the old hosted-checkout path is still the live one, so
         * the endpoint must hand off rather than half-run the new flow.
         */
        config()->set('booking.direct_payments_enabled', false);

        $this->post('/booking', $this->valid())
            ->assertRedirectContains('/book/sea-glass-738423');

        $this->assertSame(0, Booking::count());
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
