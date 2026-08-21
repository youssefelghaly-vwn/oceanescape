<?php

/**
 * config/booking.php
 *
 * Direct booking + payment flow.
 *
 * WHAT CHANGED, AND WHY IT MATTERS
 *
 * Previously the site handed the guest to Lodgify's hosted checkout
 * (checkout.lodgify.com) and Lodgify collected the money. Lodgify created the
 * reservation as `Open`, emailed its own deposit link, flipped the reservation to
 * `Booked` when the deposit landed, and later emailed the balance link. We saw none
 * of it — see App\Services\Lodgify\LodgifyCheckout and the checkout_intents table,
 * which exists purely because we lost sight of the guest at the redirect.
 *
 * Now WE own the money and Lodgify owns the reservation:
 *
 *   1. Guest confirms on our site. NOTHING IS CHARGED at this point.
 *   2. We create the reservation in Lodgify via the API. It is `Open`.
 *   3. We email a Stripe Checkout link for the deposit.
 *   4. Deposit paid -> we mark the reservation `Booked` in Lodgify, which is what
 *      blocks the dates on the calendar.
 *   5. `balance_lead_days` before arrival we email a second Stripe link.
 *   6. Balance paid -> we record the payment against the reservation.
 *
 * THE RISK THIS FLOW CARRIES, STATED PLAINLY
 * Between steps 2 and 4 the reservation is `Open`, and an `Open` reservation does
 * NOT block the dates in Lodgify. Two guests can therefore hold the same nights
 * until one of them pays. `deposit_link_ttl_hours` is the lever that bounds this
 * window: keep it short. See `docs` note in Docs/05-payments-and-booking.md.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Feature flag
    |--------------------------------------------------------------------------
    | False restores the old behaviour: /book/{slug} redirects to Lodgify's hosted
    | checkout and none of the code in this feature runs. Keep this switchable —
    | it is the rollback path if the Lodgify write API misbehaves in production.
    */
    'direct_payments_enabled' => (bool) env('BOOKING_DIRECT_PAYMENTS', false),

    /*
    |--------------------------------------------------------------------------
    | Deposit
    |--------------------------------------------------------------------------
    | The deposit amount is taken STRICTLY from Lodgify's own payment schedule
    | (`scheduled_payments` on the /v2/quote response). Lodgify is the authority on
    | what is owed and when; deriving it ourselves would mean two systems disagreeing
    | about money, and the guest believing whichever one they saw last.
    |
    | If Lodgify returns no schedule we REFUSE to create a payment rather than
    | guessing a percentage. A booking that cannot be priced authoritatively is a
    | booking we decline to take money for.
    |
    | `allow_percentage_fallback` exists only so the decision is visible in config
    | rather than buried in code. Leave it false unless you have accepted the risk of
    | charging an amount Lodgify did not sanction.
    */
    'deposit' => [
        'source' => 'lodgify_schedule',
        'allow_percentage_fallback' => (bool) env('BOOKING_DEPOSIT_ALLOW_FALLBACK', false),
        'fallback_percent' => (float) env('BOOKING_DEPOSIT_FALLBACK_PERCENT', 25.0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Payment links
    |--------------------------------------------------------------------------
    | Links are signed, expiring URLs on our own domain that redirect to a Stripe
    | Checkout Session. They are never raw Stripe URLs in an email, because a Stripe
    | session URL cannot be revoked once sent and carries its own much longer expiry.
    |
    | deposit_link_ttl_hours ALSO bounds how long an unpaid reservation sits `Open`
    | holding dates it has not paid for. 48h is a balance between giving a real person
    | time to pay and not letting a speculative booking block a weekend.
    */
    'deposit_link_ttl_hours' => (int) env('BOOKING_DEPOSIT_LINK_TTL', 48),
    'balance_link_ttl_days' => (int) env('BOOKING_BALANCE_LINK_TTL_DAYS', 14),

    /*
    | How many days before arrival the balance link goes out. Lodgify's own default
    | is 30; matching it keeps guest expectations consistent with anything they were
    | told on a previous stay.
    */
    'balance_lead_days' => (int) env('BOOKING_BALANCE_LEAD_DAYS', 30),

    /*
    | A guest who books INSIDE the balance window (e.g. arriving in 10 days) still
    | needs both payments. Below this many days to arrival we skip the deposit
    | entirely and ask for the full amount in one payment, because two links a week
    | apart is worse for everyone than one link for the total.
    */
    'full_payment_within_days' => (int) env('BOOKING_FULL_PAYMENT_WITHIN_DAYS', 14),

    /*
    |--------------------------------------------------------------------------
    | Lodgify write behaviour
    |--------------------------------------------------------------------------
    | mark_booked_on: which payment flips the reservation Open -> Booked.
    |   deposit  Lodgify's own semantics, and what blocks the calendar earliest.
    |   balance  leaves the dates unblocked until shortly before arrival. Supported
    |            for completeness; understand the overbooking exposure before using.
    */
    'mark_booked_on' => env('BOOKING_MARK_BOOKED_ON', 'deposit'),

    /*
    | Recording the payment amount back onto the Lodgify reservation is BEST EFFORT.
    | No documented public endpoint for it has been confirmed against a live account
    | (see App\Services\Lodgify\LodgifyBookingWriter), so a failure here is logged and
    | surfaced to an admin but never fails the guest's payment — the money is already
    | taken and the reservation is already Booked.
    */
    'record_payments_in_lodgify' => (bool) env('BOOKING_RECORD_PAYMENTS_IN_LODGIFY', true),

    /*
    |--------------------------------------------------------------------------
    | Guest-facing copy
    |--------------------------------------------------------------------------
    */
    'support_phone' => env('BOOKING_SUPPORT_PHONE', '902-398-1020'),
    'support_email' => env('BOOKING_SUPPORT_EMAIL', 'stay@oceanescapecottages.ca'),

    /*
    | Where operational alerts go when a payment succeeded but the Lodgify write
    | could not be completed after all retries. This is the one failure mode that
    | needs a human, because money has moved and the calendar does not reflect it.
    */
    'alert_email' => env('BOOKING_ALERT_EMAIL'),
];
