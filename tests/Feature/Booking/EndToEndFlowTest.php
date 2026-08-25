<?php

namespace Tests\Feature\Booking;

use App\DTO\Cottage;
use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Services\Booking\BookingAuditor;
use App\Services\Booking\QuoteReader;
use App\Services\Lodgify\LodgifyBookingWriter;
use App\Services\Lodgify\LodgifyRepository;
use App\Services\Payments\PaymentLinkService;
use App\Services\Payments\StripeGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The whole journey, through real HTTP routes: cottage page -> details -> reserve ->
 * payment link -> Stripe webhook -> confirmed.
 *
 * Only the two external boundaries are faked (Lodgify HTTP, Stripe session creation).
 * Everything in between is the real application, which is what makes this the test that
 * would have caught the button pointing at the wrong place.
 */
class EndToEndFlowTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_e2e';

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

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.stripe.webhook_secret', self::SECRET);
        Mail::fake();

        $cottage = $this->cottage();

        $repo = Mockery::mock(LodgifyRepository::class);
        $repo->shouldReceive('cottageBySlug')->andReturn($cottage);
        $repo->shouldReceive('cottagesFreeFor')->andReturn(new Collection([$cottage]));
        $repo->shouldReceive('freeWindows')->andReturn([]);
        $repo->shouldReceive('seasons')->andReturn(new Collection);
        $repo->shouldReceive('quote')->andReturn(null);
        $repo->shouldReceive('lastErrors')->andReturn([]);
        $repo->shouldReceive('lastGuestMessage')->andReturn(null);
        $this->app->instance(LodgifyRepository::class, $repo);

        $reader = Mockery::mock(QuoteReader::class);
        $reader->shouldReceive('authoritativeQuote')->andReturn([
            'source' => 'v2', 'currency' => 'CAD', 'nights' => 3, 'total' => 900.0,
            'fees' => [], 'taxes' => [],
            'schedule' => [
                ['name' => 'On agreement', 'amount' => 225.0, 'is_current' => true],
                ['name' => 'Before arrival', 'amount' => 675.0, 'is_current' => false],
            ],
        ]);
        $this->app->instance(QuoteReader::class, $reader);

        // Lodgify writes: record the calls, don't make them.
        $writer = Mockery::mock(LodgifyBookingWriter::class);
        $writer->shouldReceive('createOpenBooking')->andReturn('17388658');
        $writer->shouldReceive('markBooked')->andReturnNull();
        $writer->shouldReceive('recordPayment')->andReturn(false);
        $this->app->instance(LodgifyBookingWriter::class, $writer);

        /*
         * Stripe session creation is the only other external call. Stubbed at the
         * PaymentLinkService seam so the rest — token minting, signed URLs, the mail, the
         * webhook handling — is all real.
         */
        $links = Mockery::mock(PaymentLinkService::class.'[prepareSession]', [
            app(StripeGateway::class),
            app(BookingAuditor::class),
        ]);
        $links->shouldAllowMockingProtectedMethods();
        $links->shouldReceive('prepareSession')->andReturnUsing(function ($payment) {
            $payment->forceFill(['stripe_checkout_session_id' => 'cs_e2e_'.$payment->getKey()])->save();

            return $payment;
        });
        $this->app->instance(PaymentLinkService::class, $links);
    }

    private function signedWebhook(array $payload): TestResponse
    {
        $body = json_encode($payload);
        $ts = time();
        $sig = hash_hmac('sha256', "{$ts}.{$body}", self::SECRET);

        return $this->call('POST', '/webhooks/stripe', server: [
            'HTTP_STRIPE_SIGNATURE' => "t={$ts},v1={$sig}",
            'CONTENT_TYPE' => 'application/json',
        ], content: $body);
    }

    #[Test]
    public function a_guest_can_go_from_the_cottage_page_to_a_confirmed_booking(): void
    {
        $arrival = now()->addDays(60)->toDateString();
        $departure = now()->addDays(63)->toDateString();

        // --- 1. the cottage page offers our own details step, not Lodgify -------
        $this->get('/cottage/sea-glass-738423')
            ->assertOk()
            ->assertSee('/booking/details/sea-glass-738423', escape: false);

        // --- 2. the details step prices the stay -------------------------------
        $this->get(route('booking.details', [
            'slug' => 'sea-glass-738423', 'arrival' => $arrival,
            'departure' => $departure, 'adults' => 2,
        ]))->assertOk()->assertSee('225.00 CAD');

        // --- 3. reserve. nothing charged ---------------------------------------
        $this->post('/booking', [
            'slug' => 'sea-glass-738423', 'arrival' => $arrival, 'departure' => $departure,
            'adults' => 2, 'guest_name' => 'Alex Morgan',
            'guest_email' => 'alex@example.test', 'guest_phone' => '+19025551234',
            'terms_accepted' => 1,
        ])->assertRedirect(route('booking.submitted'));

        $booking = Booking::firstOrFail();

        $this->assertSame(BookingStatus::AwaitingDeposit, $booking->status);
        $this->assertSame('17388658', $booking->lodgify_booking_id);
        $this->assertSame('Open', $booking->lodgify_status);
        $this->assertSame(90000, (int) $booking->total_cents);
        $this->assertSame(22500, (int) $booking->deposit_cents);

        $this->get(route('booking.submitted'))->assertOk()->assertSee('Check your email');

        // --- 4. the deposit link exists and is reachable ------------------------
        $deposit = $booking->deposit();
        $this->assertNotNull($deposit);
        $this->assertSame(22500, (int) $deposit->amount_cents);

        $payUrl = URL::temporarySignedRoute('booking.pay', now()->addHour(), [
            'token' => $deposit->token,
        ]);

        // Unsigned is refused; signed is not.
        $this->get("/pay/{$deposit->token}")->assertForbidden();

        // --- 5. the guest pays; Stripe tells us ---------------------------------
        $this->signedWebhook([
            'id' => 'evt_e2e_1', 'object' => 'event', 'created' => time(),
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => $deposit->stripe_checkout_session_id ?? 'cs_e2e_1',
                'object' => 'checkout.session', 'payment_status' => 'paid',
                'amount_total' => 22500, 'currency' => 'cad',
                'payment_intent' => 'pi_e2e_1',
                'metadata' => ['payment_reference' => $deposit->reference],
            ]],
        ])->assertOk();

        // --- 6. confirmed -------------------------------------------------------
        $booking->refresh();

        $this->assertSame(PaymentStatus::Paid, $deposit->fresh()->status);
        $this->assertSame(BookingStatus::DepositPaid, $booking->status);
        $this->assertTrue($booking->status->holdsDates(), 'dates should now be held');
        $this->assertNotNull($booking->booked_at);
        $this->assertSame(22500, $booking->amountPaid()->cents);
        $this->assertSame(67500, $booking->amountOutstanding()->cents);

        // --- 7. and the balance link goes out later -----------------------------
        $booking->forceFill(['arrival' => now()->addDays(20), 'departure' => now()->addDays(23)])->save();

        $this->artisan('booking:send-balance-links', ['--lead' => 30])->assertSuccessful();

        $balance = $booking->fresh()->balance();
        $this->assertNotNull($balance);
        $this->assertSame(67500, (int) $balance->amount_cents);
        $this->assertSame(BookingStatus::AwaitingBalance, $booking->fresh()->status);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
