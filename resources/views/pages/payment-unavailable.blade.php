<x-website-layout title="Payment unavailable · Ocean Escape Cottages">
    <section class="mx-auto max-w-2xl px-6 py-20">
        <h1 class="font-display text-3xl text-ink-900 sm:text-4xl">We can't take that payment</h1>

        <p class="mt-5 text-tide-700">{{ $reason }}</p>

        <p class="mt-4 text-sm text-tide-600">
            Nothing has been charged. Quote reference
            <span class="font-mono">{{ $booking->reference }}</span> and call us on
            <a class="underline" href="tel:{{ config('booking.support_phone') }}">{{ config('booking.support_phone') }}</a>
            — we can take the payment over the phone.
        </p>
    </section>
</x-website-layout>
