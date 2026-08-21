<x-website-layout title="Payment received · Ocean Escape Cottages">
    <section class="mx-auto max-w-2xl px-6 py-20">
        @if ($payment->status->isSettled())
            <p class="font-mono text-[11px] uppercase tracking-widest text-emerald-600">Paid</p>
            <h1 class="mt-3 font-display text-3xl text-ink-900 sm:text-4xl">
                {{ $booking->status->holdsDates() ? "You're booked" : 'Thank you' }}
            </h1>
            <p class="mt-5 text-tide-700">
                We've received {{ ($payment->amountReceived() ?? $payment->amount())->format() }}
                for <strong>{{ $booking->cottage_name }}</strong>, {{ $booking->stay_label }}.
                A confirmation is on its way to {{ $booking->guest_email }}.
            </p>
        @else
            {{-- The webhook is authoritative and may not have landed yet. Never claim a
                 payment failed just because this page got here first. --}}
            <p class="font-mono text-[11px] uppercase tracking-widest text-brand-600">Processing</p>
            <h1 class="mt-3 font-display text-3xl text-ink-900 sm:text-4xl">Payment received</h1>
            <p class="mt-5 text-tide-700">
                Your payment is being confirmed — this usually takes a few seconds. We'll email
                you as soon as it's done. There's no need to pay again.
            </p>
        @endif

        <div class="mt-8 rounded-2xl border border-fog-200 bg-white p-6 text-sm">
            <dl class="space-y-3">
                <div class="flex justify-between gap-4">
                    <dt class="text-tide-600">Reference</dt>
                    <dd class="font-mono font-medium">{{ $booking->reference }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-tide-600">Paid</dt>
                    <dd>{{ $booking->amountPaid()->format() }} of {{ $booking->total()->format() }}</dd>
                </div>
                @if ($booking->amountOutstanding()->isPositive())
                    <div class="flex justify-between gap-4 border-t border-fog-200 pt-3">
                        <dt class="text-tide-600">Still to pay</dt>
                        <dd>{{ $booking->amountOutstanding()->format() }}</dd>
                    </div>
                @endif
            </dl>
        </div>

        @if ($booking->amountOutstanding()->isPositive())
            <p class="mt-6 text-sm text-tide-600">
                We'll email you a link for the remaining
                {{ $booking->amountOutstanding()->format() }} about
                {{ config('booking.balance_lead_days') }} days before you arrive.
            </p>
        @endif

        <a href="{{ route('home') }}"
           class="mt-10 inline-flex rounded-full bg-brand-600 px-6 py-3 text-sm font-medium text-white">
            Back to the site
        </a>
    </section>
</x-website-layout>
