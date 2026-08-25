{{-- resources/views/mail/payment-deposit.blade.php

     The FIRST money email: the deposit that confirms the booking (or, for a stay inside
     `full_payment_within_days`, the single full payment).

     Separate from the balance mail on purpose. The two say different things and carry
     different consequences: this one is "your dates are not held until you pay, and we
     release them when the link expires", while the balance mail is "your stay is confirmed,
     here is the remainder". One template with branches read as neither. --}}
<x-mail::message>
# One step left, {{ $booking->guest_first_name }}

We're holding **{{ $booking->cottage_name }}** for you. To confirm the booking, please pay
the {{ $isFullPayment ? 'amount' : 'deposit' }} below.

**{{ $booking->stay_label }}** · {{ $booking->nights }} {{ Str::plural('night', $booking->nights) }} · {{ $booking->party_label }}
Booking reference **{{ $booking->reference }}**

<x-mail::panel>
**{{ $isFullPayment ? 'Due now' : 'Deposit due now' }}: {{ $amount->format() }}**
@unless ($isFullPayment)
Total for the stay {{ $booking->total()->format() }} — the balance of
{{ $booking->balanceAmount()->format() }} is due
{{ config('booking.balance_lead_days') }} days before you arrive, and we'll email you a link
for it nearer the time.
@endunless
</x-mail::panel>

<x-mail::button :url="$payUrl">
Pay {{ $amount->format() }} securely
</x-mail::button>

@if ($expires)
{{-- The most important sentence in this email: the expiry is also when we let the dates go. --}}
This link expires **{{ $expires->diffForHumans() }}** ({{ $expires->format('D j M, H:i') }}).
If we haven't heard from you by then we'll release the dates, so do get in touch if you
need longer — we'd rather hold them than lose you.
@endif

Payment is handled securely by Stripe. We never see or store your card details, and nothing
is kept on file for next time.

Any questions, just reply to this email or call us on {{ config('booking.support_phone') }}.

Thanks,<br>
Ocean Escape Cottages

<x-slot:subcopy>
If the button doesn't work, copy this link into your browser:
{{ $payUrl }}
</x-slot:subcopy>
</x-mail::message>
