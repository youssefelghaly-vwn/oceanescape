<?php

namespace App\Exceptions;

/**
 * Lodgify returned no usable payment schedule for a quote.
 *
 * We REFUSE to take money in this case rather than inventing a deposit amount — see
 * config('booking.deposit.source'). Charging a percentage Lodgify never sanctioned means
 * two systems disagreeing about what a guest owes, and the guest believing whichever they
 * saw last. Better to decline the booking and have someone call them.
 */
class PaymentScheduleUnavailable extends BookingException
{
    public function guestMessage(): ?string
    {
        return 'We could not confirm the payment terms for those dates just now. '
             .'Please try again shortly, or call us on '
             .config('booking.support_phone').' and we will take the booking by phone.';
    }
}
