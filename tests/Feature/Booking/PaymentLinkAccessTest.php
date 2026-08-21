<?php

namespace Tests\Feature\Booking;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Access control on payment links.
 *
 * A payment URL identifies a booking, an amount and a guest, so it must not be guessable,
 * enumerable, or usable after it expires.
 */
class PaymentLinkAccessTest extends TestCase
{
    use RefreshDatabase;

    private function payment(array $attrs = []): BookingPayment
    {
        $booking = Booking::factory()->awaitingDeposit()->create();

        return BookingPayment::factory()->deposit()->create(
            array_merge(['booking_id' => $booking->getKey()], $attrs)
        );
    }

    #[Test]
    public function an_unsigned_payment_url_is_rejected(): void
    {
        $payment = $this->payment();

        // The bare path, without a signature. Must not work.
        $this->get("/pay/{$payment->token}")->assertForbidden();
    }

    #[Test]
    public function a_tampered_signature_is_rejected(): void
    {
        $payment = $this->payment();

        $url = URL::temporarySignedRoute('booking.pay', now()->addHour(), ['token' => $payment->token]);

        $this->get($url.'x')->assertForbidden();
    }

    #[Test]
    public function an_expired_signature_is_rejected(): void
    {
        $payment = $this->payment();

        $url = URL::temporarySignedRoute('booking.pay', now()->subMinute(), ['token' => $payment->token]);

        $this->get($url)->assertForbidden();
    }

    #[Test]
    public function a_signature_for_one_token_does_not_work_for_another(): void
    {
        /*
         * The enumeration guard. Holding a valid link for your own booking must not let you
         * substitute somebody else's token.
         */
        $mine = $this->payment();
        $someones = $this->payment();

        $url = URL::temporarySignedRoute('booking.pay', now()->addHour(), ['token' => $mine->token]);

        $swapped = str_replace($mine->token, $someones->token, $url);

        $this->get($swapped)->assertForbidden();
    }

    #[Test]
    public function an_unknown_token_is_a_404_with_no_detail(): void
    {
        $url = URL::temporarySignedRoute('booking.pay', now()->addHour(), [
            'token' => bin2hex(random_bytes(32)),
        ]);

        $this->get($url)->assertNotFound();
    }

    #[Test]
    public function tokens_are_long_and_random_rather_than_sequential(): void
    {
        $tokens = collect(range(1, 10))->map(fn () => $this->payment()->token);

        $this->assertCount(10, $tokens->unique());

        foreach ($tokens as $token) {
            $this->assertSame(64, strlen($token), 'token should be 32 random bytes hex-encoded');
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        }
    }

    #[Test]
    public function an_already_paid_link_shows_a_receipt_rather_than_charging_again(): void
    {
        $booking = Booking::factory()->depositPaid()->create();
        $payment = BookingPayment::factory()->deposit()->paid()->create([
            'booking_id' => $booking->getKey(),
        ]);

        $url = URL::temporarySignedRoute('booking.pay', now()->addHour(), ['token' => $payment->token]);

        $this->get($url)
            ->assertOk()
            ->assertSee("That's already paid", escape: false);
    }

    #[Test]
    public function a_link_for_a_terminal_booking_is_unavailable(): void
    {
        $booking = Booking::factory()->awaitingDeposit()->create();
        $booking->transitionTo(BookingStatus::Expired);

        $payment = BookingPayment::factory()->deposit()->create(['booking_id' => $booking->getKey()]);

        $url = URL::temporarySignedRoute('booking.pay', now()->addHour(), ['token' => $payment->token]);

        $this->get($url)
            ->assertOk()
            ->assertSee('no longer active', escape: false);
    }

    #[Test]
    public function the_cancelled_return_page_does_not_require_a_signature(): void
    {
        /*
         * Stripe redirects the browser to the return URLs and appends its own query
         * parameters, which would break a signature. Safe because these pages grant
         * nothing — they only read state.
         */
        $payment = $this->payment();

        $this->get(route('booking.pay.cancelled', ['token' => $payment->token]))
            ->assertOk()
            ->assertSee('No payment taken', escape: false);
    }

    #[Test]
    public function opening_a_link_is_recorded_in_the_audit_trail(): void
    {
        $booking = Booking::factory()->depositPaid()->create();
        $payment = BookingPayment::factory()->deposit()->paid()->create([
            'booking_id' => $booking->getKey(),
        ]);

        $url = URL::temporarySignedRoute('booking.pay', now()->addHour(), ['token' => $payment->token]);
        $this->get($url)->assertOk();

        $this->assertDatabaseHas('booking_audit_logs', [
            'booking_id' => $booking->getKey(),
            'event' => 'payment.link_opened',
        ]);
    }
}
