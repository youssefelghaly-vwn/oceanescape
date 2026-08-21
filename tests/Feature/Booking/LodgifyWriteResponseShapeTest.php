<?php

namespace Tests\Feature\Booking;

use App\Exceptions\LodgifyWriteFailed;
use App\Models\Booking;
use App\Models\BookingPayment;
use App\Services\Lodgify\LodgifyBookingWriter;
use App\Services\Lodgify\LodgifyClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Response shapes from Lodgify's v1 booking WRITE endpoints.
 *
 * These go through the real LodgifyClient with only the HTTP layer faked, because the bug
 * they pin lived in the client's return type rather than in any logic:
 * `createBooking(): array` threw a TypeError on a bare-integer response, AFTER the request
 * had succeeded — so Lodgify created a reservation and we threw away its id.
 *
 * CONFIRMED AGAINST A LIVE ACCOUNT: POST /v1/reservation/booking answers with a bare
 * integer id, e.g. `17388658`.
 */
class LodgifyWriteResponseShapeTest extends TestCase
{
    use RefreshDatabase;

    private function booking(): Booking
    {
        return Booking::factory()->create([
            'cottage_id' => 738423,
            'room_type_id' => 805539,
            'currency' => 'CAD',
        ]);
    }

    private function writer(): LodgifyBookingWriter
    {
        return new LodgifyBookingWriter(app(LodgifyClient::class));
    }

    #[Test]
    public function a_bare_integer_id_is_accepted(): void
    {
        // The real shape. This is the case that crashed in production.
        Http::fake([
            '*/v1/reservation/booking' => Http::response('17388658', 200, ['Content-Type' => 'application/json']),
        ]);

        $id = $this->writer()->createOpenBooking($this->booking());

        $this->assertSame('17388658', $id);
    }

    #[Test]
    public function a_quoted_string_id_is_accepted(): void
    {
        Http::fake([
            '*/v1/reservation/booking' => Http::response('"17388658"', 200, ['Content-Type' => 'application/json']),
        ]);

        $this->assertSame('17388658', $this->writer()->createOpenBooking($this->booking()));
    }

    #[Test]
    public function an_object_response_is_accepted(): void
    {
        Http::fake([
            '*/v1/reservation/booking' => Http::response(['id' => 17388658, 'status' => 'Open'], 200),
        ]);

        $this->assertSame('17388658', $this->writer()->createOpenBooking($this->booking()));
    }

    #[Test]
    public function a_wrapped_object_response_is_accepted(): void
    {
        Http::fake([
            '*/v1/reservation/booking' => Http::response(['data' => ['id' => 999]], 200),
        ]);

        $this->assertSame('999', $this->writer()->createOpenBooking($this->booking()));
    }

    #[Test]
    public function a_success_with_no_usable_id_fails_loudly(): void
    {
        /*
         * The dangerous case: 2xx but nothing we can reference. A reservation may exist, so
         * this must fail rather than pretend it worked.
         */
        Http::fake([
            '*/v1/reservation/booking' => Http::response(['ok' => true], 200),
        ]);

        $this->expectException(LodgifyWriteFailed::class);
        $this->expectExceptionMessageMatches('/no id|reconciliation/i');

        $this->writer()->createOpenBooking($this->booking());
    }

    #[Test]
    public function an_api_error_is_reported_as_no_money_at_risk(): void
    {
        Http::fake([
            '*/v1/reservation/booking' => Http::response(['message' => 'Invalid dates'], 422),
        ]);

        try {
            $this->writer()->createOpenBooking($this->booking());
            $this->fail('expected LodgifyWriteFailed');
        } catch (LodgifyWriteFailed $e) {
            $this->assertSame(422, $e->status);
            // Nothing is charged at creation time, so the guest can safely be told.
            $this->assertFalse($e->moneyAtRisk);
            $this->assertStringContainsString('Nothing has been charged', (string) $e->guestMessage());
        }
    }

    #[Test]
    public function mark_booked_tolerates_an_empty_body(): void
    {
        // A 200 with no body is a normal answer from these v1 routes.
        Http::fake(['*/book' => Http::response('', 200)]);

        $booking = Booking::factory()->awaitingDeposit()->create();

        $this->writer()->markBooked($booking);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/book')
            && $request->method() === 'PUT');
    }

    #[Test]
    public function mark_booked_tolerates_a_bare_boolean_body(): void
    {
        Http::fake(['*/book' => Http::response('true', 200, ['Content-Type' => 'application/json'])]);

        $this->writer()->markBooked(Booking::factory()->awaitingDeposit()->create());

        $this->assertTrue(true);   // no TypeError is the assertion
    }

    #[Test]
    public function mark_booked_failure_flags_money_at_risk(): void
    {
        // By the time this runs the guest has paid, so the severity must be different.
        Http::fake(['*/book' => Http::response(['message' => 'nope'], 500)]);

        try {
            $this->writer()->markBooked(Booking::factory()->awaitingDeposit()->create());
            $this->fail('expected LodgifyWriteFailed');
        } catch (LodgifyWriteFailed $e) {
            $this->assertTrue($e->moneyAtRisk);
            // Never tell a guest who has paid that something failed.
            $this->assertNull($e->guestMessage());
        }
    }

    #[Test]
    public function writes_are_not_retried_at_the_transport(): void
    {
        /*
         * Retrying a non-idempotent POST is how you create two reservations for the same
         * nights. Retry belongs at the job level, guarded on lodgify_booking_id.
         */
        Http::fake(['*/v1/reservation/booking' => Http::response(['message' => 'boom'], 500)]);

        try {
            $this->writer()->createOpenBooking($this->booking());
        } catch (LodgifyWriteFailed) {
            // expected
        }

        Http::assertSentCount(1);
    }

    #[Test]
    public function record_payment_does_not_call_anything_when_unconfigured(): void
    {
        config()->set('lodgify.write.record_payment_path', null);
        Http::fake();

        $booking = Booking::factory()->depositPaid()->create();
        $payment = BookingPayment::factory()->deposit()->paid()->create([
            'booking_id' => $booking->getKey(),
        ]);

        $this->assertFalse($this->writer()->recordPayment($payment));

        Http::assertNothingSent();
    }
}
