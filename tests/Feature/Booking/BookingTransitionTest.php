<?php

namespace Tests\Feature\Booking;

use App\Enums\BookingStatus;
use App\Exceptions\IllegalBookingTransition;
use App\Models\Booking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BookingTransitionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_applies_a_legal_transition_once(): void
    {
        $booking = Booking::factory()->awaitingDeposit()->create();

        $this->assertTrue($booking->transitionTo(BookingStatus::DepositPaid));
        $this->assertSame(BookingStatus::DepositPaid, $booking->fresh()->status);
    }

    #[Test]
    public function compare_and_swap_means_only_one_concurrent_caller_wins(): void
    {
        /*
         * Simulates two Stripe webhook deliveries racing. Both hold a model instance that
         * believes the booking is awaiting_deposit. Exactly one transition must apply —
         * otherwise both would go on to run the same side effects (Lodgify write,
         * confirmation email) twice.
         */
        $booking = Booking::factory()->awaitingDeposit()->create();

        $first = Booking::find($booking->getKey());
        $second = Booking::find($booking->getKey());

        $this->assertTrue($first->transitionTo(BookingStatus::DepositPaid));
        $this->assertFalse(
            $second->transitionTo(BookingStatus::DepositPaid),
            'the second caller must not also apply the transition'
        );

        // The loser is refreshed to reality rather than left believing a stale status.
        $this->assertSame(BookingStatus::DepositPaid, $second->status);
    }

    #[Test]
    public function it_refuses_an_illegal_transition(): void
    {
        $booking = Booking::factory()->create();   // pending_lodgify

        $this->expectException(IllegalBookingTransition::class);

        $booking->transitionTo(BookingStatus::PaidInFull);
    }

    #[Test]
    public function a_terminal_booking_cannot_be_revived(): void
    {
        $booking = Booking::factory()->create();
        $booking->forceFill(['status' => BookingStatus::Expired])->save();

        $this->expectException(IllegalBookingTransition::class);

        $booking->transitionTo(BookingStatus::AwaitingDeposit);
    }

    #[Test]
    public function it_writes_extra_columns_in_the_same_update(): void
    {
        $booking = Booking::factory()->awaitingDeposit()->create();

        $booking->transitionTo(BookingStatus::DepositPaid, ['booked_at' => now()]);

        $this->assertNotNull($booking->fresh()->booked_at);
    }

    #[Test]
    public function money_accessors_reconcile(): void
    {
        $booking = Booking::factory()->create([
            'total_cents' => 100000,
            'deposit_cents' => 25000,
            'balance_cents' => 75000,
            'currency' => 'CAD',
        ]);

        $this->assertSame(100000, $booking->total()->cents);
        $this->assertTrue(
            $booking->depositAmount()->plus($booking->balanceAmount())->equals($booking->total())
        );
    }

    #[Test]
    public function references_are_unique_and_avoid_lookalike_characters(): void
    {
        $refs = collect(range(1, 40))->map(fn () => Booking::generateReference());

        $this->assertCount(40, $refs->unique());

        foreach ($refs as $ref) {
            $this->assertMatchesRegularExpression('/^BK-[A-Z0-9]{6}$/', $ref);
            // Vowel/lookalike substitution: these must never appear in the body.
            $this->assertDoesNotMatchRegularExpression('/[OIL01]/', substr($ref, 3));
        }
    }
}
