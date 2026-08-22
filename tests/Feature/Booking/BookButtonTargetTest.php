<?php

namespace Tests\Feature\Booking;

use App\DTO\Cottage;
use App\Services\Lodgify\LodgifyRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Where the cottage page's "Book now" button actually points.
 *
 * This is the regression test for the bug that made the payments feature invisible: the
 * whole backend existed, but the button still linked to /book/{slug} (the Lodgify
 * hand-off), so POST /booking was never reached from the UI.
 */
class BookButtonTargetTest extends TestCase
{
    use RefreshDatabase;

    private function bindCottage(): void
    {
        $cottage = new Cottage(
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

        $repo = Mockery::mock(LodgifyRepository::class);
        $repo->shouldReceive('cottageBySlug')->andReturn($cottage);
        $repo->shouldReceive('freeWindows')->andReturn([]);
        $repo->shouldReceive('seasons')->andReturn(new Collection);
        $repo->shouldReceive('quote')->andReturn(null);
        $repo->shouldReceive('lastErrors')->andReturn([]);
        $repo->shouldReceive('lastGuestMessage')->andReturn(null);
        $this->app->instance(LodgifyRepository::class, $repo);
    }

    #[Test]
    public function it_points_at_our_own_details_step(): void
    {
        $this->bindCottage();

        $this->get('/cottage/sea-glass-738423')
            ->assertOk()
            ->assertSee('/booking/details/sea-glass-738423', escape: false);
    }

    #[Test]
    public function the_page_contains_no_lodgify_checkout_link_at_all(): void
    {
        /*
         * The original bug was the button pointing at the Lodgify hand-off. Now that the
         * hand-off is deleted, this asserts the stronger property: nothing on the cottage
         * page can send a guest to Lodgify to pay.
         */
        $this->bindCottage();

        $html = $this->get('/cottage/sea-glass-738423')->assertOk()->getContent();

        $this->assertStringNotContainsString('checkout.lodgify.com', $html);
        $this->assertStringNotContainsString('/book/sea-glass-738423', $html);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
