{{-- resources/views/pages/business-stays-thanks.blade.php --}}
<x-website-layout title="Enquiry received | Ocean Escape Cottages">
    <section class="bg-white py-32">
        <div class="mx-auto max-w-xl px-6 text-center">
            <div class="mx-auto grid h-16 w-16 place-items-center rounded-full bg-brand-50 text-brand-600 ring-1 ring-brand-100">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                    <path d="M20 6 9 17l-5-5"/>
                </svg>
            </div>

            <h1 class="mt-6 font-display text-3xl font-medium text-ink-900">Thank you &mdash; we&rsquo;ve got it</h1>

            <p class="mt-4 text-base leading-relaxed text-tide-700">
                Your enquiry reference is
                <span class="font-mono font-semibold text-ink-900">{{ $reference }}</span>.
                We&rsquo;ll be in touch within one business day, usually sooner.
            </p>

            <p class="mt-3 text-sm text-tide-600">
                Something urgent? Call <a href="tel:9023981020" class="font-medium text-brand-600 hover:underline">902-398-1020</a>
                or email <a href="mailto:info@oceanescapecottages.ca" class="font-medium text-brand-600 hover:underline">info@oceanescapecottages.ca</a>
                and quote your reference.
            </p>

            <div class="mt-9 flex flex-wrap items-center justify-center gap-3">
                <a href="{{ route('cottages.index') }}"
                   class="rounded-full bg-brand-600 px-6 py-3 text-sm font-semibold text-white transition hover:bg-brand-700">
                    Browse the cottages
                </a>
                <a href="{{ route('home') }}"
                   class="rounded-full px-6 py-3 text-sm font-semibold text-brand-700 ring-1 ring-brand-200 transition hover:bg-brand-50">
                    Back to home
                </a>
            </div>
        </div>
    </section>
</x-website-layout>