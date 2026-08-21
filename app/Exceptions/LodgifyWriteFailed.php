<?php

namespace App\Exceptions;

/**
 * A write to Lodgify (create reservation / mark booked / record payment) failed.
 *
 * TWO VERY DIFFERENT SITUATIONS SHARE THIS TYPE, and the distinction is what
 * `moneyAtRisk` records:
 *
 *   false  Failed while creating the reservation, BEFORE any payment. Nothing has been
 *          charged, so we can fail the request cleanly and tell the guest to call.
 *   true   Failed AFTER a successful Stripe payment. The guest has paid and Lodgify does
 *          not know. This must never be swallowed: it is retried, and on final failure
 *          it alerts a human, because the calendar is now wrong in the guest's favour.
 */
class LodgifyWriteFailed extends BookingException
{
    public function __construct(
        string $message,
        public readonly string $operation = 'unknown',
        public readonly ?int $status = null,
        public readonly bool $moneyAtRisk = false,
        public readonly ?string $responseExcerpt = null,
    ) {
        parent::__construct($message);
    }

    public function guestMessage(): ?string
    {
        // Never tell a guest who has paid that something failed — the money is safe and
        // the booking will be reconciled by a human. Only the pre-payment case gets copy.
        return $this->moneyAtRisk
            ? null
            : 'We could not confirm that reservation just now. Nothing has been charged. '
              .'Please call us on '.config('booking.support_phone').'.';
    }
}
