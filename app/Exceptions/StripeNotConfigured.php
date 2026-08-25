<?php

namespace App\Exceptions;

/**
 * Stripe credentials are missing.
 *
 * Raised lazily, on first actual use of the Stripe client, rather than when StripeGateway
 * is constructed. That distinction matters: the gateway is constructor-injected into
 * PaymentController, so throwing at construction time took down every route that merely
 * resolved the controller — `route:list` included — instead of only the payment paths.
 */
class StripeNotConfigured extends BookingException
{
    public function guestMessage(): ?string
    {
        return 'Online payment is temporarily unavailable. Please call us on '
             .config('booking.support_phone').' and we can take payment by phone.';
    }
}
