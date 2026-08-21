<?php

namespace App\Exceptions;

use RuntimeException;

/** Base for every failure in the booking/payment flow, so callers can catch one type. */
class BookingException extends RuntimeException
{
    /**
     * Copy that is safe to show a guest. Null means "show the generic message" —
     * never leak an internal reason by default.
     */
    public function guestMessage(): ?string
    {
        return null;
    }
}
