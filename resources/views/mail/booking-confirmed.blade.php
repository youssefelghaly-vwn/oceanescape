<x-mail::message>
@if ($booking->status->holdsDates())
# You're booked, {{ $booking->guest_first_name }}

Your stay at **{{ $booking->cottage_name }}** is confirmed. We've got the dates held for you.
@else
# Payment received, {{ $booking->guest_first_name }}

Thanks — we've received your payment for **{{ $booking->cottage_name }}**.
@endif

**{{ $booking->stay_label }}** · {{ $booking->nights }} {{ Str::plural('night', $booking->nights) }} · {{ $booking->party_label }}
Booking reference **{{ $booking->reference }}**

<x-mail::panel>
**Paid now:** {{ $paid->format() }} ({{ $payment->type->label() }})
**Total for the stay:** {{ $booking->total()->format() }}
@if ($outstanding->isPositive())
**Still to pay:** {{ $outstanding->format() }}
@else
**Nothing further to pay.** You're all settled.
@endif
</x-mail::panel>

@if ($balanceDue && $outstanding->isPositive())
We'll email you a link for the remaining {{ $outstanding->format() }} about
{{ config('booking.balance_lead_days') }} days before you arrive — no need to do anything now.
@endif

@if (filled($booking->quote_snapshot['cancellation_policy'] ?? null))
**Cancellation policy**
{{ $booking->quote_snapshot['cancellation_policy'] }}
@endif

We'll be in touch closer to the time with directions and check-in details.
Check-in is from 3pm and check-out by 11am unless we've agreed otherwise.

Anything at all, reply here or call {{ config('booking.support_phone') }}.

See you soon,<br>
Ocean Escape Cottages
</x-mail::message>
