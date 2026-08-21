<?php

namespace Tests\Feature\Booking;

use App\DTO\Cottage;
use App\Enums\BookingStatus;
use App\Enums\PaymentType;
use App\Exceptions\LodgifyWriteFailed;
use App\Exceptions\PaymentScheduleUnavailable;
use App\Jobs\SendPaymentLink;
use App\Models\Booking;
use App\Services\Booking\BookingCreator;
use App\Services\Booking\QuoteReader;
use App\Services\Lodgify\LodgifyBookingWriter;
use App\Services\Lodgify\LodgifyRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The booking-creation path.
 *
 * Lodgify is mocked because these tests are about OUR orchestration and money handling,
 * not about Lodgify's API — whose write contract is unverified from this environment
 * anyway (see config lodgify.write and `php artisan lodgify:probe-booking-write`).
 */
class CreateBookingTest extends TestCase
{
    use RefreshDatabase;

    private function cottage(): Cottage
    {
        // Only the fields BookingCreator touches need to be meaningful.
        return new Cottage(
            id: 738423, slug: 'sea-glass-738423', name: 'Sea Glass Cottage',
            description: null, shortDescription: null,
            addressLine: null, city: null, state: null, country: null, postalCode: null,
            latitude: null, longitude: null,
            bedrooms: 2, bathrooms: 1, maxGuests: 6, propertyType: null, sizeSqm: null,
            petFriendly: true, smokingAllowed: false, partiesAllowed: false,
            childrenAllowed: true, checkInTime: '15:00', checkOutTime: '11:00',
            minStay: 2, maxStay: null, houseRules: [],
            heroImage: null, images: [], imageAlts: [],
            rooms: [['id' => 805539, 'name' => 'Main', 'maxGuests' => 6]],
            baseNightlyPrice: 300.0,
            currency: 'CAD',
            amenities: [],
        );
    }

    private function quote(array $overrides = []): array
    {
        return array_merge([
            'source' => 'v2',
            'currency' => 'CAD',
            'nights' => 3,
            'total' => 900.0,
            'schedule' => [
                ['name' => 'On agreement', 'amount' => 225.0, 'is_current' => true],
                ['name' => 'Before arrival', 'amount' => 675.0, 'is_current' => false],
            ],
        ], $overrides);
    }

    private function payload(array $overrides = []): array
    {
        $arrival = now()->addDays(60)->startOfDay();

        return array_merge([
            'slug' => 'sea-glass-738423',
            'arrival' => $arrival->toDateString(),
            'departure' => $arrival->copy()->addDays(3)->toDateString(),
            'adults' => 2,
            'children' => 0,
            'pets' => 0,
            'guest_name' => 'Alex Morgan',
            'guest_email' => 'alex@example.test',
            'guest_phone' => '+19025551234',
            'guest_country' => 'CA',
        ], $overrides);
    }

    /** @param array<string,mixed> $quote */
    private function bindLodgify(array $quote, ?string $createdId = '17388658'): void
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

        $writer = Mockery::mock(LodgifyBookingWriter::class);

        if ($createdId === null) {
            $writer->shouldReceive('createOpenBooking')->andThrow(new LodgifyWriteFailed(
                'Lodgify said no', operation: 'createOpenBooking', status: 422, moneyAtRisk: false,
            ));
        } else {
            $writer->shouldReceive('createOpenBooking')->andReturn($createdId);
        }

        $this->app->instance(LodgifyBookingWriter::class, $writer);
    }

    #[Test]
    public function it_creates_an_open_reservation_and_queues_the_deposit_link(): void
    {
        Bus::fake();
        $this->bindLodgify($this->quote());

        $booking = app(BookingCreator::class)->create($this->payload());

        // Reservation exists, unconfirmed, nothing charged.
        $this->assertSame(BookingStatus::AwaitingDeposit, $booking->status);
        $this->assertSame('17388658', $booking->lodgify_booking_id);
        $this->assertSame('Open', $booking->lodgify_status);
        $this->assertNull($booking->booked_at);

        // Money taken strictly from Lodgify's schedule.
        $this->assertSame(90000, (int) $booking->total_cents);
        $this->assertSame(22500, (int) $booking->deposit_cents);
        $this->assertSame(67500, (int) $booking->balance_cents);
        $this->assertSame('CAD', $booking->currency);

        // One deposit payment row, and the link is queued rather than sent inline.
        $this->assertSame(1, $booking->payments()->count());
        $this->assertSame(PaymentType::Deposit, $booking->deposit()->type);
        $this->assertSame(22500, (int) $booking->deposit()->amount_cents);
        Bus::assertDispatched(SendPaymentLink::class);

        $this->assertDatabaseHas('booking_audit_logs', ['event' => 'booking.created']);
    }

    #[Test]
    public function the_client_cannot_influence_the_price(): void
    {
        /*
         * The core money-safety property. Even if a crafted request carries its own totals,
         * the amounts come from the Lodgify quote and nothing else.
         */
        Bus::fake();
        $this->bindLodgify($this->quote());

        $booking = app(BookingCreator::class)->create($this->payload([
            'total_cents' => 1,
            'deposit_cents' => 1,
            'total' => 0.01,
            'currency' => 'USD',
        ]));

        $this->assertSame(90000, (int) $booking->total_cents);
        $this->assertSame(22500, (int) $booking->deposit_cents);
        $this->assertSame('CAD', $booking->currency);
    }

    #[Test]
    public function a_double_submit_resolves_to_one_booking(): void
    {
        /*
         * A double-clicked confirm button must not create two Lodgify reservations for the
         * same nights. Guarded by a unique idempotency_key derived from the stay + guest.
         */
        Bus::fake();
        $this->bindLodgify($this->quote());

        $creator = app(BookingCreator::class);

        $first = $creator->create($this->payload());
        $second = $creator->create($this->payload());

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame(1, Booking::count());
        $this->assertDatabaseHas('booking_audit_logs', ['event' => 'booking.duplicate_suppressed']);
    }

    #[Test]
    public function a_lodgify_failure_leaves_the_booking_failed_and_charges_nothing(): void
    {
        Bus::fake();
        $this->bindLodgify($this->quote(), createdId: null);

        try {
            app(BookingCreator::class)->create($this->payload());
            $this->fail('expected LodgifyWriteFailed');
        } catch (LodgifyWriteFailed $e) {
            $this->assertFalse($e->moneyAtRisk, 'no money has moved at creation time');
            $this->assertStringContainsString('Nothing has been charged', (string) $e->guestMessage());
        }

        $booking = Booking::first();

        $this->assertSame(BookingStatus::Failed, $booking->status);
        $this->assertNull($booking->lodgify_booking_id);
        $this->assertNotNull($booking->lodgify_sync_error);

        // No payment was ever created, so no link can be sent.
        $this->assertSame(0, $booking->payments()->count());
        Bus::assertNotDispatched(SendPaymentLink::class);
    }

    #[Test]
    public function an_imminent_stay_is_charged_in_one_payment(): void
    {
        Bus::fake();
        config()->set('booking.full_payment_within_days', 14);
        $this->bindLodgify($this->quote());

        $arrival = now()->addDays(5)->startOfDay();

        $booking = app(BookingCreator::class)->create($this->payload([
            'arrival' => $arrival->toDateString(),
            'departure' => $arrival->copy()->addDays(3)->toDateString(),
        ]));

        $this->assertTrue($booking->requires_full_payment);
        $this->assertSame(90000, (int) $booking->deposit_cents);
        $this->assertSame(0, (int) $booking->balance_cents);
        $this->assertSame(PaymentType::Full, $booking->deposit()->type);
    }

    #[Test]
    public function it_records_the_quote_it_priced_from(): void
    {
        // Kept so a disputed amount can be answered with what Lodgify said at the time.
        Bus::fake();
        $this->bindLodgify($this->quote());

        $booking = app(BookingCreator::class)->create($this->payload());

        // assertEquals not assertSame: the snapshot round-trips through JSON, which
        // renders 900.0 as 900.
        $this->assertEquals(900.0, $booking->quote_snapshot['total']);
        $this->assertNotEmpty($booking->quote_snapshot['quoted_at']);
        $this->assertSame('lodgify_schedule', $booking->payment_schedule['source']);
    }

    #[Test]
    public function it_refuses_when_lodgify_supplies_no_payment_schedule(): void
    {
        Bus::fake();
        config()->set('booking.deposit.allow_percentage_fallback', false);
        $this->bindLodgify($this->quote(['schedule' => []]));

        $this->expectException(PaymentScheduleUnavailable::class);

        app(BookingCreator::class)->create($this->payload());

        $this->assertSame(0, Booking::count());
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
