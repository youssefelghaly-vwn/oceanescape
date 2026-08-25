<x-website-layout title="Already paid · Ocean Escape Cottages">
    <section class="mx-auto max-w-2xl px-6 py-20">
        <h1 class="font-display text-3xl text-ink-900 sm:text-4xl">That's already paid</h1>

        <p class="mt-5 text-tide-700">
            The {{ $payment->type->label() }} of
            {{ ($payment->amountReceived() ?? $payment->amount())->format() }} for
            <strong>{{ $booking->cottage_name }}</strong> was received
            {{ $payment->paid_at?->diffForHumans() }}. Nothing more to do here — and you
            haven't been charged twice.
        </p>

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
            </dl>
        </div>
    </section>
</x-website-layout>
