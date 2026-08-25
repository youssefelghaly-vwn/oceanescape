<?php

namespace Tests\Feature\Admin;

use App\DTO\Reservation;
use App\Http\Controllers\Admin\ReservationController;
use App\Models\User;
use App\Services\Lodgify\ReservationRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Pagination on /admin/reservations.
 *
 * The list is paginated in PHP over a collection read live from Lodgify, not a database
 * query — so none of Eloquent's paginator behaviour comes for free and each part is worth
 * pinning: the page size, that filters and page size survive the page links, and that a page
 * past the end does not render as "no matches".
 *
 * Lodgify is mocked at the repository seam; these tests are about our paging, not its API.
 */
class ReservationPaginationTest extends TestCase
{
    /** @var Collection<int, Reservation> */
    private Collection $reservations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reservations = $this->fakeReservations(60);

        $repo = Mockery::mock(ReservationRepository::class);
        // search() ignores the filters here: what matters is how we slice what it returns.
        $repo->shouldReceive('search')->andReturnUsing(fn () => $this->reservations);
        $repo->shouldReceive('filterOptions')->andReturn([
            'statuses' => ['Booked'], 'sources' => ['Manual'], 'properties' => [738423 => 'Sea Glass Cottage'],
        ]);
        $repo->shouldReceive('stats')->andReturn([
            'total' => 60, 'upcoming' => 60, 'current' => 0, 'past' => 0, 'unpaid' => 0,
        ]);
        $this->app->instance(ReservationRepository::class, $repo);
    }

    /** @return Collection<int, Reservation> */
    private function fakeReservations(int $count): Collection
    {
        return collect(range(1, $count))->map(function (int $i) {
            $arrival = now()->addDays(10 + $i)->startOfDay();

            return new Reservation(
                id: (string) (17000000 + $i),
                status: 'Booked',
                source: 'Manual',
                propertyId: 738423,
                propertyName: 'Sea Glass Cottage',
                roomTypeId: 805539,
                arrival: $arrival,
                departure: $arrival->copy()->addDays(3),
                nights: 3,
                checkInTime: '15:00',
                checkOutTime: '11:00',
                guestName: "Guest Number {$i}",
                guestEmail: "guest{$i}@example.test",
                guestPhone: null,
                guestCountry: 'CA',
                adults: 2, children: 0, infants: 0, pets: 0,
                total: 900.0, amountPaid: 900.0, amountDue: 0.0, currency: 'CAD',
                subtotals: [], policy: [],
                createdAt: Carbon::now()->subDay(), canceledAt: null,
                notes: null, isDeleted: false,
            );
        });
    }

    private function admin(): User
    {
        return User::factory()->make(['is_admin' => true, 'email_verified_at' => now()]);
    }

    #[Test]
    public function the_first_page_shows_one_page_worth_and_no_more(): void
    {
        $response = $this->actingAs($this->admin())->get('/admin/reservations')->assertOk();

        $response->assertSee('Guest Number 1')
            ->assertSee('Guest Number 25')
            ->assertDontSee('Guest Number 26');

        // The count is what tells an operator the filter worked; the page size is not it.
        $response->assertSee('60 matches');
    }

    #[Test]
    public function the_links_render_with_our_own_markup(): void
    {
        /*
         * REGRESSION, and the reason this file exists. Laravel's own paginator view lives in
         * /vendor, which is gitignored — and Tailwind v4's automatic content detection skips
         * gitignored paths, so its classes were never compiled and the controls rendered as
         * bare unstyled links. Ours lives in resources/views/vendor/pagination, which is
         * always scanned.
         */
        $response = $this->actingAs($this->admin())->get('/admin/reservations')->assertOk();

        $response->assertSee('aria-label="Pagination Navigation"', escape: false)
            // Our palette, not Laravel's grays — proof the override is the view in use.
            ->assertSee('ring-fog-300', escape: false)
            ->assertDontSee('border-gray-300', escape: false)
            // The summary line, which Laravel's default has and this screen was missing.
            ->assertSee('Showing');
    }

    #[Test]
    public function page_two_continues_where_page_one_stopped(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/reservations?page=2')
            ->assertOk()
            ->assertSee('Guest Number 26')
            ->assertSee('Guest Number 50')
            ->assertDontSee('Guest Number 51');
    }

    #[Test]
    public function the_page_size_can_be_raised(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/reservations?per_page=50')
            ->assertOk()
            ->assertSee('Guest Number 50')
            ->assertDontSee('Guest Number 51');
    }

    #[Test]
    public function an_unlisted_page_size_falls_back_instead_of_being_honoured(): void
    {
        // per_page is an allowlist: an unbounded value from the query string would let anyone
        // outside the admin area ask this page to render everything Lodgify has.
        $this->actingAs($this->admin())
            ->get('/admin/reservations?per_page=5000')
            ->assertOk()
            ->assertSee('Guest Number 25')
            ->assertDontSee('Guest Number 26');

        $this->assertSame([25, 50, 100], ReservationController::PER_PAGE_OPTIONS);
    }

    #[Test]
    public function filters_and_page_size_survive_the_page_links(): void
    {
        // A page link that drops the filters silently shows the operator a different result
        // set than the one they are reading.
        $response = $this->actingAs($this->admin())
            ->get('/admin/reservations?status=Booked&per_page=50&timeframe=upcoming')
            ->assertOk();

        $response->assertSee('status=Booked', escape: false)
            ->assertSee('per_page=50', escape: false)
            ->assertSee('timeframe=upcoming', escape: false);
    }

    #[Test]
    public function a_page_past_the_end_shows_the_last_page_not_an_empty_table(): void
    {
        /*
         * A stale bookmark, or narrowing the filters while on page 4. Left alone this renders
         * "No reservations match — try clearing the filters", which sends the operator
         * hunting for a filter problem that does not exist.
         */
        $this->actingAs($this->admin())
            ->get('/admin/reservations?page=99')
            ->assertOk()
            ->assertSee('Guest Number 60')
            ->assertDontSee('No reservations match');
    }

    #[Test]
    public function a_result_set_that_fits_on_one_page_shows_no_controls(): void
    {
        $this->reservations = $this->fakeReservations(4);

        $this->actingAs($this->admin())
            ->get('/admin/reservations')
            ->assertOk()
            ->assertSee('Guest Number 4')
            // hasPages() is false, so the nav is absent by design rather than empty chrome.
            ->assertDontSee('aria-label="Pagination Navigation"', escape: false);
    }

    #[Test]
    public function an_empty_result_set_still_renders_the_empty_state(): void
    {
        $this->reservations = collect();

        $this->actingAs($this->admin())
            ->get('/admin/reservations')
            ->assertOk()
            ->assertSee('No reservations match')
            ->assertDontSee('aria-label="Pagination Navigation"', escape: false);
    }

    #[Test]
    public function the_list_is_admin_only(): void
    {
        $this->get('/admin/reservations')->assertRedirect(route('login'));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
