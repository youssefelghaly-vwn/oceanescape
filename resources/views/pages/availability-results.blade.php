{{-- resources/views/pages/availability-results.blade.php

     Flight-search layout: one stacked column of result rows, grouped into
     ranked sections. Dates are optional — without them this becomes a
     browsable list of every cottage. --}}
<x-website-layout :title="$hasDates ? 'Available Cottages · Ocean Escape' : 'All Cottages · Ocean Escape'">

    {{-- ================================================= SEARCH SUMMARY --}}
    <section class="border-b border-fog-200 bg-fog-50 pb-8 pt-32">
        <div class="mx-auto max-w-5xl px-6 lg:px-8">
            <p class="font-mono text-[11px] uppercase tracking-[0.2em] text-tide-500">
                {{ $hasDates ? 'Your search' : 'All cottages' }}
            </p>

            @if ($hasDates)
                <h1 class="mt-2 font-display text-3xl font-medium text-ink-900 sm:text-4xl">
                    {{ \Illuminate\Support\Carbon::parse($arrival)->format('M j') }}
                    <span class="text-fog-400">&ndash;</span>
                    {{ \Illuminate\Support\Carbon::parse($departure)->format('M j, Y') }}
                    <span class="font-mono text-base font-normal text-tide-500">
                        &middot; {{ $nights }} night{{ $nights > 1 ? 's' : '' }}
                    </span>
                </h1>
                <p class="mt-1 text-sm text-tide-600">
                    {{ $guests }} guest{{ $guests > 1 ? 's' : '' }}
                    @if ($pets) &middot; {{ $pets }} pet{{ $pets > 1 ? 's' : '' }} @endif
                </p>
            @else
                <h1 class="mt-2 font-display text-3xl font-medium text-ink-900 sm:text-4xl">
                    Six oceanfront cottages
                </h1>
                <p class="mt-1 max-w-xl text-sm text-tide-600">
                    Browse every cottage and its next open dates, or add your dates above to see
                    exactly what&rsquo;s free.
                </p>
            @endif

            @if ($degraded)
                <div class="mt-5 flex items-start gap-2.5 rounded-2xl bg-amber-50 px-4 py-3 text-sm text-amber-900 ring-1 ring-amber-200">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="mt-0.5 shrink-0">
                        <circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/>
                    </svg>
                    <span>Some availability couldn&rsquo;t be loaded, so results may be incomplete. Please refresh or contact us to confirm.</span>
                </div>
            @endif

            <div class="mt-6">
                <x-booking-search
                    :initial-arrival="$arrival"
                    :initial-departure="$departure"
                    :initial-adults="$adults"
                    :initial-children="$children"
                    :initial-pets="$pets"
                />
            </div>
        </div>
    </section>

    {{-- ======================================================= RESULTS --}}
    <section class="bg-white py-12">
        <div class="mx-auto max-w-5xl space-y-14 px-6 lg:px-8">

            {{-- ---------------------------------------- BROWSE (no dates) --}}
            @if (!$hasDates)
                @if ($browse->isEmpty())
                    <div class="rounded-3xl bg-fog-50 px-6 py-12 text-center ring-1 ring-black/5">
                        <p class="text-sm text-tide-600">
                            We couldn&rsquo;t load the cottages just now. Please refresh, or
                            <a href="mailto:info@oceanescapecottages.ca" class="font-medium text-brand-700 underline">email us</a>.
                        </p>
                    </div>
                @else
                    <div>
                        <div class="mb-5 flex flex-wrap items-baseline justify-between gap-3">
                            <h2 class="font-display text-2xl font-medium text-ink-900">
                                {{ $browse->count() }} {{ \Illuminate\Support\Str::plural('cottage', $browse->count()) }}
                            </h2>
                            <p class="font-mono text-[11px] uppercase tracking-wide text-tide-500">
                                openings within {{ config('lodgify.availability_window_days', 90) }} days
                            </p>
                        </div>

                        <div class="space-y-4">
                            @foreach ($browse as $listing)
                                <x-cottage-row
                                    :cottage="$listing['cottage']"
                                    :windows="$listing['windows']"
                                    :adults="$adults"
                                    :children="$children"
                                    :pets="$pets"
                                    variant="browse"
                                />
                            @endforeach
                        </div>
                    </div>
                @endif

            @else
                {{-- ------------------------------------- TIER 1: exact --}}
                <div>
                    <div class="mb-1 flex flex-wrap items-baseline justify-between gap-3">
                        <h2 class="font-display text-2xl font-medium text-ink-900">
                            {{ $exact->isNotEmpty() ? 'Available for your dates' : 'No cottages match your exact dates' }}
                        </h2>
                        @if ($exact->isNotEmpty())
                            <p class="font-mono text-[11px] uppercase tracking-wide text-tide-500">
                                {{ $exact->count() }} {{ \Illuminate\Support\Str::plural('cottage', $exact->count()) }}
                            </p>
                        @endif
                    </div>

                    @if ($exact->isNotEmpty())
                        <p class="mb-5 text-sm text-tide-600">
                            Free for exactly {{ \Illuminate\Support\Carbon::parse($arrival)->format('M j') }}
                            &ndash; {{ \Illuminate\Support\Carbon::parse($departure)->format('M j') }}.
                        </p>
                        <div class="space-y-4">
                            @foreach ($exact as $cottage)
                                <x-cottage-row
                                    :cottage="$cottage"
                                    :arrival="$arrival"
                                    :departure="$departure"
                                    :adults="$adults"
                                    :children="$children"
                                    :pets="$pets"
                                    variant="exact"
                                />
                            @endforeach
                        </div>
                    @else
                        <div class="mt-4 rounded-3xl bg-fog-50 px-6 py-6 text-center ring-1 ring-black/5">
                            <p class="text-sm text-tide-600">
                                @if ($nearby->isNotEmpty() || $alternatives->isNotEmpty())
                                    Those exact dates are taken &mdash; here are the closest options we can offer.
                                @else
                                    Those dates are fully booked across all six cottages.
                                @endif
                            </p>
                        </div>
                    @endif
                </div>

                {{-- ------------------------------------ TIER 2: nearby --}}
                @if ($nearby->isNotEmpty())
                    <div>
                        <div class="mb-1 flex flex-wrap items-baseline justify-between gap-3">
                            <h2 class="font-display text-2xl font-medium text-ink-900">Same trip, nearby dates</h2>
                            <p class="font-mono text-[11px] uppercase tracking-wide text-tide-500">
                                within &plusmn;{{ config('lodgify.nearby_window_days', 14) }} days
                            </p>
                        </div>
                        <p class="mb-5 text-sm text-tide-600">
                            Still {{ $nights }} night{{ $nights > 1 ? 's' : '' }} &mdash; just shifted slightly.
                        </p>

                        <div class="space-y-4">
                            @foreach ($nearby as $match)
                                <x-cottage-row
                                    :cottage="$match['cottage']"
                                    :arrival="$match['arrival']"
                                    :departure="$match['departure']"
                                    :adults="$adults"
                                    :children="$children"
                                    :pets="$pets"
                                    :offset-days="$match['offset_days']"
                                    variant="nearby"
                                />
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- ------------------------------ TIER 3: open windows --}}
                @if ($alternatives->isNotEmpty())
                    <div>
                        <div class="mb-1 flex flex-wrap items-baseline justify-between gap-3">
                            <h2 class="font-display text-2xl font-medium text-ink-900">Next open windows</h2>
                            <p class="font-mono text-[11px] uppercase tracking-wide text-tide-500">
                                {{ $alternatives->count() }} {{ \Illuminate\Support\Str::plural('cottage', $alternatives->count()) }}
                            </p>
                        </div>
                        <p class="mb-5 max-w-2xl text-sm text-tide-600">
                            A {{ $nights }}-night stay isn&rsquo;t open around those dates, but these cottages have
                            availability close by &mdash; shorter or longer than you asked for.
                        </p>

                        <div class="space-y-4">
                            @foreach ($alternatives as $match)
                                <x-cottage-row
                                    :cottage="$match['cottage']"
                                    :arrival="$match['arrival']"
                                    :departure="$match['departure']"
                                    :adults="$adults"
                                    :children="$children"
                                    :pets="$pets"
                                    :offset-days="$match['offset_days']"
                                    :window-nights="$match['nights']"
                                    variant="alternative"
                                />
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- ------------------------------------- nothing at all --}}
                @if ($exact->isEmpty() && $nearby->isEmpty() && $alternatives->isEmpty())
                    <div class="py-8 text-center">
                        <div class="mx-auto grid h-14 w-14 place-items-center rounded-full bg-fog-50 text-tide-400 ring-1 ring-black/5">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                <rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>
                            </svg>
                        </div>
                        <h2 class="mt-5 font-display text-2xl font-medium text-ink-900">Nothing open in this stretch</h2>
                        <p class="mx-auto mt-3 max-w-md text-sm text-tide-600">
                            All six cottages are booked around those dates. Cancellations do happen &mdash;
                            get in touch and we&rsquo;ll let you know first.
                        </p>
                        <div class="mt-7 flex flex-wrap items-center justify-center gap-3">
                            <a href="{{ route('availability.search') }}"
                               class="rounded-full bg-brand-600 px-6 py-3 text-sm font-semibold text-white transition hover:bg-brand-700">
                                Browse all cottages
                            </a>
                            <a href="mailto:info@oceanescapecottages.ca"
                               class="rounded-full px-6 py-3 text-sm font-semibold text-brand-700 ring-1 ring-brand-200 transition hover:bg-brand-50">
                                Email us
                            </a>
                        </div>
                    </div>
                @endif
            @endif
        </div>
    </section>

</x-website-layout>