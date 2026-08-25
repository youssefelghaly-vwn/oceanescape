{{-- resources/views/mail/payment-balance.blade.php

     The SECOND money email: the remaining balance on a booking whose deposit is already
     paid and whose dates are already held in Lodgify.

     Tone differs from the deposit mail for a reason. Nothing is at risk here — the stay is
     confirmed — so this is a reminder, not an ultimatum, and it leads with the arrival
     rather than with the money. It is also sent to someone who has paid us once already,
     which is why it opens by saying what is left rather than re-explaining the booking. --}}
<x-mail::message>
# Your stay is coming up, {{ $booking->guest_first_name }}

**{{ $booking->cottage_name }}** is confirmed and waiting for you
@if ($booking->arrival)
on **{{ $booking->arrival->format('l j F') }}**@if ($daysToArrival !== null && $daysToArrival > 0), in {{ $daysToArrival }} {{ Str::plural('day', $daysToArrival) }}@endif.
@else
.
@endif
Here's the remaining balance.

**{{ $booking->stay_label }}** · {{ $booking->nights }} {{ Str::plural('night', $booking->nights) }} · {{ $booking->party_label }}
Booking reference **{{ $booking->reference }}**

<x-mail::panel>
**Balance due: {{ $amount->format() }}**
Total for the stay {{ $booking->total()->format() }}, of which
{{ $booking->amountPaid()->format() }} is already paid. Nothing else is owed after this.
</x-mail::panel>

<x-mail::button :url="$payUrl">
Pay {{ $amount->format() }} securely
</x-mail::button>

@if ($expires)
This link is good until **{{ $expires->format('D j M') }}**. If it lapses, just reply and
we'll send a fresh one — your booking stays confirmed either way.
@endif

We'll send arrival details and directions closer to the day.

Payment is handled securely by Stripe. We never see or store your card details, and nothing
is kept on file for next time.

Any questions, just reply to this email or call us on {{ config('booking.support_phone') }}.

See you soon,<br>
Ocean Escape Cottages

<x-slot:subcopy>
If the button doesn't work, copy this link into your browser:
{{ $payUrl }}
</x-slot:subcopy>
</x-mail::message>
