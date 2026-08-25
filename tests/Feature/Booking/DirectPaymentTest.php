<?php

namespace Tests\Feature\Booking;

use App\DTO\Cottage;
use App\Enums\BookingStatus;
use App\Jobs\SendPaymentLink;
use App\Models\Booking;
use App\Models\User;
use App\Services\Booking\QuoteReader;
use App\Services\Lodgify\LodgifyBookingWriter;
use App\Services\Lodgify\LodgifyRepository;
use App\Services\Payments\StripeGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Stripe\Checkout\Session;
use Tests\TestCase;

/**
 * Booking directly: a signed-in, verified guest goes from the confirm button to Stripe
 * without waiting for an emailed link.
 *
 * WHAT THESE TESTS ARE REALLY PROTECTING
 *
 * The emailed link is not only a delivery mechanism — opening it proves the address on the
 * booking reaches the person paying. Skipping it is safe ONLY for an account that has already
 * proved that, booking its own address. So most of what follows is the fallback: every guest
 * who does not meet that bar must still get the email, and posting pay_now=1 must never be
 * enough on its own.
 *
 * Nothing here charges anything, and no card is stored on either path — see
 * NoCardSavingTest.
 */
class DirectPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake();
        Mail::fake();

        $cottage = $this->cottage();

        $repo = Mockery::mock(LodgifyRepository::class);
        $repo->shouldReceive('cottageBySlug')->andReturn($cottage);
        $repo->shouldReceive('cottagesFreeFor')->andReturn(new Collection([$cottage]));
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

        $writer = Mockery::mock(LodgifyBookingWriter::class);
        $writer->shouldReceive('createOpenBooking')->andReturn('17388658');
        $this->app->instance(LodgifyBookingWriter::class, $writer);
    }

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

    /** @param array<string, mixed> $overrides */
    private function form(array $overrides = []): array
    {
        return array_merge([
            'slug' => 'sea-glass-738423',
            'arrival' => now()->addDays(60)->toDateString(),
            'departure' => now()->addDays(63)->toDateString(),
            'adults' => 2,
            'guest_name' => 'Alex Morgan',
            'guest_email' => 'alex@example.test',
            'guest_phone' => '+19025551234',
            'terms_accepted' => 1,
        ], $overrides);
    }

    private function verifiedUser(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'name' => 'Alex Morgan',
            'email' => 'alex@example.test',
        ], $attributes));
    }

    #[Test]
    public function a_verified_guest_is_taken_straight_to_their_payment(): void
    {
        $user = $this->verifiedUser();

        $response = $this->actingAs($user)->post('/booking', $this->form(['pay_now' => 1]));

        $booking = Booking::firstOrFail();
        $deposit = $booking->deposit();

        $this->assertNotNull($deposit);

        // Our own signed pay route, not a Stripe URL: that page creates the session and
        // records the attempt, and re-mints it if the guest comes back later.
        $response->assertRedirectContains("/pay/{$deposit->token}");
        $response->assertRedirectContains('signature=');

        // Same booking as any other: Lodgify holds it Open, we hold the money lifecycle.
        $this->assertSame(BookingStatus::AwaitingDeposit, $booking->status);
        $this->assertSame(22500, (int) $deposit->amount_cents);
        $this->assertSame($user->id, (int) $booking->user_id);
    }

    #[Test]
    public function the_email_is_only_a_delayed_fallback_on_the_direct_path(): void
    {
        $this->actingAs($this->verifiedUser())
            ->post('/booking', $this->form(['pay_now' => 1]));

        /*
         * Queued with a delay rather than not at all: a guest who abandons Stripe would
         * otherwise hold a reservation with no way to pay for it. The job returns early once
         * the payment settles, so paying immediately means no email is ever sent.
         */
        Bus::assertDispatched(
            SendPaymentLink::class,
            fn (SendPaymentLink $job) => $job->delay !== null,
        );
    }

    #[Test]
    public function a_signed_out_guest_still_gets_the_emailed_link(): void
    {
        // pay_now is a request, not a permission. Anyone can post it.
        $response = $this->post('/booking', $this->form(['pay_now' => 1]));

        $response->assertRedirect(route('booking.submitted'));

        Bus::assertDispatched(
            SendPaymentLink::class,
            fn (SendPaymentLink $job) => $job->delay === null,
        );
    }

    #[Test]
    public function an_unverified_account_still_gets_the_emailed_link(): void
    {
        /*
         * The whole justification for skipping the email is that a verified account has
         * already proved the address. Without verification the email is the proof, so it
         * must go.
         */
        $user = User::factory()->unverified()->create(['email' => 'alex@example.test']);

        $this->actingAs($user)
            ->post('/booking', $this->form(['pay_now' => 1]))
            ->assertRedirect(route('booking.submitted'));

        Bus::assertDispatched(
            SendPaymentLink::class,
            fn (SendPaymentLink $job) => $job->delay === null,
        );
    }

    #[Test]
    public function booking_someone_elses_address_falls_back_to_the_email(): void
    {
        /*
         * The case this closes: signing in with a verified account and then booking under a
         * different address would otherwise skip the one step that proves that inbox is
         * reachable by the person paying.
         */
        $this->actingAs($this->verifiedUser(['email' => 'alex@example.test']))
            ->post('/booking', $this->form([
                'pay_now' => 1,
                'guest_email' => 'someone.else@example.test',
            ]))
            ->assertRedirect(route('booking.submitted'));

        Bus::assertDispatched(
            SendPaymentLink::class,
            fn (SendPaymentLink $job) => $job->delay === null,
        );
    }

    #[Test]
    public function the_config_switch_closes_the_post_path_not_just_the_button(): void
    {
        config()->set('booking.direct_pay.enabled', false);

        $this->actingAs($this->verifiedUser())
            ->post('/booking', $this->form(['pay_now' => 1]))
            ->assertRedirect(route('booking.submitted'));
    }

    #[Test]
    public function asking_for_the_email_while_signed_in_is_honoured(): void
    {
        // The second button on the details form. A guest who would rather pay later must be
        // able to say so.
        $this->actingAs($this->verifiedUser())
            ->post('/booking', $this->form(['pay_now' => 0]))
            ->assertRedirect(route('booking.submitted'));

        Bus::assertDispatched(
            SendPaymentLink::class,
            fn (SendPaymentLink $job) => $job->delay === null,
        );
    }

    #[Test]
    public function the_details_page_prefills_a_signed_in_guest_and_offers_to_take_payment(): void
    {
        $user = $this->verifiedUser(['phone' => '+19025559876', 'country' => 'CA']);

        $this->actingAs($user)->get(route('booking.details', [
            'slug' => 'sea-glass-738423',
            'arrival' => now()->addDays(60)->toDateString(),
            'departure' => now()->addDays(63)->toDateString(),
            'adults' => 2,
        ]))
            ->assertOk()
            ->assertSee('+19025559876')                 // phone, which the form could not fill before
            ->assertSee('alex@example.test')
            ->assertSee('Reserve &amp; pay 225.00 CAD now', escape: false)
            ->assertSee('nothing is saved for next time');
    }

    #[Test]
    public function a_signed_out_visitor_sees_only_the_emailed_link_option(): void
    {
        $this->get(route('booking.details', [
            'slug' => 'sea-glass-738423',
            'arrival' => now()->addDays(60)->toDateString(),
            'departure' => now()->addDays(63)->toDateString(),
            'adults' => 2,
        ]))
            ->assertOk()
            ->assertSee('Reserve &amp; send me the payment link', escape: false)
            ->assertDontSee('Reserve &amp; pay', escape: false);
    }

    #[Test]
    public function details_given_at_booking_fill_blanks_on_the_profile(): void
    {
        $user = $this->verifiedUser(['phone' => null, 'country' => null]);

        $this->actingAs($user)->post('/booking', $this->form([
            'pay_now' => 1,
            'guest_phone' => '+19025550000',
            'guest_country' => 'CA',
        ]));

        $user->refresh();

        $this->assertSame('+19025550000', $user->phone);
        $this->assertSame('CA', $user->country);
    }

    #[Test]
    public function a_booking_for_someone_else_does_not_rewrite_the_account_holders_details(): void
    {
        /*
         * Filling blanks is a convenience; overwriting is a bug. A guest booking a stay for
         * their parents should not end up with their parents' phone number on their account.
         */
        $user = $this->verifiedUser(['phone' => '+19025551111', 'country' => 'CA']);

        $this->actingAs($user)->post('/booking', $this->form([
            'pay_now' => 1,
            'guest_phone' => '+15145559999',
            'guest_country' => 'US',
        ]));

        $user->refresh();

        $this->assertSame('+19025551111', $user->phone);
        $this->assertSame('CA', $user->country);
    }

    #[Test]
    public function following_the_redirect_reaches_stripe_and_records_the_attempt(): void
    {
        /*
         * The two halves of this change, joined: the direct flow hands the guest to our pay
         * page, and that page is what opens the Stripe session and writes the attempt line.
         * Only the Stripe call itself is faked.
         */
        $logPath = storage_path('logs/testing-direct-pay.log');

        config()->set('logging.channels.payments', [
            'driver' => 'single', 'path' => $logPath, 'level' => 'debug',
        ]);

        if (file_exists($logPath)) {
            unlink($logPath);
        }

        $gateway = Mockery::mock(StripeGateway::class);
        $gateway->shouldReceive('createCheckoutSession')->andReturn(
            Session::constructFrom([
                'id' => 'cs_direct_1',
                'url' => 'https://checkout.stripe.com/c/pay/cs_direct_1',
                'status' => 'open',
            ])
        );
        $this->app->instance(StripeGateway::class, $gateway);

        $redirect = $this->actingAs($this->verifiedUser())
            ->post('/booking', $this->form(['pay_now' => 1]))
            ->headers->get('Location');

        $this->get($redirect)
            ->assertRedirect('https://checkout.stripe.com/c/pay/cs_direct_1');

        $log = (string) file_get_contents($logPath);

        $this->assertStringContainsString('"attempt":"reached_stripe"', $log);
        // `direct` because no link was emailed before the guest got there — which is what
        // distinguishes an abandoned direct booking from an abandoned emailed one.
        $this->assertStringContainsString('"mode":"direct"', $log);
        $this->assertStringContainsString('cs_direct_1', $log);

        unlink($logPath);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
