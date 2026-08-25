<?php

namespace App\Services\Booking;

use App\DTO\Cottage;
use App\Exceptions\PaymentScheduleUnavailable;
use App\Services\Lodgify\LodgifyRepository;
use App\Services\Lodgify\QuoteNormaliser;

/**
 * Fetches the quote we are willing to charge against.
 *
 * "Authoritative" means two things, and both matter:
 *
 *   FRESH   The cached quote TTL is 60s (lodgify.cache.quote), which is fine for showing
 *           a price in a panel and NOT fine for deciding what to charge — a guest may
 *           have had the page open for an hour. This forces a live re-quote at the moment
 *           of booking.
 *
 *   SERVER-SIDE  Nothing about the amount comes from the request. The browser sends dates
 *           and party size; the money is whatever Lodgify says it is in response to those.
 */
class QuoteReader
{
    public function __construct(
        protected LodgifyRepository $lodgify,
        protected QuoteNormaliser $normaliser,
    ) {}

    /**
     * @return array<string, mixed> normalised quote
     *
     * @throws PaymentScheduleUnavailable when Lodgify will not price the stay
     */
    public function authoritativeQuote(
        Cottage $cottage,
        string $arrival,
        string $departure,
        int $adults,
        int $children = 0,
        int $pets = 0,
        array $addOnIds = [],
    ): array {
        /*
         * Bypass the short-lived quote cache. Re-quoting is one HTTP call and this is the
         * one moment in the application where a stale price becomes a wrong charge.
         */
        $this->lodgify->forgetQuote($cottage->id, $arrival, $departure, $adults, $children, $pets, $addOnIds);

        $raw = $this->lodgify->quote($cottage->id, $arrival, $departure, $adults, $children, $pets, $addOnIds);

        if (blank($raw)) {
            /*
             * Prefer Lodgify's own words when it gave us any — "The minimum stay for this
             * rental is 6 days" tells the guest what to change, where a generic failure
             * message blames the cottage for a problem that might be ours. Same
             * distinction RateController::quote() already draws.
             */
            $guestMessage = $this->lodgify->lastGuestMessage();

            throw new class($guestMessage) extends PaymentScheduleUnavailable
            {
                public function __construct(private ?string $fromLodgify)
                {
                    parent::__construct($fromLodgify ?? 'Lodgify would not price this stay.');
                }

                public function guestMessage(): ?string
                {
                    return $this->fromLodgify ?? parent::guestMessage();
                }
            };
        }

        return $this->normaliser->normalise($raw, $cottage);
    }
}
