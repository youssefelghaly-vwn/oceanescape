<?php

namespace Tests\Unit\Booking;

use App\Models\Booking;
use App\Models\BookingAuditLog;
use App\Services\Booking\BookingAuditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BookingAuditorTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_redacts_sensitive_keys_at_any_depth(): void
    {
        /*
         * Audit rows are read in an admin screen by more people than write the code that
         * fills them, so the scrubbing is enforced centrally rather than trusted to each
         * call site.
         */
        app(BookingAuditor::class)->record('test.event', context: [
            'api_key' => 'sk_live_should_never_appear',
            'safe' => 'visible',
            'nested' => [
                'client_secret' => 'pi_secret_xyz',
                'card' => '4242424242424242',
                'amount' => 2500,
            ],
        ]);

        $context = BookingAuditLog::first()->context;

        $this->assertSame('[redacted]', $context['api_key']);
        $this->assertSame('[redacted]', $context['nested']['client_secret']);
        $this->assertSame('[redacted]', $context['nested']['card']);

        $this->assertSame('visible', $context['safe']);
        $this->assertSame(2500, $context['nested']['amount']);

        // And nothing sensitive survives anywhere in the serialised row.
        $this->assertStringNotContainsString('sk_live', json_encode($context));
        $this->assertStringNotContainsString('4242', json_encode($context));
    }

    #[Test]
    public function it_summarises_objects_rather_than_dumping_them(): void
    {
        app(BookingAuditor::class)->record('test.event', context: [
            'response' => new \stdClass,
        ]);

        $this->assertSame('[stdClass]', BookingAuditLog::first()->context['response']);
    }

    #[Test]
    public function audit_rows_cannot_be_updated_or_deleted(): void
    {
        $booking = Booking::factory()->create();

        app(BookingAuditor::class)->record('test.event', booking: $booking);

        $row = BookingAuditLog::first();

        try {
            $row->update(['event' => 'tampered']);
            $this->fail('an audit row should not be updatable');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }

        try {
            $row->delete();
            $this->fail('an audit row should not be deletable');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('cannot be deleted', $e->getMessage());
        }

        $this->assertSame('test.event', BookingAuditLog::first()->event);
    }

    #[Test]
    public function it_records_status_transitions(): void
    {
        $booking = Booking::factory()->create();

        app(BookingAuditor::class)->recordTransition(
            $booking, 'booking.advanced', 'awaiting_deposit', 'deposit_paid'
        );

        $this->assertDatabaseHas('booking_audit_logs', [
            'booking_id' => $booking->getKey(),
            'event' => 'booking.advanced',
            'from_status' => 'awaiting_deposit',
            'to_status' => 'deposit_paid',
        ]);
    }
}
