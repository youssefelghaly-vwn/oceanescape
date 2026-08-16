{{-- resources/views/pages/cottage.blade.php

     Content order is deliberate — most decision-critical info first:
       1. Gallery + name + location + price-from + key facts   (is this the right place?)
       2. Booking panel (sticky)                               (can I get it?)
       3. Calendar with per-night prices                        (when, and how much?)
       4. Live breakdown for chosen dates                       (what will I actually pay?)
       5. Description                                           (tell me more)
       6. Amenities                                             (does it have X?)
       7. Seasonal rates                                        (is another month cheaper?)
       8. House rules & check-in                                (fine print)
       9. Location & map                                        (where exactly?)
--}}
<x-website-layout :title="$cottage->name . ' | Ocean Escape Cottages'">

<div
    x-data="cottageCalendar({
        slug: '{{ $cottage->slug }}',
        ratesUrl: '{{ route('api.cottage.rates', $cottage->slug) }}',
        quoteUrl: '{{ route('api.cottage.quote', $cottage->slug) }}',
        addonsUrl: '{{ route('api.cottage.addons', $cottage->slug) }}',
        currency: '{{ $cottage->currency ?? 'USD' }}',
        maxGuests: {{ $cottage->maxGuests ?: 'null' }},
        petsAllowed: {{ $cottage->petFriendly ? 'true' : 'false' }},
        arrival: {{ $arrival ? "'".$arrival."'" : 'null' }},
        departure: {{ $departure ? "'".$departure."'" : 'null' }},
        adults: {{ (int) $adults }},
        children: {{ (int) $children }},
        pets: {{ (int) $pets }},
    })"
>

    {{-- ============================================================ 1. GALLERY --}}
    <section class="bg-ink-900 pt-24"
             x-data="imageLightbox({ images: @js($cottage->galleryPayload()) })"
             @keydown.window="onKey($event)">
        <div class="mx-auto max-w-7xl px-6 lg:px-8">
            @php
                $imgs  = $cottage->images;
                $count = count($imgs);
            @endphp

            @if ($count === 0)
                <div class="grid aspect-[16/6] place-items-center rounded-3xl bg-ink-800 text-fog-400">
                    <div class="text-center">
                        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" class="mx-auto">
                            <path d="M3 21h18M5 21V8l7-5 7 5v13M9 21v-6h6v6"/>
                        </svg>
                        <p class="mt-3 font-mono text-[10px] uppercase tracking-wide text-fog-500">No photos yet</p>
                    </div>
                </div>

            @elseif ($count === 1)
                <button type="button" @click="show(0, $event.currentTarget)"
                        class="group relative block aspect-[16/7] w-full overflow-hidden rounded-3xl bg-ink-800">
                    <img src="{{ $imgs[0] }}" alt="{{ $cottage->altFor($imgs[0], 0) }}"
                         class="h-full w-full object-cover transition duration-500 group-hover:scale-[1.02]"
                         referrerpolicy="no-referrer">
                    <x-gallery-zoom-hint />
                </button>

            @elseif ($count === 2)
                <div class="grid gap-2 overflow-hidden rounded-3xl sm:grid-cols-2">
                    @foreach ($imgs as $i => $img)
                        <button type="button" @click="show({{ $i }}, $event.currentTarget)"
                                class="group relative aspect-[4/3] overflow-hidden bg-ink-800">
                            <img src="{{ $img }}" alt="{{ $cottage->altFor($img, $i) }}"
                                 loading="{{ $i === 0 ? 'eager' : 'lazy' }}" referrerpolicy="no-referrer"
                                 class="h-full w-full object-cover transition duration-500 group-hover:scale-[1.03]">
                            <x-gallery-zoom-hint />
                        </button>
                    @endforeach
                </div>

            @elseif ($count === 3)
                <div class="grid gap-2 overflow-hidden rounded-3xl sm:grid-cols-3 sm:grid-rows-2">
                    <button type="button" @click="show(0, $event.currentTarget)"
                            class="group relative aspect-[4/3] overflow-hidden bg-ink-800 sm:col-span-2 sm:row-span-2 sm:aspect-auto">
                        <img src="{{ $imgs[0] }}" alt="{{ $cottage->altFor($imgs[0], 0) }}"
                             class="h-full w-full object-cover transition duration-500 group-hover:scale-[1.02]"
                             referrerpolicy="no-referrer">
                        <x-gallery-zoom-hint />
                    </button>
                    @foreach (array_slice($imgs, 1, 2) as $i => $img)
                        <button type="button" @click="show({{ $i + 1 }}, $event.currentTarget)"
                                class="group relative hidden aspect-[4/3] overflow-hidden bg-ink-800 sm:block">
                            <img src="{{ $img }}" alt="{{ $cottage->altFor($img, $i + 1) }}"
                                 loading="lazy" referrerpolicy="no-referrer"
                                 class="h-full w-full object-cover transition duration-500 group-hover:scale-[1.03]">
                            <x-gallery-zoom-hint />
                        </button>
                    @endforeach
                </div>

            @else
                <div class="grid gap-2 overflow-hidden rounded-3xl sm:grid-cols-4 sm:grid-rows-2">
                    <button type="button" @click="show(0, $event.currentTarget)"
                            class="group relative aspect-[4/3] overflow-hidden bg-ink-800 sm:col-span-2 sm:row-span-2 sm:aspect-auto">
                        <img src="{{ $imgs[0] }}" alt="{{ $cottage->altFor($imgs[0], 0) }}"
                             class="h-full w-full object-cover transition duration-500 group-hover:scale-[1.02]"
                             referrerpolicy="no-referrer">
                        <x-gallery-zoom-hint />
                    </button>

                    @foreach (array_slice($imgs, 1, 4) as $i => $img)
                        <button type="button" @click="show({{ $i + 1 }}, $event.currentTarget)"
                                class="group relative hidden aspect-[4/3] overflow-hidden bg-ink-800 sm:block">
                            <img src="{{ $img }}" alt="{{ $cottage->altFor($img, $i + 1) }}"
                                 loading="lazy" referrerpolicy="no-referrer"
                                 class="h-full w-full object-cover transition duration-500 group-hover:scale-[1.03]">
                            @if ($i === 3 && $count > 5)
                                {{-- the remaining-count badge doubles as the "see all" affordance --}}
                                <span class="absolute inset-0 grid place-items-center bg-ink-900/55 transition group-hover:bg-ink-900/70">
                                    <span class="text-center">
                                        <span class="block font-display text-2xl font-medium text-white">+{{ $count - 5 }}</span>
                                        <span class="mt-0.5 block font-mono text-[9px] uppercase tracking-wider text-white/80">photos</span>
                                    </span>
                                </span>
                            @else
                                <x-gallery-zoom-hint />
                            @endif
                        </button>
                    @endforeach
                </div>

                {{-- mobile: the grid collapses to the hero only, so give an explicit way in --}}
                <button type="button" @click="show(0, $event.currentTarget)"
                        class="mt-3 w-full rounded-full bg-white/10 py-2.5 font-mono text-[10px] uppercase tracking-wider text-white/80 backdrop-blur transition hover:bg-white/20 sm:hidden">
                    View all {{ $count }} photos
                </button>
            @endif
        </div>

        {{-- ---------------------------------------------------------- LIGHTBOX --}}
        <template x-teleport="body">
            <div x-show="open" x-cloak
                 class="fixed inset-0 z-[100] flex flex-col bg-ink-900/97 backdrop-blur-sm"
                 x-transition:enter="transition ease-out duration-200"
                 x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                 x-transition:leave="transition ease-in duration-150"
                 x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
                 role="dialog" aria-modal="true" aria-label="Photo gallery"
                 x-ref="dialog" tabindex="-1"
                 @click.self="hide()">

                {{-- top bar --}}
                <div class="flex items-center justify-between px-5 py-4 sm:px-8">
                    <p class="font-mono text-[11px] uppercase tracking-wider text-white/60">
                        <span x-text="index + 1"></span> / <span x-text="count"></span>
                    </p>
                    <button type="button" @click="hide()" aria-label="Close gallery"
                            class="grid h-10 w-10 place-items-center rounded-full text-white/70 transition hover:bg-white/10 hover:text-white">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M18 6 6 18M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                {{-- stage --}}
                <div class="relative flex min-h-0 flex-1 items-center justify-center px-4 sm:px-16"
                     @touchstart.passive="onTouchStart($event)"
                     @touchend.passive="onTouchEnd($event)">

                    <button type="button" @click="prev()" x-show="hasMultiple" aria-label="Previous photo"
                            class="absolute left-2 z-10 grid h-11 w-11 place-items-center rounded-full bg-white/10 text-white backdrop-blur transition hover:bg-white/20 sm:left-5">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 18l-6-6 6-6"/></svg>
                    </button>

                    <div class="relative flex h-full max-h-full w-full items-center justify-center">
                        <template x-if="current">
                            <img :src="current.full" :alt="current.alt"
                                 @load="loading = false"
                                 @@error="loading = false; $el.src = current.thumb"
                                 referrerpolicy="no-referrer"
                                 class="max-h-full max-w-full rounded-xl object-contain shadow-2xl">
                        </template>

                        <div x-show="loading"
                             class="pointer-events-none absolute inset-0 grid place-items-center">
                            <span class="h-8 w-8 animate-spin rounded-full border-2 border-white/25 border-t-white"></span>
                        </div>
                    </div>

                    <button type="button" @click="next()" x-show="hasMultiple" aria-label="Next photo"
                            class="absolute right-2 z-10 grid h-11 w-11 place-items-center rounded-full bg-white/10 text-white backdrop-blur transition hover:bg-white/20 sm:right-5">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 6l6 6-6 6"/></svg>
                    </button>
                </div>

                {{-- caption --}}
                <div class="px-5 pt-4 text-center sm:px-8">
                    <p class="mx-auto max-w-2xl text-sm text-white/70" x-text="current?.alt"></p>
                </div>

                {{-- thumbnail strip --}}
                <div x-show="hasMultiple" class="overflow-x-auto px-5 py-4 sm:px-8">
                    <div class="mx-auto flex w-max gap-2">
                        <template x-for="(img, i) in images" :key="i">
                            <button type="button" @click="goTo(i)"
                                    :aria-label="'View photo ' + (i + 1)"
                                    :aria-current="i === index"
                                    class="relative h-14 w-20 shrink-0 overflow-hidden rounded-lg transition"
                                    :class="i === index
                                        ? 'ring-2 ring-white opacity-100'
                                        : 'opacity-45 hover:opacity-80'">
                                <img :src="img.thumb" :alt="''" loading="lazy"
                                     referrerpolicy="no-referrer"
                                     class="h-full w-full object-cover">
                            </button>
                        </template>
                    </div>
                </div>
            </div>
        </template>
    </section>

    {{-- ==================================== 2. HEADER + STICKY BOOKING PANEL --}}
    <section class="bg-white py-10">
        <div class="mx-auto grid max-w-7xl gap-10 px-6 lg:grid-cols-[1fr_380px] lg:gap-14 lg:px-8">

            {{-- LEFT: identity + facts --}}
            <div class="min-w-0">
                @if ($degraded)
                    <div class="mb-6 flex items-start gap-2.5 rounded-2xl bg-amber-50 px-4 py-3 text-sm text-amber-900 ring-1 ring-amber-200">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="mt-0.5 shrink-0">
                            <circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/>
                        </svg>
                        <span>Some live data couldn&rsquo;t be loaded. Prices and availability shown may be incomplete &mdash; please confirm before booking.</span>
                    </div>
                @endif

                <p class="font-mono text-[11px] uppercase tracking-[0.2em] text-brand-600">
                    {{ $cottage->locationLine() ?: 'Lockeport, Nova Scotia' }}
                </p>
                <h1 class="mt-2 font-display text-3xl font-medium leading-tight text-ink-900 sm:text-4xl">
                    {{ $cottage->name }}
                </h1>

                {{-- key facts --}}
                <div class="mt-6 flex flex-wrap items-center gap-x-6 gap-y-3 border-y border-fog-200 py-5">
                    @foreach ([
                        ['guests', $cottage->maxGuests, 'Guests'],
                        ['bed',    $cottage->bedrooms,  'Bedrooms'],
                        ['bath',   $cottage->bathrooms, 'Bathrooms'],
                    ] as [$icon, $value, $label])
                        @if ($value)
                            <div class="flex items-center gap-2.5">
                                <span class="grid h-9 w-9 place-items-center rounded-full bg-brand-50 text-brand-600">
                                    @if ($icon === 'guests')
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/></svg>
                                    @elseif ($icon === 'bed')
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M2 20v-8a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v8M2 16h20M6 10V6h12v4"/></svg>
                                    @else
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 12h16v5a3 3 0 0 1-3 3H7a3 3 0 0 1-3-3v-5zM7 12V6a2 2 0 0 1 4 0"/></svg>
                                    @endif
                                </span>
                                <span>
                                    <span class="block text-sm font-semibold text-ink-900">{{ $value }}</span>
                                    <span class="block font-mono text-[10px] uppercase tracking-wide text-tide-500">{{ $label }}</span>
                                </span>
                            </div>
                        @endif
                    @endforeach

                    @if ($cottage->petFriendly)
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-brand-50 px-3 py-1.5 font-mono text-[10px] font-semibold uppercase tracking-wide text-brand-700 ring-1 ring-brand-100">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><circle cx="5.5" cy="9.5" r="2.2"/><circle cx="10" cy="6" r="2.2"/><circle cx="14" cy="6" r="2.2"/><circle cx="18.5" cy="9.5" r="2.2"/><path d="M12 12.5c2.6 0 4.8 1.9 4.8 4.2 0 1.7-1.3 2.8-3 2.8-.9 0-1.3-.3-1.8-.3s-.9.3-1.8.3c-1.7 0-3-1.1-3-2.8 0-2.3 2.2-4.2 4.8-4.2z"/></svg>
                            Pet-friendly
                        </span>
                    @endif
                </div>

                {{-- ============================================ 3. CALENDAR --}}
                <div id="availability" class="mt-12 scroll-mt-28">
                    <div class="mb-5 flex flex-wrap items-baseline justify-between gap-3">
                        <h2 class="font-display text-2xl font-medium text-ink-900">Availability &amp; nightly rates</h2>
                        <span class="flex items-center gap-2 font-mono text-[10px] uppercase tracking-wide text-tide-500">
                            <span class="h-1.5 w-1.5 animate-pulse rounded-full bg-brand-500"></span>
                            <span x-text="loading ? 'Loading rates…' : 'Live Prices'"></span>
                        </span>
                    </div>

                    <div class="rounded-3xl bg-fog-50 p-5 ring-1 ring-black/5 sm:p-6">
                        {{-- month nav --}}
                        <div class="mb-4 flex items-center justify-between">
                            <button type="button" @click="prevMonth()" :disabled="atFloor"
                                    class="grid h-9 w-9 place-items-center rounded-full text-ink-800 transition hover:bg-white disabled:opacity-30"
                                    aria-label="Previous month">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 18l-6-6 6-6"/></svg>
                            </button>
                            <p class="font-mono text-[10px] uppercase tracking-wide text-tide-500">
                                tap a date to start, tap again to set check-out
                            </p>
                            <button type="button" @click="nextMonth()"
                                    class="grid h-9 w-9 place-items-center rounded-full text-ink-800 transition hover:bg-white"
                                    aria-label="Next month">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 6l6 6-6 6"/></svg>
                            </button>
                        </div>

                        <div class="grid gap-8 lg:grid-cols-2">
                            <template x-for="offset in [0, 1]" :key="'m' + offset">
                                <div class="relative" :class="offset === 1 ? 'hidden lg:block' : ''">
                                    <p class="mb-3 text-center font-display text-base text-ink-900" x-text="monthLabel(offset)"></p>

                                    {{-- Per-month spinner: paging into an unfetched month
                                         shows progress on that month only. --}}
                                    <div x-show="monthLoading(offset)" x-cloak
                                         class="absolute inset-x-0 bottom-0 top-8 z-10 grid place-items-center rounded-xl bg-fog-50/80 backdrop-blur-[1px]">
                                        <div class="text-center">
                                            <span class="mx-auto block h-6 w-6 animate-spin rounded-full border-2 border-fog-300 border-t-brand-600"></span>
                                            <span class="mt-2 block font-mono text-[9px] uppercase tracking-wider text-tide-500">
                                                Loading rates
                                            </span>
                                        </div>
                                    </div>

                                    <div class="grid grid-cols-7 gap-1 text-center">
                                        <template x-for="(d, i) in ['S','M','T','W','T','F','S']" :key="'h'+offset+i">
                                            <span class="pb-2 font-mono text-[10px] text-tide-400" x-text="d"></span>
                                        </template>

                                        <template x-for="cell in grid(offset)" :key="cell.key">
                                            <div class="relative">
                                                <button
                                                    x-show="!cell.blank"
                                                    type="button"
                                                    @click="select(cell)"
                                                    @mouseenter="hover(cell)"
                                                    :disabled="cell.disabled"
                                                    :title="dayTitle(cell)"
                                                    class="relative flex h-14 w-full flex-col items-center justify-center gap-0.5 rounded-xl border text-sm transition"
                                                    :class="{
                                                        'border-transparent text-fog-300 cursor-not-allowed': cell.isPast,
                                                        'border-transparent text-tide-300 line-through decoration-1 cursor-not-allowed': cell.isBooked,
                                                        'border-transparent text-tide-400 cursor-not-allowed relative': cell.blockedStart,
                                                        'border-fog-200 bg-white text-ink-800 hover:border-brand-400 hover:bg-brand-50 cursor-pointer': !cell.disabled && !cell.isArrival && !cell.isDeparture && !cell.inRange,
                                                        'border-brand-600 bg-brand-600 text-white shadow-md shadow-brand-600/25': cell.isArrival || cell.isDeparture,
                                                        'border-brand-100 bg-brand-50 text-brand-800': (cell.inRange || cell.inHover) && !cell.isArrival && !cell.isDeparture,
                                                    }"
                                                >
                                                    <span class="font-medium leading-none" x-text="cell.day"></span>
                                                    <span class="font-mono text-[9px] leading-none"
                                                          :class="(cell.isArrival || cell.isDeparture) ? 'text-white/80' : 'text-tide-500'"
                                                          x-show="cell.price !== null && !cell.isPast && !cell.isBooked && !cell.blockedStart"
                                                          x-text="priceLabel(cell.price)"></span>

                                                    {{-- Free night, but too short a gap to satisfy the
                                                         minimum stay — cross it out so it never reads
                                                         as a selectable check-in date. --}}
                                                    <span x-show="cell.blockedStart" x-cloak
                                                          class="pointer-events-none absolute inset-0 grid place-items-center">
                                                        <svg viewBox="0 0 40 40" class="h-full w-full text-fog-300" aria-hidden="true">
                                                            <line x1="9" y1="31" x2="31" y2="9"
                                                                  stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                                                        </svg>
                                                    </span>
                                                </button>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </template>
                        </div>

                        {{-- legend + selection --}}
                        <div class="mt-6 flex flex-col gap-3 border-t border-fog-200 pt-4 sm:flex-row sm:items-center sm:justify-between">
                            <div class="flex flex-wrap items-center gap-4 font-mono text-[10px] uppercase tracking-wide text-tide-500">
                                <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-brand-600"></span>Selected</span>
                                <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-sm border border-fog-300 bg-white"></span>Available</span>
                                <span class="flex items-center gap-1.5"><span class="h-px w-3 bg-tide-300"></span>Booked</span>
                                <span class="flex items-center gap-1.5">
                                    <svg viewBox="0 0 12 12" class="h-2.5 w-2.5 text-fog-400"><line x1="2" y1="10" x2="10" y2="2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                                    Too short
                                </span>
                            </div>
                            <div class="flex items-center gap-4">
                                <p class="font-mono text-[11px] text-tide-500" x-text="selectionHint"></p>
                                <button type="button" @click="clear()" x-show="arrival"
                                        class="text-xs font-medium text-brand-600 hover:text-brand-700">Clear</button>
                            </div>
                        </div>
                    </div>

                    {{-- next open windows --}}
                    @if (!empty($windows))
                        <div class="mt-5">
                            <p class="font-mono text-[10px] uppercase tracking-[0.12em] text-tide-500">Next open windows</p>
                            <div class="mt-2 flex flex-wrap gap-1.5">
                                @foreach ($windows as $w)
                                    @php
                                        $ws = \Illuminate\Support\Carbon::parse($w['start']);
                                        $we = \Illuminate\Support\Carbon::parse($w['end'])->addDay();
                                    @endphp
                                    <button type="button"
                                            @click="pickWindow('{{ $ws->toDateString() }}', '{{ $we->toDateString() }}')"
                                            class="inline-flex items-baseline gap-1.5 rounded-full bg-brand-50 px-3 py-1.5 text-[11px] font-medium text-brand-800 ring-1 ring-brand-100 transition hover:bg-brand-100">
                                        {{ $ws->format('M j') }} &ndash; {{ $we->format($ws->month === $we->month ? 'j' : 'M j') }}
                                        <span class="font-mono text-[9px] text-brand-600">{{ $w['nights'] }}n</span>
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>

                {{-- =============================== 4. LIVE BREAKDOWN --}}
                <div x-show="arrival && departure" x-cloak class="mt-10 scroll-mt-28" id="breakdown">
                    <h2 class="mb-4 font-display text-2xl font-medium text-ink-900">Your stay breakdown</h2>

                    <div class="rounded-3xl bg-gradient-to-br from-brand-50 to-fog-50 p-6 ring-1 ring-brand-100">
                        <template x-if="quoteLoading">
                            <p class="py-4 text-center font-mono text-xs text-tide-500">Pricing your stay&hellip;</p>
                        </template>

                        {{-- A rule from Lodgify the guest can act on --}}
                        <template x-if="!quoteLoading && quoteRejected">
                            <div class="flex items-start gap-2.5 py-3">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="mt-0.5 shrink-0 text-amber-600">
                                    <circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/>
                                </svg>
                                <div>
                                    <p class="text-sm font-medium text-ink-900" x-text="quoteError"></p>
                                    <p class="mt-1 text-[11px] text-tide-500">Adjust your dates and we&rsquo;ll reprice instantly.</p>
                                </div>
                            </div>
                        </template>

                        {{-- Something broke on our side — do not blame the cottage --}}
                        <template x-if="!quoteLoading && quoteFailed">
                            <div class="flex items-start gap-2.5 py-3">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="mt-0.5 shrink-0 text-tide-400">
                                    <circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/>
                                </svg>
                                <div>
                                    <p class="text-sm text-tide-700" x-text="quoteError"></p>
                                    <p class="mt-1 text-[11px] text-tide-500">
                                        <a href="mailto:info@oceanescapecottages.ca" class="font-medium text-brand-600 hover:underline">Email us</a>
                                        or call <a href="tel:9023981020" class="font-medium text-brand-600 hover:underline">902-398-1020</a> and we&rsquo;ll sort it out.
                                    </p>
                                </div>
                            </div>
                        </template>

                        <template x-if="!quoteLoading && quote">
                            <div>
                                <div class="space-y-2.5 text-sm">
                                    {{-- One line per rate period, so a stay spanning a season
                                         change itemises correctly instead of averaging. --}}
                                    <template x-for="seg in segments" :key="seg.start">
                                        <div class="flex justify-between gap-3">
                                            <span class="text-tide-600">
                                                <span x-text="money(seg.price)"></span> &times;
                                                <span x-text="seg.nights"></span>
                                                <span x-text="seg.nights > 1 ? 'nights' : 'night'"></span>
                                                <em class="not-italic text-[11px] text-tide-400"
                                                    x-text="'(' + segmentLabel(seg) + (seg.season ? ' · ' + seg.season : '') + ')'"></em>
                                            </span>
                                            <span class="font-medium text-ink-900" x-text="money(seg.subtotal)"></span>
                                        </div>
                                    </template>

                                    <div class="flex justify-between border-t border-brand-100/70 pt-2.5"
                                         x-show="hasMultipleRates">
                                        <span class="text-tide-600">Accommodation subtotal</span>
                                        <span class="font-medium text-ink-900" x-text="money(quote.rental)"></span>
                                    </div>

                                    <template x-for="fee in (quote.fees || [])" :key="fee.name">
                                        <div class="flex justify-between">
                                            <span class="text-tide-600" x-text="fee.name"></span>
                                            <span class="font-medium text-ink-900" x-text="money(fee.value ?? fee.amount)"></span>
                                        </div>
                                    </template>

                                    <template x-for="tax in (quote.taxes || [])" :key="tax.name">
                                        <div class="flex justify-between">
                                            <span class="text-tide-600" x-text="tax.name"></span>
                                            <span class="font-medium text-ink-900" x-text="money(tax.value)"></span>
                                        </div>
                                    </template>
                                </div>

                                <template x-if="selectedAddonList.length">
                                    <div class="mt-3 space-y-2.5 border-t border-brand-100/70 pt-3 text-sm">
                                        <template x-for="addon in selectedAddonList" :key="addon.id">
                                            <div class="flex justify-between gap-3">
                                                <span class="text-tide-600">
                                                    <span x-text="addon.name"></span>
                                                    <em class="not-italic text-[11px] text-tide-400"
                                                        x-text="addonQtyLabel(addon)
                                                                ? '(' + money(addon.price) + ' × ' + addonQtyLabel(addon) + ')'
                                                                : ''"></em>
                                                </span>
                                                <span class="font-medium text-ink-900" x-text="money(addonCost(addon))"></span>
                                            </div>
                                        </template>
                                    </div>
                                </template>

                                <div class="mt-4 flex items-center justify-between border-t border-brand-100 pt-4">
                                    <span class="font-display text-lg text-ink-900">Total</span>
                                    <span class="font-display text-2xl font-medium text-brand-700" x-text="money(grandTotal)"></span>
                                </div>

                                <template x-if="(quote.schedule || []).length > 1">
                                    <div class="mt-4 rounded-2xl bg-white/70 p-4">
                                        <p class="font-mono text-[10px] uppercase tracking-wide text-tide-500">Payment schedule</p>
                                        <template x-for="p in quote.schedule" :key="p.name">
                                            <div class="mt-1.5 flex justify-between text-xs">
                                                <span class="text-tide-600" x-text="p.name"></span>
                                                <span class="font-medium text-ink-900" x-text="money(p.amount)"></span>
                                            </div>
                                        </template>
                                    </div>
                                </template>

                                {{-- Deposit and cancellation terms, straight from Lodgify --}}
                                <template x-if="quote.security_deposit_text || quote.cancellation_policy">
                                    <div class="mt-4 space-y-1.5 rounded-2xl bg-white/70 p-4">
                                        <template x-if="quote.cancellation_policy">
                                            <p class="text-[11px] leading-relaxed text-tide-600">
                                                <span class="font-mono text-[9px] uppercase tracking-wide text-tide-500">Cancellation</span><br>
                                                <span x-text="quote.cancellation_policy"></span>
                                            </p>
                                        </template>
                                        <template x-if="quote.security_deposit_text">
                                            <p class="text-[11px] leading-relaxed text-tide-600">
                                                <span class="font-mono text-[9px] uppercase tracking-wide text-tide-500">Security deposit</span><br>
                                                <span x-text="quote.security_deposit_text"></span>
                                            </p>
                                        </template>
                                    </div>
                                </template>

                                <p class="mt-3 text-[11px] text-tide-500">
                                    Computed live by Lodgify for your dates &mdash; includes active rate rules, taxes and fees.
                                </p>
                            </div>
                        </template>
                    </div>
                </div>

                {{-- ============================================ 5. DESCRIPTION --}}
                @if ($cottage->description)
                    <div class="mt-12">
                        <h2 class="mb-4 font-display text-2xl font-medium text-ink-900">About this cottage</h2>
                        <div class="prose prose-sm max-w-none text-tide-700 [&_p]:mb-4 [&_p]:leading-relaxed">
                            {!! \Illuminate\Support\Str::of($cottage->description)->stripTags('<p><br><strong><em><ul><ol><li><h3><h4>') !!}
                        </div>
                    </div>
                @endif

                {{-- ============================================== 6. AMENITIES --}}
                @if ($cottage->amenityCount() > 0)
                    <div class="mt-12">
                        <div class="mb-5 flex items-baseline justify-between gap-3">
                            <h2 class="font-display text-2xl font-medium text-ink-900">Amenities</h2>
                            <span class="font-mono text-[10px] uppercase tracking-wide text-tide-500">
                                {{ $cottage->amenityCount() }} listed
                            </span>
                        </div>

                        <div x-data="{ expanded: false }">
                            <div class="grid gap-8 sm:grid-cols-2">
                                @foreach ($cottage->amenities as $group => $items)
                                    <div :class="!expanded && {{ $loop->index >= 4 ? 'true' : 'false' }} ? 'hidden' : ''">
                                        <p class="font-mono text-[10px] uppercase tracking-[0.12em] text-brand-600">{{ $group }}</p>
                                        <ul class="mt-2.5 space-y-1.5">
                                            @foreach ($items as $item)
                                                <li class="flex items-start gap-2 text-sm text-tide-700">
                                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="mt-1 shrink-0 text-brand-500"><path d="M20 6 9 17l-5-5"/></svg>
                                                    {{ $item }}
                                                </li>
                                            @endforeach
                                        </ul>
                                    </div>
                                @endforeach
                            </div>

                            @if (count($cottage->amenities) > 4)
                                <button type="button" @click="expanded = !expanded"
                                        class="mt-6 rounded-full px-5 py-2.5 text-sm font-semibold text-brand-700 ring-1 ring-brand-200 transition hover:bg-brand-50">
                                    <span x-text="expanded ? 'Show fewer amenities' : 'Show all amenities'"></span>
                                </button>
                            @endif
                        </div>
                    </div>
                @endif

                {{-- ======================================= 7. SEASONAL RATES --}}
                @if ($seasons->isNotEmpty())
                    <div class="mt-12">
                        <h2 class="mb-2 font-display text-2xl font-medium text-ink-900">Seasonal rates</h2>
                        <p class="mb-5 text-sm text-tide-600">
                            Nightly rates as configured for this cottage. Your final total depends on your dates,
                            length of stay, and any active promotions.
                        </p>

                        <div class="overflow-hidden rounded-3xl ring-1 ring-fog-200">
                            <table class="w-full text-left text-sm">
                                <thead class="bg-fog-50">
                                    <tr class="font-mono text-[10px] uppercase tracking-wide text-tide-500">
                                        <th class="px-5 py-3 font-medium">Period</th>
                                        <th class="px-5 py-3 font-medium">Dates</th>
                                        <th class="px-5 py-3 text-right font-medium">Per night</th>
                                        <th class="hidden px-5 py-3 text-right font-medium sm:table-cell">Min stay</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-fog-200 bg-white">
                                    @foreach ($seasons as $season)
                                        <tr class="{{ $season->isCurrent() ? 'bg-brand-50/40' : '' }}">
                                            <td class="px-5 py-3.5">
                                                <span class="font-medium text-ink-900">{{ $season->name }}</span>
                                                @if ($season->isCurrent())
                                                    <span class="ml-2 rounded-full bg-brand-600 px-2 py-0.5 font-mono text-[9px] uppercase tracking-wide text-white">now</span>
                                                @endif
                                            </td>
                                            <td class="px-5 py-3.5 text-tide-600">{{ $season->dateRangeLabel() }}</td>
                                            <td class="px-5 py-3.5 text-right font-medium text-ink-900">
                                                @if ($season->nightly !== null)
                                                    {{ ($season->currency ?? 'CAD') === 'CAD' ? 'CA$' : $season->currency . ' ' }}{{ number_format($season->nightly, 2) }}
                                                @else
                                                    <span class="text-tide-400">&mdash;</span>
                                                @endif
                                            </td>
                                            <td class="hidden px-5 py-3.5 text-right text-tide-600 sm:table-cell">
                                                {{ $season->minStay ? $season->minStay . ' night' . ($season->minStay > 1 ? 's' : '') : '—' }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif

                {{-- ==================================== 8. RULES & CHECK-IN --}}
                <div class="mt-12">
                    <h2 class="mb-5 font-display text-2xl font-medium text-ink-900">House rules &amp; check-in</h2>

                    <div class="grid gap-4 sm:grid-cols-2">
                        @if ($cottage->checkInTime)
                            <div class="rounded-2xl bg-fog-50 p-5">
                                <p class="font-mono text-[10px] uppercase tracking-wide text-tide-500">Check-in</p>
                                <p class="mt-1 text-lg font-medium text-ink-900">{{ $cottage->checkInTime }}</p>
                            </div>
                        @endif
                        @if ($cottage->checkOutTime)
                            <div class="rounded-2xl bg-fog-50 p-5">
                                <p class="font-mono text-[10px] uppercase tracking-wide text-tide-500">Check-out</p>
                                <p class="mt-1 text-lg font-medium text-ink-900">{{ $cottage->checkOutTime }}</p>
                            </div>
                        @endif
                        @if ($cottage->minStay)
                            <div class="rounded-2xl bg-fog-50 p-5">
                                <p class="font-mono text-[10px] uppercase tracking-wide text-tide-500">Minimum stay</p>
                                <p class="mt-1 text-lg font-medium text-ink-900">{{ $cottage->minStay }} night{{ $cottage->minStay > 1 ? 's' : '' }}</p>
                            </div>
                        @endif
                    </div>

                    <ul class="mt-5 grid gap-2.5 sm:grid-cols-2">
                        @foreach ([
                            ['Pets', $cottage->petFriendly, 'Pets welcome', 'No pets'],
                            ['Smoking', $cottage->smokingAllowed, 'Smoking allowed', 'No smoking'],
                            ['Events', $cottage->partiesAllowed, 'Events allowed', 'No parties or events'],
                            ['Children', $cottage->childrenAllowed, 'Children welcome', 'Not suitable for children'],
                        ] as [$label, $allowed, $yes, $no])
                            <li class="flex items-center gap-3 rounded-2xl px-4 py-3 ring-1 ring-fog-200">
                                <span class="grid h-7 w-7 shrink-0 place-items-center rounded-full {{ $allowed ? 'bg-brand-50 text-brand-600' : 'bg-fog-100 text-tide-400' }}">
                                    @if ($allowed)
                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6"><path d="M20 6 9 17l-5-5"/></svg>
                                    @else
                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6"><path d="M18 6 6 18M6 6l12 12"/></svg>
                                    @endif
                                </span>
                                <span class="text-sm {{ $allowed ? 'text-ink-900' : 'text-tide-600' }}">
                                    {{ $allowed ? $yes : $no }}
                                </span>
                            </li>
                        @endforeach
                    </ul>

                    @if (!empty($cottage->houseRules))
                        <div x-data="{ open: false }" class="mt-5">
                            <ul class="space-y-2 text-sm leading-relaxed text-tide-700">
                                @foreach ($cottage->houseRules as $i => $rule)
                                    <li class="flex gap-2.5" @if ($i >= 4) x-show="open" x-cloak @endif>
                                        <span class="mt-2 h-1 w-1 shrink-0 rounded-full bg-tide-300"></span>
                                        {{ $rule }}
                                    </li>
                                @endforeach
                            </ul>
                            @if (count($cottage->houseRules) > 4)
                                <button type="button" @click="open = !open"
                                        class="mt-3 text-sm font-medium text-brand-600 hover:text-brand-700">
                                    <span x-text="open ? 'Show less' : 'Read all rules'"></span>
                                </button>
                            @endif
                        </div>
                    @endif
                </div>

                {{-- ========================================= 9. LOCATION --}}
                <div class="mt-12">
                    <h2 class="mb-2 font-display text-2xl font-medium text-ink-900">Where you&rsquo;ll be</h2>
                    <p class="mb-5 text-sm text-tide-600">
                        {{ collect([$cottage->addressLine, $cottage->city, $cottage->state, $cottage->postalCode, $cottage->country])->filter()->implode(', ') ?: 'Lockeport, Nova Scotia, Canada' }}
                    </p>

                    @if ($cottage->mapEmbedUrl())
                        <div class="overflow-hidden rounded-3xl ring-1 ring-fog-200">
                            <iframe src="{{ $cottage->mapEmbedUrl() }}"
                                    class="h-[340px] w-full border-0"
                                    loading="lazy"
                                    referrerpolicy="no-referrer-when-downgrade"
                                    title="Map of {{ $cottage->name }}"></iframe>
                        </div>
                    @else
                        <div class="grid h-[200px] place-items-center rounded-3xl bg-fog-50 text-sm text-tide-500 ring-1 ring-fog-200">
                            Map coordinates unavailable for this cottage.
                        </div>
                    @endif

                    @if ($cottage->directionsUrl())
                        <a href="{{ $cottage->directionsUrl() }}" target="_blank" rel="noopener"
                           class="mt-4 inline-flex items-center gap-2 text-sm font-medium text-brand-600 transition hover:gap-3 hover:text-brand-700">
                            Get directions
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                        </a>
                    @endif
                </div>
            </div>

            {{-- RIGHT: sticky booking panel (desktop) --}}
            <aside class="hidden lg:block">
                {{-- max-h + overflow so the panel can never push its own CTA
                     off-screen on a short viewport --}}
                <div class="sticky top-28 max-h-[calc(100vh-8rem)] overflow-y-auto" id="book">
                    <div class="rounded-3xl bg-white p-6 shadow-xl shadow-ink-900/10 ring-1 ring-black/5">
                        {{-- price from --}}
                        <div class="flex items-baseline gap-1.5">
                            <template x-if="quote">
                                <span class="font-display text-3xl font-medium text-ink-900"
                                      x-text="hasMultipleRates
                                                ? money(Math.min(...segments.map(s => s.price ?? Infinity))) + '+'
                                                : money(quote.nightly)"></span>
                            </template>
                            <template x-if="!quote">
                                <span class="font-display text-3xl font-medium text-ink-900">
                                    @if ($priceFrom)
                                        {{ ($cottage->currency ?? 'CAD') === 'CAD' ? 'CA$' : '' }}{{ number_format($priceFrom, 0) }}
                                    @else
                                        &mdash;
                                    @endif
                                </span>
                            </template>
                            <span class="font-mono text-[11px] text-tide-500">
                                <span x-show="!quote">from / night</span>
                                <span x-show="quote" x-cloak>/ night</span>
                            </span>
                        </div>

                        {{-- dates --}}
                        <div class="mt-5 overflow-hidden rounded-2xl ring-1 ring-fog-200">
                            <div class="grid grid-cols-2 divide-x divide-fog-200">
                                <div class="p-3.5">
                                    <p class="font-mono text-[9px] uppercase tracking-wide text-tide-500">Check in</p>
                                    <p class="mt-0.5 text-sm font-medium text-ink-900" x-text="fmt(arrival) || 'Select'"></p>
                                </div>
                                <div class="p-3.5">
                                    <p class="font-mono text-[9px] uppercase tracking-wide text-tide-500">Check out</p>
                                    <p class="mt-0.5 text-sm font-medium text-ink-900" x-text="fmt(departure) || 'Select'"></p>
                                </div>
                            </div>
                            <div class="border-t border-fog-200 p-3.5">
                                <p class="font-mono text-[9px] uppercase tracking-wide text-tide-500">Guests</p>
                                <p class="mt-0.5 text-sm font-medium text-ink-900" x-text="guestSummary"></p>
                            </div>
                        </div>

                        <a href="#availability"
                           x-show="!arrival || !departure"
                           class="mt-3 block rounded-2xl bg-brand-600 py-3.5 text-center text-sm font-semibold text-white transition hover:bg-brand-700">
                            Choose your dates
                        </a>

                        {{-- guest steppers --}}
                        <div x-show="arrival && departure" x-cloak class="mt-4 space-y-2.5">
                            {{-- Caps come from Lodgify occupancy (rules.max_guests), so the
                                 + button disables at capacity instead of failing at quote time. --}}
                            <template x-for="row in [
                                { key:'adults', label:'Adults', min:1 },
                                { key:'children', label:'Children', min:0 },
                                { key:'pets', label:'Pets', min:0 },
                            ]" :key="row.key">
                                <div class="flex items-center justify-between"
                                     x-show="row.key !== 'pets' || rules.pets_allowed">
                                    <span class="text-sm text-tide-700" x-text="row.label"></span>
                                    <div class="flex items-center gap-2.5">
                                        <button type="button" @click="decGuest(row.key, row.min)" :disabled="$data[row.key] <= row.min"
                                                class="grid h-7 w-7 place-items-center rounded-full ring-1 ring-fog-300 text-ink-800 transition hover:ring-brand-400 disabled:opacity-30">&minus;</button>
                                        <span class="w-4 text-center text-sm font-medium" x-text="$data[row.key]"></span>
                                        <button type="button" @click="incGuest(row.key)" :disabled="!canInc(row.key)"
                                                class="grid h-7 w-7 place-items-center rounded-full ring-1 ring-fog-300 text-ink-800 transition hover:ring-brand-400 disabled:opacity-30">+</button>
                                    </div>
                                </div>
                            </template>

                            <p class="pt-1 font-mono text-[10px] text-tide-500" x-show="maxGuests">
                                <span x-show="occupancyFull">Maximum occupancy reached</span>
                                <span x-show="!occupancyFull"
                                      x-text="'Sleeps up to ' + maxGuests + ' guests'"></span>
                            </p>
                        </div>

                        {{-- add-ons: a summary row that opens a dialog.
                             Four extras rendered inline made the panel taller
                             than the viewport, hiding the Book button. --}}
                        <div x-show="arrival && departure && hasAddons" x-cloak
                             class="mt-5 border-t border-fog-200 pt-4">
                            <button type="button" @click="openAddons($event.currentTarget)"
                                    class="flex w-full items-center justify-between gap-3 rounded-2xl px-3 py-3 text-left ring-1 transition"
                                    :class="selectedAddonCount
                                        ? 'bg-brand-50 ring-brand-200 hover:ring-brand-300'
                                        : 'ring-fog-200 hover:ring-brand-300'">
                                <span class="min-w-0">
                                    <span class="block font-mono text-[10px] uppercase tracking-wide text-tide-500">
                                        Extras
                                    </span>
                                    <span class="mt-0.5 block truncate text-sm font-medium text-ink-900"
                                          x-text="addonsSummary"></span>
                                </span>

                                <span class="flex shrink-0 items-center gap-2">
                                    <span x-show="addonsTotal > 0" x-cloak
                                          class="font-mono text-[11px] font-medium text-brand-700"
                                          x-text="'+' + money(addonsTotal)"></span>
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="text-tide-400">
                                        <path d="M9 6l6 6-6 6"/>
                                    </svg>
                                </span>
                            </button>
                        </div>

                        {{-- total + CTA --}}
                        <template x-if="quote">
                            <div class="mt-5 border-t border-fog-200 pt-4">
                                <div class="flex items-center justify-between">
                                    <span class="text-sm text-tide-600">
                                        Stay &middot; <span x-text="nights"></span>
                                        <span x-text="nights > 1 ? 'nights' : 'night'"></span>
                                    </span>
                                    <span class="text-sm font-medium text-ink-900" x-text="money(quote.total)"></span>
                                </div>

                                <div x-show="addonsTotal > 0" x-cloak
                                     class="mt-1.5 flex items-center justify-between">
                                    <span class="text-sm text-tide-600">Extras</span>
                                    <span class="text-sm font-medium text-ink-900" x-text="money(addonsTotal)"></span>
                                </div>

                                <div class="mt-2.5 flex items-center justify-between border-t border-fog-200 pt-2.5">
                                    <span class="font-display text-base text-ink-900">Total</span>
                                    <span class="font-display text-xl font-medium text-brand-700" x-text="money(grandTotal)"></span>
                                </div>
                                <a :href="'#breakdown'" class="mt-1 block text-[11px] text-brand-600 hover:underline">
                                    See full breakdown
                                </a>
                                <button type="button" @click="book()"
                                        class="mt-4 w-full rounded-2xl bg-brand-600 py-3.5 text-sm font-semibold text-white transition hover:bg-brand-700">
                                    Book now
                                </button>
                                <p class="mt-2.5 text-center text-[10px] text-tide-500">
                                    You won&rsquo;t be charged yet
                                </p>
                            </div>
                        </template>

                        <template x-if="quoteLoading">
                            <p class="mt-5 border-t border-fog-200 pt-4 text-center font-mono text-xs text-tide-500">
                                Pricing&hellip;
                            </p>
                        </template>
                    </div>

                    <p class="mt-4 text-center text-[11px] text-tide-500">
                        Questions? <a href="mailto:info@oceanescapecottages.ca" class="font-medium text-brand-600 hover:underline">Email us</a>
                        or call <a href="tel:9023981020" class="font-medium text-brand-600 hover:underline">902-398-1020</a>
                    </p>
                </div>
            </aside>
        </div>
    </section>

    {{-- ==================================== ADD-ONS DIALOG --}}
    <template x-teleport="body">
        <div x-show="addonsOpen" x-cloak
             class="fixed inset-0 z-[90] flex items-end justify-center sm:items-center"
             role="dialog" aria-modal="true" aria-label="Add extras to your stay"
             @keydown.window="onAddonsKey($event)">

            <div x-show="addonsOpen"
                 x-transition:enter="transition ease-out duration-200"
                 x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                 x-transition:leave="transition ease-in duration-150"
                 x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
                 class="absolute inset-0 bg-ink-900/50 backdrop-blur-sm"
                 @click="closeAddons()"></div>

            <div x-show="addonsOpen"
                 x-transition:enter="transition ease-out duration-250"
                 x-transition:enter-start="opacity-0 translate-y-8 sm:translate-y-4 sm:scale-95"
                 x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                 x-transition:leave="transition ease-in duration-150"
                 x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                 x-transition:leave-end="opacity-0 translate-y-6 sm:scale-95"
                 x-ref="addonsDialog" tabindex="-1"
                 class="relative flex max-h-[88vh] w-full flex-col overflow-hidden rounded-t-3xl bg-white shadow-2xl sm:max-w-lg sm:rounded-3xl">

                {{-- header --}}
                <div class="flex items-start justify-between gap-4 border-b border-fog-200 px-6 py-5">
                    <div>
                        <h3 class="font-display text-xl font-medium text-ink-900">Add to your stay</h3>
                        <p class="mt-1 text-[11px] leading-snug text-tide-500">
                            Requested at booking &mdash; confirmed by us before you arrive.
                        </p>
                    </div>
                    <button type="button" @click="closeAddons()" aria-label="Close"
                            class="grid h-9 w-9 shrink-0 place-items-center rounded-full text-tide-400 transition hover:bg-fog-100 hover:text-ink-900">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M18 6 6 18M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                {{-- list --}}
                <div class="min-h-0 flex-1 overflow-y-auto px-6 py-5">
                    <div class="space-y-3">
                        <template x-for="addon in addons" :key="'modal-' + addon.id">
                            <div class="rounded-2xl p-4 ring-1 transition"
                                 :class="isSelected(addon) ? 'bg-brand-50 ring-brand-200' : 'ring-fog-200'">

                                <div class="flex items-start justify-between gap-4">
                                    <button type="button" @click="toggleAddon(addon)"
                                            :disabled="addon.required"
                                            class="flex min-w-0 flex-1 items-start gap-3 text-left disabled:cursor-default">
                                        <img x-show="addon.image" :src="addon.image" :alt="addon.name"
                                             loading="lazy" referrerpolicy="no-referrer"
                                             class="h-12 w-12 shrink-0 rounded-xl object-cover ring-1 ring-black/5">
                                        <span class="min-w-0">
                                            <span class="block text-sm font-semibold text-ink-900" x-text="addon.name"></span>
                                            <span x-show="addon.description" x-cloak
                                                  class="mt-0.5 block text-[12px] leading-snug text-tide-600"
                                                  x-text="addon.description"></span>
                                            <span class="mt-1 block font-mono text-[11px] text-tide-500">
                                                <span x-text="money(addon.price)"></span>
                                                <span x-text="addonUnitLabel(addon)"></span>
                                                <span x-show="addon.required" class="text-brand-600"> · required</span>
                                            </span>
                                        </span>
                                    </button>

                                    <div class="shrink-0">
                                        <button type="button" @click="toggleAddon(addon)"
                                                x-show="!isSelected(addon)"
                                                class="rounded-full bg-ink-900 px-4 py-1.5 font-mono text-[10px] uppercase tracking-wide text-white transition hover:bg-ink-800">
                                            Add
                                        </button>
                                        <button type="button" @click="toggleAddon(addon)"
                                                x-show="isSelected(addon) && !addon.required" x-cloak
                                                class="rounded-full px-4 py-1.5 font-mono text-[10px] uppercase tracking-wide text-tide-600 ring-1 ring-fog-300 transition hover:bg-white">
                                            Remove
                                        </button>
                                        <span x-show="isSelected(addon) && addon.required" x-cloak
                                              class="block rounded-full bg-brand-600 px-4 py-1.5 font-mono text-[10px] uppercase tracking-wide text-white">
                                            Included
                                        </span>
                                    </div>
                                </div>

                                {{-- quantity dimensions, only where they apply --}}
                                <div x-show="isSelected(addon) && hasSteppers(addon)" x-cloak
                                     class="mt-4 grid gap-3 border-t border-brand-100/70 pt-4 sm:grid-cols-2">

                                    <div x-show="needsPersons(addon)"
                                         class="flex items-center justify-between rounded-xl bg-white/70 px-3 py-2">
                                        <span class="font-mono text-[10px] uppercase tracking-wide text-tide-500">Guests</span>
                                        <div class="flex items-center gap-2.5">
                                            <button type="button" @click="decPersons(addon)"
                                                    :disabled="state(addon).persons <= 1"
                                                    class="grid h-7 w-7 place-items-center rounded-full ring-1 ring-fog-300 transition hover:ring-brand-400 disabled:opacity-30">&minus;</button>
                                            <span class="w-5 text-center text-sm font-medium" x-text="state(addon).persons"></span>
                                            <button type="button" @click="incPersons(addon)"
                                                    :disabled="!canIncPersons(addon)"
                                                    class="grid h-7 w-7 place-items-center rounded-full ring-1 ring-fog-300 transition hover:ring-brand-400 disabled:opacity-30">+</button>
                                        </div>
                                    </div>

                                    <div x-show="needsNights(addon)"
                                         class="flex items-center justify-between rounded-xl bg-white/70 px-3 py-2">
                                        <span class="font-mono text-[10px] uppercase tracking-wide text-tide-500">Nights</span>
                                        <div class="flex items-center gap-2.5">
                                            <button type="button" @click="decNights(addon)"
                                                    :disabled="state(addon).nights <= 1"
                                                    class="grid h-7 w-7 place-items-center rounded-full ring-1 ring-fog-300 transition hover:ring-brand-400 disabled:opacity-30">&minus;</button>
                                            <span class="w-5 text-center text-sm font-medium" x-text="state(addon).nights"></span>
                                            <button type="button" @click="incNights(addon)"
                                                    :disabled="!canIncNights(addon)"
                                                    class="grid h-7 w-7 place-items-center rounded-full ring-1 ring-fog-300 transition hover:ring-brand-400 disabled:opacity-30">+</button>
                                        </div>
                                    </div>
                                </div>

                                <div x-show="isSelected(addon)" x-cloak
                                     class="mt-3 flex items-baseline justify-between">
                                    <span class="font-mono text-[10px] text-tide-400" x-text="addonQtyLabel(addon)"></span>
                                    <span class="font-display text-base font-medium text-brand-700" x-text="money(addonCost(addon))"></span>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>

                {{-- footer --}}
                <div class="border-t border-fog-200 bg-fog-50 px-6 py-4">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="font-mono text-[10px] uppercase tracking-wide text-tide-500">Extras total</p>
                            <p class="font-display text-xl font-medium text-ink-900" x-text="money(addonsTotal)"></p>
                        </div>
                        <div class="flex items-center gap-2">
                            <button type="button" @click="clearAddons()"
                                    x-show="selectedAddonCount > 0" x-cloak
                                    class="rounded-full px-4 py-2.5 text-sm font-medium text-tide-600 transition hover:text-ink-900">
                                Clear all
                            </button>
                            <button type="button" @click="closeAddons()"
                                    class="rounded-full bg-brand-600 px-6 py-2.5 text-sm font-semibold text-white transition hover:bg-brand-700">
                                Done
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </template>

    {{-- ============================== STICKY BOOKING BAR (mobile) --}}
    <div class="fixed inset-x-0 bottom-0 z-40 border-t border-fog-200 bg-white/95 px-4 py-3 shadow-[0_-4px_20px_rgba(0,0,0,0.06)] backdrop-blur lg:hidden">
        <div class="flex items-center gap-3">
            <div class="min-w-0 flex-1">
                <template x-if="quote">
                    <div>
                        <p class="truncate font-display text-lg font-medium text-ink-900" x-text="money(grandTotal)"></p>
                        <p class="font-mono text-[10px] text-tide-500">
                            total &middot; <span x-text="nights"></span>
                            <span x-text="nights > 1 ? 'nights' : 'night'"></span>
                        </p>
                    </div>
                </template>
                <template x-if="!quote">
                    <div>
                        <p class="truncate font-display text-lg font-medium text-ink-900">
                            @if ($priceFrom)
                                {{ ($cottage->currency ?? 'CAD') === 'CAD' ? 'CA$' : '' }}{{ number_format($priceFrom, 0) }}
                                <span class="font-mono text-[11px] font-normal text-tide-500">from / night</span>
                            @else
                                <span class="font-mono text-sm font-normal text-tide-500">Select dates for pricing</span>
                            @endif
                        </p>
                        <p class="font-mono text-[10px] text-tide-500" x-show="arrival && !departure">
                            Now pick check-out
                        </p>
                    </div>
                </template>
            </div>

            <a href="#availability" x-show="!quote"
               class="shrink-0 rounded-full bg-brand-600 px-6 py-3 text-sm font-semibold text-white">
                Choose dates
            </a>

            <button type="button" @click="openAddons($event.currentTarget)"
                    x-show="quote && hasAddons" x-cloak
                    aria-label="Add extras"
                    class="relative shrink-0 rounded-full px-4 py-3 text-sm font-semibold text-brand-700 ring-1 ring-brand-200">
                Extras
                <span x-show="selectedAddonCount > 0" x-cloak
                      class="absolute -right-1 -top-1 grid h-5 w-5 place-items-center rounded-full bg-brand-600 font-mono text-[10px] text-white"
                      x-text="selectedAddonCount"></span>
            </button>

            <button type="button" @click="book()" x-show="quote" x-cloak
                    class="shrink-0 rounded-full bg-brand-600 px-6 py-3 text-sm font-semibold text-white">
                Book now
            </button>
        </div>
    </div>

    {{-- spacer so the mobile bar never covers the footer --}}
    <div class="h-20 lg:hidden"></div>

</div>
</x-website-layout>