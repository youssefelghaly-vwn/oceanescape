{{-- resources/views/components/cottage-row.blade.php

     Horizontal result row, in the manner of a flight or hotel search result:
     image | details | price + action, scanning left to right.

     Reads better than a card grid when results are ranked, because the eye can
     run down a single column of prices and comparable facts.

     <x-cottage-row :cottage="$c" :arrival="..." :departure="..." variant="exact" />
--}}
@props([
    'cottage',
    'arrival'      => null,
    'departure'    => null,
    'adults'       => 2,
    'children'     => 0,
    'pets'         => 0,
    'offsetDays'   => 0,
    'windowNights' => null,
    'windows'      => [],
    'variant'      => 'exact',   // exact | nearby | alternative | browse
])

@php
    $query = array_filter([
        'arrival'   => $arrival,
        'departure' => $departure,
        'adults'    => $adults ?: null,
        'children'  => $children ?: null,
        'pets'      => $pets ?: null,
    ], fn ($v) => $v !== null && $v !== '');

    $href = route('cottage.show', ['slug' => $cottage->slug])
          . (empty($query) ? '' : '?' . http_build_query($query));

    $facts = collect([
        $cottage->maxGuests ? $cottage->maxGuests . ' guests' : null,
        $cottage->bedrooms  ? $cottage->bedrooms . ' bed'     : null,
        $cottage->bathrooms ? $cottage->bathrooms . ' bath'   : null,
    ])->filter();

    $nights = ($arrival && $departure)
        ? \Illuminate\Support\Carbon::parse($arrival)->diffInDays(\Illuminate\Support\Carbon::parse($departure))
        : null;

    $from = $cottage->baseNightlyPrice;
    $currencySymbol = ($cottage->currency ?? 'USD') === 'CAD' ? 'CA$' : '$';
@endphp

<article class="group overflow-hidden rounded-3xl bg-white ring-1 ring-black/5 transition hover:shadow-xl hover:shadow-ink-900/10">
    <div class="flex flex-col sm:flex-row">

        {{-- image --}}
        <a href="{{ $href }}"
           class="relative block shrink-0 overflow-hidden bg-fog-100 sm:w-64 lg:w-72">
            <div class="aspect-[16/10] sm:h-full sm:aspect-auto">
                @if ($cottage->heroImage)
                    <img src="{{ $cottage->heroImage }}"
                         alt="{{ $cottage->altFor($cottage->heroImage, 0) }}"
                         loading="lazy" referrerpolicy="no-referrer"
                         class="h-full w-full object-cover transition duration-500 group-hover:scale-[1.03]">
                @else
                    <div class="grid h-full w-full place-items-center text-fog-300">
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2">
                            <path d="M3 21h18M5 21V8l7-5 7 5v13M9 21v-6h6v6"/>
                        </svg>
                    </div>
                @endif
            </div>

            @if ($variant === 'nearby' && $offsetDays > 0)
                <span class="absolute left-3 top-3 inline-flex items-center gap-1.5 rounded-full bg-buoy-500 px-2.5 py-1 font-mono text-[9px] font-semibold uppercase tracking-wide text-white shadow-lg">
                    <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M13 2 3 14h9l-1 8 10-12h-9l1-8z"/>
                    </svg>
                    {{ $offsetDays }} day{{ $offsetDays > 1 ? 's' : '' }} off
                </span>
            @elseif ($variant === 'alternative' && $windowNights)
                <span class="absolute left-3 top-3 rounded-full bg-ink-900/85 px-2.5 py-1 font-mono text-[9px] font-semibold uppercase tracking-wide text-white backdrop-blur">
                    {{ $windowNights }} night{{ $windowNights > 1 ? 's' : '' }} open
                </span>
            @endif
        </a>

        {{-- details --}}
        <div class="flex min-w-0 flex-1 flex-col justify-between gap-4 p-5 sm:flex-row sm:items-stretch sm:gap-6">

            <div class="min-w-0 flex-1">
                <a href="{{ $href }}" class="block">
                    <h3 class="font-display text-lg font-medium leading-snug text-ink-900 transition group-hover:text-brand-700">
                        {{ $cottage->name }}
                    </h3>
                </a>

                <p class="mt-1 font-mono text-[10px] uppercase tracking-[0.12em] text-tide-500">
                    {{ $cottage->locationLine() ?: 'Lockeport, Nova Scotia' }}
                </p>

                @if ($facts->isNotEmpty())
                    <div class="mt-2.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-tide-600">
                        @foreach ($facts as $i => $fact)
                            @if ($i > 0)<span class="text-fog-300" aria-hidden="true">&middot;</span>@endif
                            <span>{{ $fact }}</span>
                        @endforeach
                        @if ($cottage->petFriendly)
                            <span class="text-fog-300" aria-hidden="true">&middot;</span>
                            <span class="text-brand-600">Pet-friendly</span>
                        @endif
                    </div>
                @endif

                {{-- the dates on offer, or the next open windows when browsing --}}
                @if ($arrival && $departure)
                    <div class="mt-3 inline-flex items-center gap-2 rounded-full bg-fog-50 px-3 py-1.5">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="text-brand-600">
                            <rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>
                        </svg>
                        <span class="text-xs font-medium text-ink-900">
                            {{ \Illuminate\Support\Carbon::parse($arrival)->format('M j') }}
                            <span class="text-fog-400">&rarr;</span>
                            {{ \Illuminate\Support\Carbon::parse($departure)->format('M j, Y') }}
                        </span>
                        @if ($nights)
                            <span class="font-mono text-[10px] text-tide-500">{{ $nights }}n</span>
                        @endif
                    </div>
                @elseif (!empty($windows))
                    <div class="mt-3">
                        <p class="font-mono text-[9px] uppercase tracking-[0.12em] text-tide-500">Next open</p>
                        <div class="mt-1.5 flex flex-wrap gap-1.5">
                            @foreach (array_slice($windows, 0, 3) as $w)
                                @php
                                    $ws = \Illuminate\Support\Carbon::parse($w['start']);
                                    $we = \Illuminate\Support\Carbon::parse($w['end'])->addDay();
                                @endphp
                                <a href="{{ route('availability.search', [
                                        'arrival'   => $ws->toDateString(),
                                        'departure' => $we->toDateString(),
                                        'adults'    => $adults,
                                    ]) }}"
                                   class="inline-flex items-baseline gap-1.5 rounded-full bg-brand-50 px-2.5 py-1 text-[11px] font-medium text-brand-800 ring-1 ring-brand-100 transition hover:bg-brand-100">
                                    {{ $ws->format('M j') }} &ndash; {{ $we->format($ws->month === $we->month ? 'j' : 'M j') }}
                                    <span class="font-mono text-[9px] text-brand-600">{{ $w['nights'] }}n</span>
                                </a>
                            @endforeach
                        </div>
                    </div>
                @else
                    <p class="mt-3 text-xs italic text-tide-400">
                        Fully booked for the next {{ config('lodgify.availability_window_days', 90) }} days.
                    </p>
                @endif
            </div>

            {{-- price + action: a fixed right rail so figures line up down the page --}}
            <div class="flex shrink-0 items-end justify-between gap-4 border-t border-fog-200 pt-4 sm:w-44 sm:flex-col sm:items-end sm:justify-between sm:border-l sm:border-t-0 sm:pl-6 sm:pt-0">
                <div class="text-left sm:text-right">
                    @if ($from)
                        <p class="font-display text-2xl font-medium text-ink-900">
                            {{ $currencySymbol }}{{ number_format($from, 0) }}
                        </p>
                        <p class="font-mono text-[10px] uppercase tracking-wide text-tide-500">from / night</p>
                    @else
                        <p class="font-mono text-[11px] text-tide-500">Select dates<br>for pricing</p>
                    @endif
                </div>

                <a href="{{ $href }}"
                   class="inline-flex items-center gap-1.5 rounded-full bg-brand-600 px-5 py-2.5 text-sm font-semibold text-white transition hover:gap-2.5 hover:bg-brand-700">
                    {{ $arrival ? 'View & book' : 'View' }}
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M5 12h14M13 6l6 6-6 6"/>
                    </svg>
                </a>
            </div>
        </div>
    </div>
</article>