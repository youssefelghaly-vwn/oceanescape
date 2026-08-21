<?php

namespace Tests\Unit\Booking;

use App\Enums\BookingStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class BookingStatusTest extends TestCase
{
    #[Test]
    public function a_paid_booking_can_never_be_walked_backwards(): void
    {
        /*
         * The property that protects against out-of-order or replayed webhooks: nothing
         * moves a settled booking back into a state where it could be charged again.
         */
        foreach (BookingStatus::cases() as $case) {
            $this->assertFalse(
                BookingStatus::PaidInFull->canTransitionTo($case) && $case !== BookingStatus::PaidInFull,
                "paid_in_full must not transition to {$case->value}"
            );
        }
    }

    #[Test]
    public function terminal_states_are_terminal(): void
    {
        foreach ([BookingStatus::PaidInFull, BookingStatus::Expired, BookingStatus::Cancelled, BookingStatus::Failed] as $terminal) {
            $this->assertTrue($terminal->isTerminal(), "{$terminal->value} should be terminal");
            $this->assertSame([], $terminal->allowedNext());
        }
    }

    #[Test]
    public function only_confirmed_states_hold_dates(): void
    {
        /*
         * This is the single most consequential fact about the chosen flow: awaiting_deposit
         * does NOT hold the dates, because an Open Lodgify reservation does not block the
         * calendar. Encoded as a test so nobody "tidies" it later.
         */
        $this->assertFalse(BookingStatus::PendingLodgify->holdsDates());
        $this->assertFalse(BookingStatus::AwaitingDeposit->holdsDates());

        $this->assertTrue(BookingStatus::DepositPaid->holdsDates());
        $this->assertTrue(BookingStatus::AwaitingBalance->holdsDates());
        $this->assertTrue(BookingStatus::PaidInFull->holdsDates());
    }

    #[Test]
    public function the_happy_path_is_walkable(): void
    {
        $this->assertTrue(BookingStatus::PendingLodgify->canTransitionTo(BookingStatus::AwaitingDeposit));
        $this->assertTrue(BookingStatus::AwaitingDeposit->canTransitionTo(BookingStatus::DepositPaid));
        $this->assertTrue(BookingStatus::DepositPaid->canTransitionTo(BookingStatus::AwaitingBalance));
        $this->assertTrue(BookingStatus::AwaitingBalance->canTransitionTo(BookingStatus::PaidInFull));
    }

    #[Test]
    public function a_late_booking_can_go_straight_to_paid_in_full(): void
    {
        // Single full payment on an imminent stay skips the deposit/balance split.
        $this->assertTrue(BookingStatus::AwaitingDeposit->canTransitionTo(BookingStatus::PaidInFull));
    }

    #[Test]
    public function an_unpaid_booking_cannot_skip_to_confirmed(): void
    {
        $this->assertFalse(BookingStatus::PendingLodgify->canTransitionTo(BookingStatus::PaidInFull));
        $this->assertFalse(BookingStatus::PendingLodgify->canTransitionTo(BookingStatus::DepositPaid));
    }

    #[Test]
    public function re_asserting_the_same_state_is_allowed(): void
    {
        // Idempotent handlers re-assert current state; that must not throw.
        foreach (BookingStatus::cases() as $case) {
            $this->assertTrue($case->canTransitionTo($case));
        }
    }
}
