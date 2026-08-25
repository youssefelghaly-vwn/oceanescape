<x-website-layout title="Payment not completed · Ocean Escape Cottages">
    <section class="mx-auto max-w-2xl px-6 py-20">
        <h1 class="font-display text-3xl text-ink-900 sm:text-4xl">No payment taken</h1>

        <p class="mt-5 text-tide-700">
            You backed out of the payment page, so nothing has been charged.
            {{ $booking->cottage_name }} for {{ $booking->stay_label }} is still reserved for
            you for now.
        </p>

        @if ($payUrl)
            <a href="{{ $payUrl }}"
               class="mt-8 inline-flex rounded-full bg-brand-600 px-6 py-3 text-sm font-medium text-white">
                Pay {{ $payment->amount()->format() }}
            </a>
            @if ($payment->link_expires_at)
                <p class="mt-3 text-sm text-tide-600">
                    This link expires {{ $payment->link_expires_at->diffForHumans() }}.
                </p>
            @endif
        @else
            <p class="mt-8 text-sm text-tide-600">
                That payment link has expired. Call us on
                <a class="underline" href="tel:{{ config('booking.support_phone') }}">{{ config('booking.support_phone') }}</a>
                and we'll sort it out.
            </p>
        @endif
    </section>
</x-website-layout>
