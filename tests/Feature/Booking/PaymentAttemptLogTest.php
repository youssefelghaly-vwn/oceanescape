<?php

namespace Tests\Feature\Booking;

use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\BookingPayment;
use App\Services\Booking\BookingAuditor;
use App\Services\Payments\PaymentAttemptLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The payment attempt log — storage/logs/payments-*.log.
 *
 * These tests assert on the FILE, not on a mocked logger, because the point of the channel
 * is that the file is readable and greppable during an incident. A mock would pass while the
 * channel was misconfigured and nothing was written anywhere.
 *
 * The channel is repointed at a `single` file for the run so the assertions do not have to
 * know today's date, which is the only difference from production.
 */
class PaymentAttemptLogTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_attempt_log';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('logging.channels.payments', [
            'driver' => 'single',
            'path' => $this->logPath(),
            'level' => 'debug',
        ]);

        config()->set('services.stripe.webhook_secret', self::SECRET);

        if (file_exists($this->logPath())) {
            unlink($this->logPath());
        }

        Bus::fake();
        Mail::fake();
    }

    protected function tearDown(): void
    {
        if (file_exists($this->logPath())) {
            unlink($this->logPath());
        }

        parent::tearDown();
    }

    private function logPath(): string
    {
        return storage_path('logs/testing-payment-attempts.log');
    }

    private function log(): string
    {
        return file_exists($this->logPath()) ? (string) file_get_contents($this->logPath()) : '';
    }

    private function signedPost(array $payload): TestResponse
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
    public function payment_events_are_written_to_the_payments_channel(): void
    {
        $payment = BookingPayment::factory()->for(Booking::factory())->create();

        app(BookingAuditor::class)->record('payment.created', $payment->booking, $payment, [
            'type' => 'deposit',
        ]);

        $log = $this->log();

        $this->assertStringContainsString('payment.created', $log);
        $this->assertStringContainsString($payment->reference, $log);
        $this->assertStringContainsString($payment->booking->reference, $log);
    }

    #[Test]
    public function booking_only_events_stay_out_of_the_payments_channel(): void
    {
        /*
         * The whole value of a separate channel is that it is only payments. A Lodgify
         * retry storm in here means the file is no better than the booking log.
         */
        $booking = Booking::factory()->create();

        app(BookingAuditor::class)->record('booking.created', $booking);
        app(BookingAuditor::class)->record('lodgify.mark_booked.attempt', $booking);

        $this->assertStringNotContainsString('booking.created', $this->log());
        $this->assertStringNotContainsString('mark_booked', $this->log());
    }

    #[Test]
    public function reporting_a_payment_back_to_lodgify_is_part_of_the_payment_story(): void
    {
        $payment = BookingPayment::factory()->for(Booking::factory())->create();

        app(BookingAuditor::class)->record('lodgify.record_payment.failed', $payment->booking, $payment);

        $this->assertStringContainsString('lodgify.record_payment.failed', $this->log());
    }

    #[Test]
    public function a_failure_is_logged_at_error_level_so_alerting_can_see_it(): void
    {
        $payment = BookingPayment::factory()->for(Booking::factory())->create();

        app(BookingAuditor::class)->recordFailure('payment.amount_mismatch', $payment->booking, $payment, [
            'requested_cents' => 22500,
            'captured_cents' => 100,
        ]);

        // Monolog writes the level into the line: "local.ERROR: payment.amount_mismatch".
        $this->assertMatchesRegularExpression(
            '/ERROR: payment\.amount_mismatch/',
            $this->log(),
        );
    }

    #[Test]
    public function nothing_sensitive_reaches_the_file(): void
    {
        $payment = BookingPayment::factory()->for(Booking::factory())->create();

        app(PaymentAttemptLog::class)->declined($payment, 'card_declined', 'Your card was declined.', [
            'card_number' => '4242424242424242',
            'client_secret' => 'pi_secret_xyz',
            'api_key' => 'sk_live_nope',
        ]);

        $log = $this->log();

        $this->assertStringContainsString('card_declined', $log, 'the decline REASON is the point of the line');
        $this->assertStringNotContainsString('4242', $log);
        $this->assertStringNotContainsString('pi_secret_xyz', $log);
        $this->assertStringNotContainsString('sk_live', $log);
    }

    #[Test]
    public function a_declined_card_is_logged_without_touching_the_booking(): void
    {
        $booking = Booking::factory()->awaitingDeposit()->create();
        $payment = BookingPayment::factory()->for($booking)->linkSent()->create();

        $this->signedPost([
            'id' => 'evt_declined_1',
            'object' => 'event',
            'created' => time(),
            'type' => 'payment_intent.payment_failed',
            'data' => ['object' => [
                'id' => 'pi_declined_1',
                'object' => 'payment_intent',
                'last_payment_error' => [
                    'code' => 'card_declined',
                    'decline_code' => 'insufficient_funds',
                    'message' => 'Your card has insufficient funds.',
                ],
                'metadata' => ['payment_reference' => $payment->reference],
            ]],
        ])->assertOk();

        $log = $this->log();

        $this->assertStringContainsString('"attempt":"declined"', $log);
        $this->assertStringContainsString('insufficient_funds', $log);
        $this->assertStringContainsString($payment->reference, $log);

        /*
         * A decline is not the end of the attempt sequence: Stripe Checkout lets the guest
         * try another card in the same session, so the link must stay payable.
         */
        $this->assertSame(PaymentStatus::LinkSent, $payment->fresh()->status);
        $this->assertTrue($payment->fresh()->isPayable());
        $this->assertNull($payment->fresh()->failed_at);
    }

    #[Test]
    public function a_decline_with_no_intent_id_is_not_attached_to_an_unrelated_payment(): void
    {
        /*
         * Regression. Laravel turns where(col, null) into WHERE col IS NULL, so an event
         * carrying no payment_intent id would have resolved to whichever payment simply had
         * no intent id yet — writing a stranger's decline against someone else's booking.
         */
        $payment = BookingPayment::factory()
            ->for(Booking::factory()->awaitingDeposit())
            ->create(['stripe_payment_intent_id' => null]);

        $this->signedPost([
            'id' => 'evt_declined_no_id',
            'object' => 'event',
            'created' => time(),
            'type' => 'payment_intent.payment_failed',
            'data' => ['object' => [
                'object' => 'payment_intent',
                'last_payment_error' => ['code' => 'card_declined'],
                'metadata' => [],
            ]],
        ])->assertOk();

        $this->assertStringNotContainsString($payment->reference, $this->log());
    }

    #[Test]
    public function an_unmatched_decline_is_ignored_rather_than_retried(): void
    {
        // Someone else's integration on the same Stripe account. Answering anything but 200
        // would make Stripe redeliver an event we will never handle.
        $this->signedPost([
            'id' => 'evt_declined_orphan',
            'object' => 'event',
            'created' => time(),
            'type' => 'payment_intent.payment_failed',
            'data' => ['object' => [
                'id' => 'pi_not_ours',
                'object' => 'payment_intent',
                'metadata' => [],
            ]],
        ])->assertOk();

        $this->assertStringNotContainsString('declined', $this->log());
    }
}
