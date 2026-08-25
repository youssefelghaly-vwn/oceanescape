<?php

namespace Tests\Feature\Admin;

use App\DTO\Reservation;
use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Jobs\SendPaymentLink;
use App\Models\Booking;
use App\Models\BookingAuditLog;
use App\Models\BookingPayment;
use App\Models\User;
use App\Services\Booking\BookingAuditor;
use App\Services\Lodgify\ReservationRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The admin reservation page, where Lodgify's reservation and our payment record meet.
 *
 * The join is `bookings.lodgify_booking_id`, and the two halves answer different questions:
 * Lodgify owns the reservation, we own the money. A reservation with no local row is normal
 * — phone bookings and OTA bookings never touch this site — so the page must say so rather
 * than render an empty panel that reads as a fault.
 */
class ReservationPaymentPanelTest extends TestCase
{
    use RefreshDatabase;

    private const LODGIFY_ID = '17388658';

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake();

        $repo = Mockery::mock(ReservationRepository::class);
        $repo->shouldReceive('find')->andReturnUsing(fn (string $id) => $this->reservation($id));
        $repo->shouldReceive('search')->andReturn(collect());
        $repo->shouldReceive('filterOptions')->andReturn(['statuses' => [], 'sources' => [], 'properties' => []]);
        $repo->shouldReceive('stats')->andReturn(['total' => 0, 'upcoming' => 0, 'current' => 0, 'past' => 0, 'unpaid' => 0]);
        $this->app->instance(ReservationRepository::class, $repo);
    }

    private function reservation(string $id): Reservation
    {
        $arrival = now()->addDays(20)->startOfDay();

        return new Reservation(
            id: $id, status: 'Booked', source: 'Manual',
            propertyId: 738423, propertyName: 'Sea Glass Cottage', roomTypeId: 805539,
            arrival: $arrival, departure: $arrival->copy()->addDays(3), nights: 3,
            checkInTime: '15:00', checkOutTime: '11:00',
            guestName: 'Alex Morgan', guestEmail: 'alex@example.test',
            guestPhone: '+19025551234', guestCountry: 'CA',
            adults: 2, children: 0, infants: 0, pets: 0,
            total: 900.0, amountPaid: 225.0, amountDue: 675.0, currency: 'CAD',
            subtotals: [], policy: [],
            createdAt: Carbon::now()->subDays(2), canceledAt: null,
            notes: null, isDeleted: false,
        );
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    /** Deposit paid, balance outstanding — the state the "email the rest" button exists for. */
    private function bookingAwaitingBalance(): Booking
    {
        $booking = Booking::factory()->depositPaid()->create([
            'lodgify_booking_id' => self::LODGIFY_ID,
            'cottage_name' => 'Sea Glass Cottage',
            'guest_email' => 'alex@example.test',
            'currency' => 'CAD',
            'total_cents' => 90000, 'deposit_cents' => 22500, 'balance_cents' => 67500,
        ]);

        BookingPayment::factory()->for($booking)->create([
            'type' => PaymentType::Deposit, 'status' => PaymentStatus::Paid,
            'amount_cents' => 22500, 'amount_received_cents' => 22500, 'currency' => 'CAD',
            'paid_at' => now()->subDay(), 'link_sent_at' => now()->subDays(2), 'link_send_count' => 1,
        ]);

        return $booking->fresh();
    }

    private function url(): string
    {
        return route('admin.reservations.show', self::LODGIFY_ID);
    }

    // ------------------------------------------------------------- the panel

    #[Test]
    public function the_page_shows_our_payment_record_beside_lodgifys_reservation(): void
    {
        $booking = $this->bookingAwaitingBalance();

        $this->actingAs($this->admin())->get($this->url())
            ->assertOk()
            // Lodgify's half
            ->assertSee('Alex Morgan')
            ->assertSee('Sea Glass Cottage')
            // ours
            ->assertSee($booking->reference)
            ->assertSee('900.00 CAD')      // total
            ->assertSee('225.00 CAD')      // paid
            ->assertSee('675.00 CAD');     // outstanding
    }

    #[Test]
    public function a_capture_that_did_not_match_is_called_out_rather_than_buried(): void
    {
        // The reason a paid booking may not have been confirmed. It must be impossible to
        // miss on the page an operator opens when the guest says "but I paid".
        $booking = Booking::factory()->awaitingDeposit()->create([
            'lodgify_booking_id' => self::LODGIFY_ID, 'currency' => 'CAD',
            'total_cents' => 90000, 'deposit_cents' => 22500, 'balance_cents' => 67500,
        ]);

        BookingPayment::factory()->for($booking)->create([
            'type' => PaymentType::Deposit, 'status' => PaymentStatus::Failed,
            'amount_cents' => 22500, 'amount_received_cents' => 100, 'currency' => 'CAD',
        ]);

        $this->actingAs($this->admin())->get($this->url())
            ->assertOk()
            ->assertSee('does not match what we asked for');
    }

    #[Test]
    public function the_recent_trail_is_shown_with_a_link_to_all_of_it(): void
    {
        $booking = $this->bookingAwaitingBalance();

        app(BookingAuditor::class)->record('payment.succeeded', $booking, $booking->deposit(), [
            'captured' => '225.00 CAD',
        ]);

        $this->actingAs($this->admin())->get($this->url())
            ->assertOk()
            ->assertSee('payment.succeeded')
            ->assertSee(route('admin.audits.show', $booking), escape: false);
    }

    #[Test]
    public function a_reservation_we_did_not_take_says_so_instead_of_showing_an_empty_panel(): void
    {
        // No local booking row: a phone or OTA reservation. Lodgify has whatever was
        // collected, and there is nothing here to email.
        $this->actingAs($this->admin())->get($this->url())
            ->assertOk()
            ->assertSee('not taken through this website')
            ->assertDontSee('Email the balance link');
    }

    // ------------------------------------------------------------ the button

    #[Test]
    public function the_button_offers_the_balance_when_the_deposit_is_paid(): void
    {
        $this->bookingAwaitingBalance();

        $this->actingAs($this->admin())->get($this->url())
            ->assertOk()
            ->assertSee('Email the balance link')
            ->assertSee('675.00 CAD');
    }

    #[Test]
    public function it_queues_the_balance_link_and_records_who_asked_for_it(): void
    {
        $booking = $this->bookingAwaitingBalance();

        $this->actingAs($admin = $this->admin())
            ->post(route('admin.reservations.payment-link', self::LODGIFY_ID))
            ->assertRedirect();

        $balance = $booking->fresh()->balance();

        $this->assertNotNull($balance, 'a balance payment row should have been created');
        $this->assertSame(67500, (int) $balance->amount_cents);

        Bus::assertDispatched(SendPaymentLink::class);

        // Attributed to the admin, because "who asked the guest for more money" is exactly
        // the kind of question an audit trail exists to answer.
        $this->assertDatabaseHas('booking_audit_logs', [
            'booking_id' => $booking->getKey(),
            'event' => 'payment.link_requested_by_admin',
            'actor_type' => 'admin',
            'actor_id' => $admin->id,
        ]);

        $this->assertSame(BookingStatus::AwaitingBalance, $booking->fresh()->status);
    }

    #[Test]
    public function it_re_sends_the_unpaid_deposit_rather_than_asking_for_a_balance(): void
    {
        /*
         * Until the deposit is paid the dates are not even held, so a balance link would be
         * asking for the wrong thing at the wrong time.
         */
        $booking = Booking::factory()->awaitingDeposit()->create([
            'lodgify_booking_id' => self::LODGIFY_ID, 'currency' => 'CAD',
            'total_cents' => 90000, 'deposit_cents' => 22500, 'balance_cents' => 67500,
        ]);

        BookingPayment::factory()->for($booking)->linkSent()->create([
            'type' => PaymentType::Deposit, 'amount_cents' => 22500, 'currency' => 'CAD',
        ]);

        $this->actingAs($this->admin())
            ->post(route('admin.reservations.payment-link', self::LODGIFY_ID))
            ->assertRedirect();

        $this->assertNull($booking->fresh()->balance(), 'no balance row should be created yet');
        $this->assertSame(BookingStatus::AwaitingDeposit, $booking->fresh()->status);

        Bus::assertDispatched(SendPaymentLink::class);
    }

    #[Test]
    public function pressing_it_twice_re_sends_one_link_rather_than_creating_a_second_charge(): void
    {
        $booking = $this->bookingAwaitingBalance();
        $url = route('admin.reservations.payment-link', self::LODGIFY_ID);
        $admin = $this->admin();

        $this->actingAs($admin)->post($url);
        $this->actingAs($admin)->post($url);

        // UNIQUE(booking_id, type) is the real guard; this asserts the screen respects it.
        $this->assertSame(1, BookingPayment::where('booking_id', $booking->getKey())
            ->where('type', PaymentType::Balance->value)->count());
    }

    #[Test]
    public function nothing_is_sent_when_nothing_is_owed(): void
    {
        $booking = Booking::factory()->create([
            'lodgify_booking_id' => self::LODGIFY_ID,
            'status' => BookingStatus::PaidInFull,
            'currency' => 'CAD',
            'total_cents' => 90000, 'deposit_cents' => 22500, 'balance_cents' => 0,
        ]);

        BookingPayment::factory()->for($booking)->create([
            'type' => PaymentType::Deposit, 'status' => PaymentStatus::Paid,
            'amount_cents' => 90000, 'amount_received_cents' => 90000, 'currency' => 'CAD',
            'paid_at' => now(),
        ]);

        $this->actingAs($this->admin())
            ->post(route('admin.reservations.payment-link', self::LODGIFY_ID))
            ->assertRedirect();

        Bus::assertNotDispatched(SendPaymentLink::class);
        $this->assertSame(0, BookingAuditLog::where('event', 'payment.link_requested_by_admin')->count());
    }

    #[Test]
    public function it_declines_gracefully_for_a_reservation_we_never_took(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.reservations.payment-link', self::LODGIFY_ID))
            ->assertRedirect();

        Bus::assertNotDispatched(SendPaymentLink::class);
    }

    #[Test]
    public function only_an_admin_can_press_it(): void
    {
        $this->bookingAwaitingBalance();

        // It emails a guest about money — the gate matters more here than on a read screen.
        $this->post(route('admin.reservations.payment-link', self::LODGIFY_ID))
            ->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create(['is_admin' => false]))
            ->post(route('admin.reservations.payment-link', self::LODGIFY_ID))
            ->assertForbidden();

        Bus::assertNotDispatched(SendPaymentLink::class);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
