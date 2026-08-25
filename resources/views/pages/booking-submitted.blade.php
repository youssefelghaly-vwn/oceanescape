{{-- Shown straight after a booking is created. Nothing has been charged at this point. --}}
<x-website-layout title="Booking received · Ocean Escape Cottages">
    <section class="mx-auto max-w-2xl px-6 py-20">
        <p class="font-mono text-[11px] uppercase tracking-widest text-brand-600">Step 2 of 2</p>
        <h1 class="mt-3 font-display text-3xl text-ink-900 sm:text-4xl">Check your email</h1>

        <p class="mt-5 text-tide-700">
            We've reserved <strong>{{ $booking->cottage_name }}</strong> for
            {{ $booking->stay_label }} and sent a secure payment link to
            <strong>{{ $booking->guest_email }}</strong>.
        </p>

        <div class="mt-8 rounded-2xl border border-fog-200 bg-white p-6">
            <dl class="space-y-3 text-sm">
                <div class="flex justify-between gap-4">
                    <dt class="text-tide-600">Reference</dt>
                    <dd class="font-mono font-medium">{{ $booking->reference }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-tide-600">Stay</dt>
                    <dd>{{ $booking->stay_label }} · {{ $booking->nights }}
                        {{ Str::plural('night', $booking->nights) }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-tide-600">Guests</dt>
                    <dd>{{ $booking->party_label }}</dd>
                </div>
                <div class="flex justify-between gap-4 border-t border-fog-200 pt-3">
                    <dt class="text-tide-600">
                        {{ $booking->requires_full_payment ? 'Due now' : 'Deposit due now' }}
                    </dt>
                    <dd class="font-medium">{{ $booking->depositAmount()->format() }}</dd>
                </div>
                @unless ($booking->requires_full_payment)
                    <div class="flex justify-between gap-4">
                        <dt class="text-tide-600">Balance, nearer the time</dt>
                        <dd>{{ $booking->balanceAmount()->format() }}</dd>
                    </div>
                @endunless
                <div class="flex justify-between gap-4 border-t border-fog-200 pt-3 text-base">
                    <dt class="font-medium">Total</dt>
                    <dd class="font-medium">{{ $booking->total()->format() }}</dd>
                </div>
            </dl>
        </div>

        {{-- Said plainly: the dates are not held until the deposit lands, and that is a
             real consequence for the guest, not fine print. --}}
        <div class="mt-6 rounded-2xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-900">
            <p class="font-medium">Your dates aren't held yet.</p>
            <p class="mt-1.5">
                They're confirmed the moment the
                {{ $booking->requires_full_payment ? 'payment' : 'deposit' }} is paid. The
                link expires in {{ config('booking.deposit_link_ttl_hours') }} hours, after
                which we release the dates.
            </p>
        </div>

        <p class="mt-8 text-sm text-tide-600">
            Nothing has been charged yet. If the email hasn't arrived in a few minutes,
            check your spam folder or call us on
            <a class="underline" href="tel:{{ config('booking.support_phone') }}">{{ config('booking.support_phone') }}</a>.
        </p>
    </section>
</x-website-layout>
