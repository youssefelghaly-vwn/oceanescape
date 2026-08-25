<x-mail::message>
# {{ $isFinal ? 'Your balance is due' : 'One step left' }}, {{ $booking->guest_first_name }}

@if ($payment->type === \App\Enums\PaymentType::Deposit)
We're holding **{{ $booking->cottage_name }}** for you. To confirm the booking, please pay
the deposit below.
@elseif ($payment->type === \App\Enums\PaymentType::Balance)
Your stay at **{{ $booking->cottage_name }}** is coming up. Here's the remaining balance.
@else
We're holding **{{ $booking->cottage_name }}** for you. Please complete payment to confirm.
@endif

**{{ $booking->stay_label }}** · {{ $booking->nights }} {{ Str::plural('night', $booking->nights) }} · {{ $booking->party_label }}
Booking reference **{{ $booking->reference }}**

<x-mail::panel>
**{{ $payment->type->label() }} due: {{ $amount->format() }}**
@if (! $booking->requires_full_payment && $payment->type === \App\Enums\PaymentType::Deposit)
Total for the stay {{ $booking->total()->format() }} — the balance of
{{ $booking->balanceAmount()->format() }} is due closer to your arrival, and we'll email you
a link for it.
@endif
</x-mail::panel>

<x-mail::button :url="$payUrl">
Pay {{ $amount->format() }} securely
</x-mail::button>

@if ($expires)
{{-- Stated plainly, because for a deposit this is also the point at which we release the dates. --}}
This link expires **{{ $expires->diffForHumans() }}** ({{ $expires->format('D j M, H:i') }}).
@if ($payment->type === \App\Enums\PaymentType::Deposit)
If we haven't heard from you by then we'll release the dates, so do get in touch if you need longer.
@endif
@endif

Payment is handled securely by Stripe. We never see or store your card details.

Any questions, just reply to this email or call us on {{ config('booking.support_phone') }}.

Thanks,<br>
Ocean Escape Cottages

<x-slot:subcopy>
If the button doesn't work, copy this link into your browser:
{{ $payUrl }}
</x-slot:subcopy>
</x-mail::message>
