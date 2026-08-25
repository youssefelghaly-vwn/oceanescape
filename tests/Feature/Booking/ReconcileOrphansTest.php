<?php

namespace Tests\Feature\Booking;

use App\DTO\Reservation;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Services\Lodgify\ReservationRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Recovery path for a reservation that exists in Lodgify with no local id pointing at it. */
class ReconcileOrphansTest extends TestCase
{
    use RefreshDatabase;

    private function reservation(array $o = []): Reservation
    {
        return new Reservation(
            id: (string) ($o['id'] ?? '17388658'),
            status: $o['status'] ?? 'Open',
            source: 'Website',
            propertyId: $o['propertyId'] ?? 738423,
            propertyName: 'Sea Glass Cottage',
            roomTypeId: 805539,
            arrival: Carbon::parse($o['arrival'] ?? '2026-11-02'),
            departure: Carbon::parse($o['departure'] ?? '2026-11-05'),
            nights: 3, checkInTime: null, checkOutTime: null,
            guestName: 'Alex Morgan',
            guestEmail: $o['guestEmail'] ?? 'alex@example.test',
            guestPhone: null, guestCountry: 'CA',
            adults: 2, children: 0, infants: 0, pets: 0,
            total: 900.0, amountPaid: 0.0, amountDue: 900.0, currency: 'CAD',
            subtotals: [], policy: [],
            createdAt: Carbon::now(), canceledAt: null, notes: null, isDeleted: false,
        );
    }

    private function bindFeed(array $reservations): void
    {
        $repo = Mockery::mock(ReservationRepository::class);
        $repo->shouldReceive('all')->andReturn(new Collection($reservations));
        $this->app->instance(ReservationRepository::class, $repo);
    }

    private function stranded(array $o = []): Booking
    {
        return Booking::factory()->create(array_merge([
            'status' => BookingStatus::PendingLodgify,
            'lodgify_booking_id' => null,
            'cottage_id' => 738423,
            'arrival' => '2026-11-02',
            'departure' => '2026-11-05',
            'guest_email' => 'alex@example.test',
        ], $o));
    }

    #[Test]
    public function it_reports_without_linking_by_default(): void
    {
        $booking = $this->stranded();
        $this->bindFeed([$this->reservation()]);

        $this->artisan('booking:reconcile-orphans')
            ->expectsOutputToContain('matches Lodgify #17388658')
            ->expectsOutputToContain('Report only')
            ->assertSuccessful();

        // Nothing changed.
        $this->assertNull($booking->fresh()->lodgify_booking_id);
    }

    #[Test]
    public function it_links_a_confident_match(): void
    {
        $booking = $this->stranded();
        $this->bindFeed([$this->reservation()]);

        $this->artisan('booking:reconcile-orphans', ['--link' => true])->assertSuccessful();

        $fresh = $booking->fresh();

        $this->assertSame('17388658', $fresh->lodgify_booking_id);
        $this->assertSame('Open', $fresh->lodgify_status);
        $this->assertSame(BookingStatus::AwaitingDeposit, $fresh->status);
        $this->assertDatabaseHas('booking_audit_logs', ['event' => 'lodgify.reconciled']);
    }

    #[Test]
    public function it_never_claims_a_reservation_belonging_to_someone_else(): void
    {
        /*
         * Same cottage, same nights, different guest. Linking this would attach our booking
         * — and later our payments — to a stranger's reservation.
         */
        $booking = $this->stranded(['guest_email' => 'alex@example.test']);
        $this->bindFeed([
            $this->reservation(['id' => '111', 'guestEmail' => 'someone.else@example.test']),
            $this->reservation(['id' => '222', 'guestEmail' => 'third.party@example.test']),
        ]);

        $this->artisan('booking:reconcile-orphans', ['--link' => true])
            ->expectsOutputToContain('too ambiguous')
            ->assertSuccessful();

        $this->assertNull($booking->fresh()->lodgify_booking_id);
    }

    #[Test]
    public function it_disambiguates_on_email_when_dates_collide(): void
    {
        $booking = $this->stranded(['guest_email' => 'alex@example.test']);
        $this->bindFeed([
            $this->reservation(['id' => '111', 'guestEmail' => 'other@example.test']),
            $this->reservation(['id' => '222', 'guestEmail' => 'alex@example.test']),
        ]);

        $this->artisan('booking:reconcile-orphans', ['--link' => true])->assertSuccessful();

        $this->assertSame('222', $booking->fresh()->lodgify_booking_id);
    }

    #[Test]
    public function it_reports_when_nothing_was_ever_created(): void
    {
        $booking = $this->stranded();
        $this->bindFeed([$this->reservation(['arrival' => '2027-01-01', 'departure' => '2027-01-05'])]);

        $this->artisan('booking:reconcile-orphans', ['--link' => true])
            ->expectsOutputToContain('nothing was created')
            ->assertSuccessful();

        $this->assertNull($booking->fresh()->lodgify_booking_id);
    }

    #[Test]
    public function a_failed_booking_is_relinked_but_not_silently_revived(): void
    {
        // Somebody decided this failed. Reviving it behind their back would be worse than
        // making them look at it.
        $booking = $this->stranded(['status' => BookingStatus::Failed]);
        $this->bindFeed([$this->reservation()]);

        $this->artisan('booking:reconcile-orphans', ['--link' => true])->assertSuccessful();

        $fresh = $booking->fresh();

        $this->assertSame('17388658', $fresh->lodgify_booking_id);
        $this->assertSame(BookingStatus::Failed, $fresh->status);
    }

    #[Test]
    public function it_ignores_bookings_that_already_have_an_id(): void
    {
        Booking::factory()->awaitingDeposit()->create();
        $this->bindFeed([$this->reservation()]);

        $this->artisan('booking:reconcile-orphans')
            ->expectsOutputToContain('No stranded bookings')
            ->assertSuccessful();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
