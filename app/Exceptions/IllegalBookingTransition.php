<?php

namespace App\Exceptions;

use App\Enums\BookingStatus;

/**
 * Thrown when something tries to move a booking through a transition the state machine
 * does not allow — e.g. a replayed webhook trying to walk `paid_in_full` back to
 * `awaiting_deposit`.
 *
 * This is a programming/ordering error, not a guest-facing one, so it carries no
 * guest message and should surface in logs and alerting.
 */
class IllegalBookingTransition extends BookingException
{
    public static function between(BookingStatus $from, BookingStatus $to, int|string $bookingId): self
    {
        return new self(sprintf(
            'Booking %s cannot move from %s to %s. Allowed: [%s].',
            $bookingId,
            $from->value,
            $to->value,
            implode(', ', array_map(fn ($s) => $s->value, $from->allowedNext())) ?: 'none (terminal)',
        ));
    }
}
