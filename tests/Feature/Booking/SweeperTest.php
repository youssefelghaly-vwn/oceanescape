<?php

namespace Tests\Feature\Booking;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Jobs\SendPaymentLink;
use App\Models\Booking;
use App\Models\BookingPayment;
use App\Services\Lodgify\LodgifyBookingWriter;
use App\Services\Payments\StripeGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The two scheduled commands: balance links, and releasing unpaid reservations.
 */
class SweeperTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Neither command should reach Stripe or Lodgify for real.
        $stripe = Mockery::mock(StripeGateway::class);
        $stripe->shouldReceive('expireSession')->andReturn(true);
        $this->app->instance(StripeGateway::class, $stripe);
    }

    private function fakeWriter(): Mockery\MockInterface
    {
        $writer = Mockery::mock(LodgifyBookingWriter::class);
        $writer->shouldReceive('release')->andReturn(true)->byDefault();
        $this->app->instance(LodgifyBookingWriter::class, $writer);

        return $writer;
    }

    // ============================================== balance link scheduling

    #[Test]
    public function it_sends_a_balance_link_inside_the_lead_window(): void
    {
        Bus::fake();

        $booking = Booking::factory()->depositPaid()->arrivingIn(20)->create([
            'total_cents' => 100000, 'deposit_cents' => 25000, 'balance_cents' => 75000,
        ]);

        $this->artisan('booking:send-balance-links', ['--lead' => 30])->assertSuccessful();

        $balance = $booking->fresh()->balance();

        $this->assertNotNull($balance);
        $this->assertSame(75000, (int) $balance->amount_cents);
        $this->assertSame(PaymentType::Balance, $balance->type);
        $this->assertSame(BookingStatus::AwaitingBalance, $booking->fresh()->status);
        Bus::assertDispatched(SendPaymentLink::class);
    }

    #[Test]
    public function it_leaves_bookings_outside_the_lead_window_alone(): void
    {
        Bus::fake();

        $booking = Booking::factory()->depositPaid()->arrivingIn(90)->create();

        $this->artisan('booking:send-balance-links', ['--lead' => 30])->assertSuccessful();

        $this->assertNull($booking->fresh()->balance());
        $this->assertSame(BookingStatus::DepositPaid, $booking->fresh()->status);
        Bus::assertNotDispatched(SendPaymentLink::class);
    }

    #[Test]
    public function running_it_twice_does_not_send_two_balance_links(): void
    {
        /*
         * The command is scheduled hourly, so this is the normal case. Idempotency comes
         * from the query excluding bookings that already have a balance payment row, plus
         * the UNIQUE(booking_id, type) constraint behind it.
         */
        Bus::fake();

        $booking = Booking::factory()->depositPaid()->arrivingIn(20)->create([
            'total_cents' => 100000, 'deposit_cents' => 25000, 'balance_cents' => 75000,
        ]);

        $this->artisan('booking:send-balance-links', ['--lead' => 30])->assertSuccessful();
        $this->artisan('booking:send-balance-links', ['--lead' => 30])->assertSuccessful();

        $this->assertSame(
            1,
            BookingPayment::where('booking_id', $booking->getKey())
                ->where('type', PaymentType::Balance->value)->count()
        );
        Bus::assertDispatchedTimes(SendPaymentLink::class, 1);
    }

    #[Test]
    public function it_does_not_chase_a_zero_balance(): void
    {
        Bus::fake();

        $booking = Booking::factory()->depositPaid()->arrivingIn(10)->create([
            'total_cents' => 50000, 'deposit_cents' => 50000, 'balance_cents' => 0,
        ]);

        $this->artisan('booking:send-balance-links', ['--lead' => 30])->assertSuccessful();

        $this->assertNull($booking->fresh()->balance());
        Bus::assertNotDispatched(SendPaymentLink::class);
    }

    #[Test]
    public function a_dry_run_changes_nothing(): void
    {
        Bus::fake();

        $booking = Booking::factory()->depositPaid()->arrivingIn(20)->create([
            'total_cents' => 100000, 'deposit_cents' => 25000, 'balance_cents' => 75000,
        ]);

        $this->artisan('booking:send-balance-links', ['--lead' => 30, '--dry-run' => true])
            ->assertSuccessful();

        $this->assertNull($booking->fresh()->balance());
        Bus::assertNotDispatched(SendPaymentLink::class);
    }

    // ==================================================== expiry / release

    #[Test]
    public function it_releases_an_unpaid_reservation_whose_link_has_lapsed(): void
    {
        $writer = $this->fakeWriter();

        $booking = Booking::factory()->awaitingDeposit()->create();
        BookingPayment::factory()->deposit()->expiredLink()->create([
            'booking_id' => $booking->getKey(),
        ]);

        $writer->shouldReceive('release')->once()->andReturn(true);

        $this->artisan('booking:expire-stale')->assertSuccessful();

        $fresh = $booking->fresh();

        $this->assertSame(BookingStatus::Expired, $fresh->status);
        $this->assertNotNull($fresh->cancelled_at);
        $this->assertSame(PaymentStatus::Expired, $fresh->payments()->first()->status);
    }

    #[Test]
    public function it_never_releases_a_reservation_that_was_just_paid(): void
    {
        /*
         * THE RACE THAT WOULD BE THE WORST BUG IN THIS FEATURE: a guest pays in the
         * seconds between the sweeper's query and its write, and the sweeper cancels the
         * stay they just paid for.
         *
         * Simulated by settling the payment after the row is already stale-looking. The
         * command re-reads each booking and skips anything no longer awaiting a deposit.
         */
        $writer = $this->fakeWriter();

        $booking = Booking::factory()->awaitingDeposit()->create([
            'total_cents' => 100000, 'deposit_cents' => 25000, 'balance_cents' => 75000,
        ]);

        $payment = BookingPayment::factory()->deposit()->expiredLink()->create([
            'booking_id' => $booking->getKey(), 'amount_cents' => 25000,
        ]);

        // The guest pays. Booking advances out of awaiting_deposit.
        $payment->forceFill([
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
            'amount_received_cents' => 25000,
        ])->save();
        $booking->transitionTo(BookingStatus::DepositPaid, ['booked_at' => now()]);

        $writer->shouldNotReceive('release');

        $this->artisan('booking:expire-stale')->assertSuccessful();

        $this->assertSame(
            BookingStatus::DepositPaid,
            $booking->fresh()->status,
            'a paid booking must never be released by the sweeper'
        );
    }

    #[Test]
    public function it_leaves_a_live_link_alone(): void
    {
        $writer = $this->fakeWriter();
        $writer->shouldNotReceive('release');

        $booking = Booking::factory()->awaitingDeposit()->create();
        BookingPayment::factory()->deposit()->linkSent()->create([
            'booking_id' => $booking->getKey(),
            'link_expires_at' => now()->addDay(),
        ]);

        $this->artisan('booking:expire-stale')->assertSuccessful();

        $this->assertSame(BookingStatus::AwaitingDeposit, $booking->fresh()->status);
        $this->assertSame(PaymentStatus::LinkSent, $booking->fresh()->payments()->first()->status);
    }

    #[Test]
    public function expiry_dry_run_changes_nothing(): void
    {
        $writer = $this->fakeWriter();
        $writer->shouldNotReceive('release');

        $booking = Booking::factory()->awaitingDeposit()->create();
        BookingPayment::factory()->deposit()->expiredLink()->create([
            'booking_id' => $booking->getKey(),
        ]);

        $this->artisan('booking:expire-stale', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(BookingStatus::AwaitingDeposit, $booking->fresh()->status);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
