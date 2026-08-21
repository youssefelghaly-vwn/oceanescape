<?php

namespace Tests\Feature\Booking;

use App\DTO\Cottage;
use App\Models\User;
use App\Services\Booking\QuoteReader;
use App\Services\Lodgify\LodgifyRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The guest-details step, and the wiring that routes the "Book now" button to it.
 *
 * This covers the gap that made the whole feature invisible from the front end: the
 * cottage page's button still pointed at /book/{slug} (Lodgify) and nothing ever reached
 * POST /booking.
 */
class BookingDetailsPageTest extends TestCase
{
    use RefreshDatabase;

    private function cottage(): Cottage
    {
        return new Cottage(
            id: 738423, slug: 'sea-glass-738423', name: 'Sea Glass Cottage',
            description: null, shortDescription: null,
            addressLine: null, city: 'Lockeport', state: 'NS', country: 'Canada',
            postalCode: null, latitude: null, longitude: null,
            bedrooms: 2, bathrooms: 1, maxGuests: 6, propertyType: null, sizeSqm: null,
            petFriendly: true, smokingAllowed: false, partiesAllowed: false,
            childrenAllowed: true, checkInTime: '15:00', checkOutTime: '11:00',
            minStay: 2, maxStay: null, houseRules: [],
            heroImage: null, images: [], imageAlts: [],
            rooms: [['id' => 805539, 'name' => 'Main', 'maxGuests' => 6]],
            baseNightlyPrice: 300.0, currency: 'CAD', amenities: [],
        );
    }

    private function bindLodgify(array $quote): void
    {
        $cottage = $this->cottage();

        $repo = Mockery::mock(LodgifyRepository::class);
        $repo->shouldReceive('cottageBySlug')->andReturn($cottage);
        $repo->shouldReceive('cottagesFreeFor')->andReturn(new Collection([$cottage]));
        $repo->shouldReceive('lastGuestMessage')->andReturn(null);
        $this->app->instance(LodgifyRepository::class, $repo);

        $reader = Mockery::mock(QuoteReader::class);
        $reader->shouldReceive('authoritativeQuote')->andReturn($quote);
        $this->app->instance(QuoteReader::class, $reader);
    }

    private function quote(array $overrides = []): array
    {
        return array_merge([
            'source' => 'v2', 'currency' => 'CAD', 'nights' => 3, 'total' => 900.0,
            'fees' => [], 'taxes' => [],
            'schedule' => [
                ['name' => 'On agreement', 'amount' => 225.0, 'is_current' => true],
                ['name' => 'Before arrival', 'amount' => 675.0, 'is_current' => false],
            ],
        ], $overrides);
    }

    private function url(array $overrides = []): string
    {
        $arrival = now()->addDays(60);

        return route('booking.details', array_merge([
            'slug' => 'sea-glass-738423',
            'arrival' => $arrival->toDateString(),
            'departure' => $arrival->copy()->addDays(3)->toDateString(),
            'adults' => 2,
        ], $overrides));
    }

    #[Test]
    public function it_shows_the_server_side_price_and_the_deposit(): void
    {
        $this->bindLodgify($this->quote());

        $this->get($this->url())
            ->assertOk()
            ->assertSee('Sea Glass Cottage')
            ->assertSee('Deposit due now', escape: false)
            // Total and split, priced server-side from Lodgify's schedule.
            ->assertSee('900.00 CAD')
            ->assertSee('225.00 CAD')
            ->assertSee('675.00 CAD')
            ->assertSee('name="guest_email"', escape: false)
            ->assertSee('nothing is charged on this page', escape: false);
    }

    #[Test]
    public function it_warns_when_the_displayed_price_no_longer_matches(): void
    {
        /*
         * The calendar passes the total it was showing. If the live quote disagrees we say
         * so rather than quietly charging a different number.
         */
        $this->bindLodgify($this->quote());

        $this->get($this->url(['total' => 800.00]))
            ->assertOk()
            ->assertSee('The price has changed', escape: false)
            ->assertSee('800.00 CAD')
            ->assertSee('900.00 CAD');
    }

    #[Test]
    public function it_does_not_warn_when_the_price_matches(): void
    {
        $this->bindLodgify($this->quote());

        $this->get($this->url(['total' => 900.00]))
            ->assertOk()
            ->assertDontSee('The price has changed', escape: false);
    }

    #[Test]
    public function it_sends_the_guest_back_when_lodgify_will_not_price_the_stay(): void
    {
        // No schedule -> DepositPolicy refuses. The guest must not be shown a form that
        // cannot succeed.
        config()->set('booking.deposit.allow_percentage_fallback', false);
        $this->bindLodgify($this->quote(['schedule' => []]));

        $this->get($this->url())
            ->assertRedirect()
            ->assertSessionHas('checkout_error');
    }

    #[Test]
    public function it_requires_valid_dates(): void
    {
        $this->bindLodgify($this->quote());

        $this->get(route('booking.details', ['slug' => 'sea-glass-738423']))
            ->assertSessionHasErrors(['arrival', 'departure']);

        $this->get($this->url([
            'arrival' => now()->subDay()->toDateString(),
            'departure' => now()->addDays(3)->toDateString(),
        ]))->assertSessionHasErrors('arrival');
    }

    #[Test]
    public function it_404s_for_an_unknown_cottage(): void
    {
        $repo = Mockery::mock(LodgifyRepository::class);
        $repo->shouldReceive('cottageBySlug')->andReturn(null);
        $this->app->instance(LodgifyRepository::class, $repo);

        $this->get($this->url(['slug' => 'nope']))->assertNotFound();
    }

    #[Test]
    public function with_the_flag_off_it_hands_back_to_the_lodgify_checkout(): void
    {
        config()->set('booking.direct_payments_enabled', false);

        $this->get($this->url())->assertRedirectContains('/book/sea-glass-738423');
    }

    #[Test]
    public function it_prefills_from_the_signed_in_user(): void
    {
        $this->bindLodgify($this->quote());

        $user = User::factory()->create([
            'name' => 'Jordan Reyes', 'email' => 'jordan@example.test',
        ]);

        $this->actingAs($user)->get($this->url())
            ->assertOk()
            ->assertSee('Jordan Reyes')
            ->assertSee('jordan@example.test');
    }

    #[Test]
    public function the_form_carries_csrf_and_a_honeypot(): void
    {
        $this->bindLodgify($this->quote());

        $this->get($this->url())
            ->assertOk()
            ->assertSee('name="_token"', escape: false)
            ->assertSee('name="website_url"', escape: false);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
