<?php

namespace Tests\Feature\Booking;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Jobs\MarkLodgifyBookingBooked;
use App\Models\Booking;
use App\Models\BookingAuditLog;
use App\Models\BookingPayment;
use App\Models\StripeWebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The webhook is the security boundary of the payments feature: it is public,
 * CSRF-exempt, and it is what confirms bookings. These tests pin the three properties
 * that make it safe — signature verification, replay immunity, and amount verification.
 */
class StripeWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_test_secret';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.stripe.webhook_secret', self::SECRET);

        Bus::fake();
        Mail::fake();
    }

    /**
     * Build a correctly signed request, exactly as Stripe does: the signed payload is
     * "{timestamp}.{raw body}", HMAC-SHA256 with the endpoint secret.
     */
    private function signedPost(array $payload, ?string $secret = null, ?int $timestamp = null): TestResponse
    {
        $body = json_encode($payload);
        $timestamp ??= time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$body}", $secret ?? self::SECRET);

        return $this->call(
            'POST',
            '/webhooks/stripe',
            server: [
                'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
                'CONTENT_TYPE' => 'application/json',
            ],
            content: $body,
        );
    }

    private function sessionCompleted(BookingPayment $payment, ?int $amountCents = null, string $eventId = 'evt_test_1'): array
    {
        return [
            'id' => $eventId,
            'object' => 'event',
            'api_version' => '2026-07-29.dahlia',
            'type' => 'checkout.session.completed',
            'created' => time(),
            'data' => ['object' => [
                'id' => $payment->stripe_checkout_session_id ?? 'cs_test_x',
                'object' => 'checkout.session',
                'payment_status' => 'paid',
                'amount_total' => $amountCents ?? (int) $payment->amount_cents,
                'currency' => strtolower($payment->currency),
                'payment_intent' => 'pi_test_123',
                'customer' => 'cus_test_123',
                'metadata' => [
                    'payment_reference' => $payment->reference,
                    'booking_reference' => $payment->booking->reference,
                ],
            ]],
        ];
    }

    private function awaitingDepositPayment(): BookingPayment
    {
        $booking = Booking::factory()->awaitingDeposit()->create([
            'total_cents' => 100000,
            'deposit_cents' => 25000,
            'balance_cents' => 75000,
        ]);

        return BookingPayment::factory()->deposit()->linkSent()->create([
            'booking_id' => $booking->getKey(),
            'amount_cents' => 25000,
            'currency' => 'CAD',
        ]);
    }

    // ================================================== signature verification

    #[Test]
    public function it_rejects_a_request_with_no_signature(): void
    {
        $response = $this->postJson('/webhooks/stripe', ['id' => 'evt_x', 'type' => 'checkout.session.completed']);

        $response->assertStatus(400);
        $this->assertDatabaseCount('stripe_webhook_events', 0);
    }

    #[Test]
    public function it_rejects_a_forged_signature(): void
    {
        /*
         * The attack this prevents: anyone who knows the URL posting a
         * checkout.session.completed to confirm a booking for free.
         */
        $payment = $this->awaitingDepositPayment();

        $response = $this->signedPost($this->sessionCompleted($payment), secret: 'whsec_wrong_secret');

        $response->assertStatus(400);
        $this->assertDatabaseCount('stripe_webhook_events', 0);
        $this->assertSame(PaymentStatus::LinkSent, $payment->fresh()->status);
        $this->assertSame(BookingStatus::AwaitingDeposit, $payment->booking->fresh()->status);
    }

    #[Test]
    public function it_rejects_a_replayed_signature_outside_the_tolerance(): void
    {
        // A captured-and-replayed request from an hour ago must not be accepted.
        config()->set('services.stripe.webhook_tolerance', 300);

        $payment = $this->awaitingDepositPayment();

        $response = $this->signedPost($this->sessionCompleted($payment), timestamp: time() - 3600);

        $response->assertStatus(400);
        $this->assertDatabaseCount('stripe_webhook_events', 0);
    }

    #[Test]
    public function it_rejects_everything_when_no_webhook_secret_is_configured(): void
    {
        // Fail closed. An unset secret must not mean "accept anything".
        config()->set('services.stripe.webhook_secret', '');

        $payment = $this->awaitingDepositPayment();

        $this->signedPost($this->sessionCompleted($payment))->assertStatus(400);
    }

    // ============================================================ happy path

    #[Test]
    public function a_valid_deposit_payment_confirms_the_booking(): void
    {
        $payment = $this->awaitingDepositPayment();

        $this->signedPost($this->sessionCompleted($payment))->assertOk();

        $payment->refresh();
        $booking = $payment->booking->fresh();

        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertSame(25000, (int) $payment->amount_received_cents);
        $this->assertNotNull($payment->paid_at);
        $this->assertSame('pi_test_123', $payment->stripe_payment_intent_id);

        $this->assertSame(BookingStatus::DepositPaid, $booking->status);
        $this->assertNotNull($booking->booked_at);

        // The Lodgify write is QUEUED, never inline — a Lodgify outage must not fail a
        // webhook and strand a paid booking.
        Bus::assertDispatched(MarkLodgifyBookingBooked::class);

        $this->assertDatabaseHas('stripe_webhook_events', [
            'stripe_event_id' => 'evt_test_1',
            'status' => StripeWebhookEvent::STATUS_PROCESSED,
        ]);

        $this->assertDatabaseHas('booking_audit_logs', ['event' => 'payment.succeeded']);
    }

    #[Test]
    public function a_balance_payment_settles_the_booking_in_full(): void
    {
        $booking = Booking::factory()->depositPaid()->create([
            'total_cents' => 100000, 'deposit_cents' => 25000, 'balance_cents' => 75000,
        ]);

        BookingPayment::factory()->deposit()->paid()->create([
            'booking_id' => $booking->getKey(), 'amount_cents' => 25000,
        ]);

        $balance = BookingPayment::factory()->balance()->linkSent()->create([
            'booking_id' => $booking->getKey(), 'amount_cents' => 75000, 'currency' => 'CAD',
        ]);

        $this->signedPost($this->sessionCompleted($balance, eventId: 'evt_balance_1'))->assertOk();

        $this->assertSame(BookingStatus::PaidInFull, $booking->fresh()->status);
        $this->assertTrue($booking->fresh()->amountOutstanding()->isZero());
    }

    // ============================================================ idempotency

    #[Test]
    public function replaying_the_same_event_is_a_no_op(): void
    {
        /*
         * Stripe guarantees at-least-once delivery, so this is the normal case, not an
         * edge case. The second delivery must not double-count the payment, send a second
         * confirmation, or fire a second Lodgify write.
         */
        $payment = $this->awaitingDepositPayment();
        $event = $this->sessionCompleted($payment);

        $this->signedPost($event)->assertOk();
        $this->signedPost($event)->assertOk();     // identical delivery

        $this->assertDatabaseCount('stripe_webhook_events', 1);
        Bus::assertDispatchedTimes(MarkLodgifyBookingBooked::class, 1);
        Mail::assertSentCount(1);

        // Exactly one settlement recorded in the trail.
        $this->assertSame(
            1,
            BookingAuditLog::where('event', 'payment.succeeded')->count()
        );
    }

    #[Test]
    public function two_distinct_events_for_an_already_paid_payment_settle_only_once(): void
    {
        // Different event ids (so the dedup table does not catch it) but the same payment.
        $payment = $this->awaitingDepositPayment();

        $this->signedPost($this->sessionCompleted($payment, eventId: 'evt_a'))->assertOk();
        $this->signedPost($this->sessionCompleted($payment, eventId: 'evt_b'))->assertOk();

        $this->assertDatabaseCount('stripe_webhook_events', 2);
        Bus::assertDispatchedTimes(MarkLodgifyBookingBooked::class, 1);

        $this->assertDatabaseHas('booking_audit_logs', [
            'event' => 'payment.settle_ignored_already_paid',
        ]);
    }

    // ==================================================== amount verification

    #[Test]
    public function it_refuses_to_confirm_a_booking_for_the_wrong_amount(): void
    {
        /*
         * A session that captured less than we asked for means either we built it wrong or
         * something tampered with it. Confirming a reservation on that basis is worse than
         * failing — the money is in Stripe and a human can sort it out.
         */
        $payment = $this->awaitingDepositPayment();

        $this->signedPost($this->sessionCompleted($payment, amountCents: 100))->assertOk();

        $payment->refresh();

        $this->assertSame(PaymentStatus::Failed, $payment->status);
        $this->assertSame(100, (int) $payment->amount_received_cents);
        $this->assertStringContainsString('mismatch', (string) $payment->failure_reason);

        // Crucially: NOT confirmed, and no Lodgify write.
        $this->assertSame(BookingStatus::AwaitingDeposit, $payment->booking->fresh()->status);
        Bus::assertNotDispatched(MarkLodgifyBookingBooked::class);

        $this->assertDatabaseHas('booking_audit_logs', ['event' => 'payment.amount_mismatch']);
    }

    // ================================================= asynchronous payments

    #[Test]
    public function a_completed_but_unpaid_session_does_not_confirm_the_booking(): void
    {
        /*
         * Some methods complete the session with payment_status=unpaid and settle later.
         * Treating completion as payment would confirm a booking against money that has
         * not arrived.
         */
        $payment = $this->awaitingDepositPayment();

        $event = $this->sessionCompleted($payment);
        $event['data']['object']['payment_status'] = 'unpaid';

        $this->signedPost($event)->assertOk();

        $this->assertSame(PaymentStatus::Processing, $payment->fresh()->status);
        $this->assertSame(BookingStatus::AwaitingDeposit, $payment->booking->fresh()->status);
        Bus::assertNotDispatched(MarkLodgifyBookingBooked::class);
    }

    #[Test]
    public function an_async_success_settles_a_processing_payment(): void
    {
        $payment = $this->awaitingDepositPayment();
        $payment->forceFill(['status' => PaymentStatus::Processing])->save();

        $event = $this->sessionCompleted($payment, eventId: 'evt_async_ok');
        $event['type'] = 'checkout.session.async_payment_succeeded';

        $this->signedPost($event)->assertOk();

        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame(BookingStatus::DepositPaid, $payment->booking->fresh()->status);
    }

    // ============================================================== the rest

    #[Test]
    public function an_unknown_event_type_is_recorded_and_ignored(): void
    {
        $payment = $this->awaitingDepositPayment();

        $event = $this->sessionCompleted($payment, eventId: 'evt_unknown');
        $event['type'] = 'customer.subscription.updated';

        $this->signedPost($event)->assertOk();

        $this->assertDatabaseHas('stripe_webhook_events', [
            'stripe_event_id' => 'evt_unknown',
            'status' => StripeWebhookEvent::STATUS_IGNORED,
        ]);
        $this->assertSame(PaymentStatus::LinkSent, $payment->fresh()->status);
    }

    #[Test]
    public function an_event_for_an_unknown_payment_is_ignored_not_failed(): void
    {
        // A payment from a different integration on the same Stripe account. Answering
        // non-200 would make Stripe retry something we will never handle.
        $event = [
            'id' => 'evt_foreign', 'object' => 'event', 'created' => time(),
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => 'cs_not_ours', 'object' => 'checkout.session',
                'payment_status' => 'paid', 'amount_total' => 5000, 'currency' => 'cad',
                'metadata' => [],
            ]],
        ];

        $this->signedPost($event)->assertOk();

        $this->assertDatabaseHas('stripe_webhook_events', [
            'stripe_event_id' => 'evt_foreign',
            'status' => StripeWebhookEvent::STATUS_IGNORED,
        ]);
    }

    #[Test]
    public function a_refund_is_recorded_but_does_not_cancel_the_reservation(): void
    {
        /*
         * A partial refund, a goodwill gesture and a real cancellation are
         * indistinguishable here. Unbooking someone's stay is not a decision to take from
         * a webhook.
         */
        $booking = Booking::factory()->depositPaid()->create();
        $payment = BookingPayment::factory()->deposit()->paid()->create([
            'booking_id' => $booking->getKey(),
            'amount_cents' => 25000,
            'stripe_charge_id' => 'ch_test_1',
        ]);

        $this->signedPost([
            'id' => 'evt_refund', 'object' => 'event', 'created' => time(),
            'type' => 'charge.refunded',
            'data' => ['object' => [
                'id' => 'ch_test_1', 'object' => 'charge',
                'amount_refunded' => 25000, 'payment_intent' => 'pi_test_1',
            ]],
        ])->assertOk();

        $this->assertSame(PaymentStatus::Refunded, $payment->fresh()->status);
        $this->assertSame(25000, (int) $payment->fresh()->refunded_cents);

        // Reservation untouched.
        $this->assertSame(BookingStatus::DepositPaid, $booking->fresh()->status);
    }
}
