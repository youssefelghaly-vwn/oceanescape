<?php

namespace Tests\Feature\Booking;

use App\Exceptions\LodgifyWriteFailed;
use App\Jobs\MarkLodgifyBookingBooked;
use App\Mail\BookingNeedsAttention;
use App\Models\Booking;
use App\Services\Booking\BookingAuditor;
use App\Services\Lodgify\LodgifyBookingWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The job that flips a paid reservation to Booked.
 *
 * This is the one failure in the feature that genuinely risks a guest's stay: if it never
 * succeeds, someone has paid for nights Lodgify still believes are for sale.
 */
class LodgifyConfirmationJobTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_marks_the_reservation_booked(): void
    {
        $booking = Booking::factory()->awaitingDeposit()->create();

        $writer = Mockery::mock(LodgifyBookingWriter::class);
        $writer->shouldReceive('markBooked')->once();
        $this->app->instance(LodgifyBookingWriter::class, $writer);

        (new MarkLodgifyBookingBooked($booking->getKey()))->handle($writer, app(BookingAuditor::class));

        $fresh = $booking->fresh();

        $this->assertSame('Booked', $fresh->lodgify_status);
        $this->assertNotNull($fresh->booked_at);
        $this->assertNull($fresh->lodgify_sync_error);
        $this->assertDatabaseHas('booking_audit_logs', ['event' => 'lodgify.mark_booked.ok']);
    }

    #[Test]
    public function it_is_a_no_op_when_the_reservation_is_already_booked(): void
    {
        // Guards against a duplicate dispatch double-writing to Lodgify.
        $booking = Booking::factory()->depositPaid()->create();

        $writer = Mockery::mock(LodgifyBookingWriter::class);
        $writer->shouldNotReceive('markBooked');
        $this->app->instance(LodgifyBookingWriter::class, $writer);

        (new MarkLodgifyBookingBooked($booking->getKey()))->handle($writer, app(BookingAuditor::class));

        $this->assertTrue(true);   // the assertion is the shouldNotReceive above
    }

    #[Test]
    public function a_failure_is_rethrown_so_the_queue_retries_it(): void
    {
        $booking = Booking::factory()->awaitingDeposit()->create();

        $writer = Mockery::mock(LodgifyBookingWriter::class);
        $writer->shouldReceive('markBooked')->andThrow(new LodgifyWriteFailed(
            'Lodgify 503', operation: 'markBooked', status: 503, moneyAtRisk: true,
        ));
        $this->app->instance(LodgifyBookingWriter::class, $writer);

        $this->expectException(LodgifyWriteFailed::class);

        try {
            (new MarkLodgifyBookingBooked($booking->getKey()))
                ->handle($writer, app(BookingAuditor::class));
        } finally {
            $fresh = $booking->fresh();

            // The attempt is recorded even though the job rethrows.
            $this->assertSame(1, (int) $fresh->lodgify_sync_attempts);
            $this->assertStringContainsString('503', (string) $fresh->lodgify_sync_error);
            $this->assertDatabaseHas('booking_audit_logs', ['event' => 'lodgify.mark_booked.failed']);
        }
    }

    #[Test]
    public function exhausting_retries_alerts_a_human(): void
    {
        /*
         * The critical alert. The guest has paid and the calendar is wrong in their favour,
         * so this must reach a person rather than being swallowed.
         */
        Mail::fake();

        $booking = Booking::factory()->awaitingDeposit()->create();

        (new MarkLodgifyBookingBooked($booking->getKey()))
            ->failed(new LodgifyWriteFailed('gave up', operation: 'markBooked', moneyAtRisk: true));

        Mail::assertSent(BookingNeedsAttention::class, fn ($mail) => $mail->hasTo('ops@example.test'));

        $this->assertStringContainsString(
            'PAID BUT NOT CONFIRMED',
            (string) $booking->fresh()->lodgify_sync_error
        );
        $this->assertDatabaseHas('booking_audit_logs', ['event' => 'lodgify.mark_booked.exhausted']);
    }

    #[Test]
    public function it_still_records_the_problem_when_no_alert_address_is_configured(): void
    {
        // Silence is the one unacceptable outcome, so a missing alert address must not mean
        // the failure disappears.
        Mail::fake();
        config()->set('booking.alert_email', null);

        $booking = Booking::factory()->awaitingDeposit()->create();

        (new MarkLodgifyBookingBooked($booking->getKey()))
            ->failed(new LodgifyWriteFailed('gave up', operation: 'markBooked', moneyAtRisk: true));

        Mail::assertNothingSent();
        $this->assertDatabaseHas('booking_audit_logs', ['event' => 'lodgify.mark_booked.exhausted']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
