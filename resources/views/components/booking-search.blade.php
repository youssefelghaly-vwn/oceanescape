@props(['class' => ''])

<div
    x-data="bookingSearch()"
    @click.outside="open = false; guestsOpen = false"
    class="relative {{ $class }}"
>
    {{-- Search bar --}}
    <div class="flex flex-col overflow-hidden rounded-3xl bg-white shadow-2xl shadow-ink-900/25 ring-1 ring-black/5 sm:flex-row sm:items-stretch sm:rounded-full">

        <button type="button" @click="open = !open; guestsOpen = false; activeField = 'arrival'"
                class="flex flex-1 items-center gap-3 px-6 py-4 text-left transition hover:bg-fog-50 sm:py-3.5">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" class="shrink-0 text-brand-600">
                <rect x="3" y="5" width="18" height="16" rx="2" /><path d="M3 10h18M8 3v4M16 3v4" />
            </svg>
            <span>
                <span class="block font-mono text-[10px] uppercase tracking-wide text-tide-500">Check in</span>
                <span class="block text-sm font-medium text-ink-900" x-text="fmt(arrival) || 'Add date'"></span>
            </span>
        </button>

        <div class="hidden w-px bg-fog-200 sm:block"></div>

        <button type="button" @click="open = !open; guestsOpen = false; activeField = 'departure'"
                class="flex flex-1 items-center gap-3 border-t border-fog-200 px-6 py-4 text-left transition hover:bg-fog-50 sm:border-t-0 sm:py-3.5">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" class="shrink-0 text-brand-600">
                <rect x="3" y="5" width="18" height="16" rx="2" /><path d="M3 10h18M8 3v4M16 3v4" />
            </svg>
            <span>
                <span class="block font-mono text-[10px] uppercase tracking-wide text-tide-500">Check out</span>
                <span class="block text-sm font-medium text-ink-900" x-text="fmt(departure) || 'Add date'"></span>
            </span>
        </button>

        <div class="hidden w-px bg-fog-200 sm:block"></div>

        <button type="button" @click="guestsOpen = !guestsOpen; open = false"
                class="flex flex-1 items-center gap-3 border-t border-fog-200 px-6 py-4 text-left transition hover:bg-fog-50 sm:border-t-0 sm:py-3.5">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" class="shrink-0 text-brand-600">
                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" /><circle cx="9" cy="7" r="4" />
                <path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75" />
            </svg>
            <span>
                <span class="block font-mono text-[10px] uppercase tracking-wide text-tide-500">Guests</span>
                <span class="block text-sm font-medium text-ink-900" x-text="guestSummary"></span>
            </span>
        </button>

        <div class="p-2 sm:pl-0">
            <button type="button" @click="search()" :disabled="!canSearch"
                    class="flex h-full w-full items-center justify-center gap-2 rounded-2xl bg-brand-600 px-6 py-4 text-sm font-semibold text-white transition hover:bg-brand-700 disabled:cursor-not-allowed disabled:opacity-40 sm:aspect-square sm:rounded-full sm:py-0">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8" /><path d="m21 21-4.35-4.35" /></svg>
                <span class="sm:hidden">Search</span>
            </button>
        </div>
    </div>

    {{-- Calendar dropdown --}}
    <div
        x-show="open"
        x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 translate-y-2" x-transition:enter-end="opacity-100 translate-y-0"
        x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
        class="fixed inset-x-3 top-24 z-50 max-h-[80vh] overflow-y-auto rounded-3xl bg-white p-5 shadow-2xl shadow-ink-900/25 ring-1 ring-black/5 sm:absolute sm:inset-x-auto sm:top-full sm:mt-3 sm:w-[640px] sm:max-h-none sm:p-6"
        style="display: none;"
        @click.stop
    >
        <div class="mb-4 flex items-center justify-between">
            <button type="button" @click="prevMonth()" class="grid h-9 w-9 place-items-center rounded-full text-ink-800 transition hover:bg-fog-100" aria-label="Previous month">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 18l-6-6 6-6" /></svg>
            </button>
            <div class="flex items-center gap-2 font-mono text-[11px] uppercase tracking-wide text-tide-500">
                <span class="h-1.5 w-1.5 animate-pulse rounded-full bg-brand-500"></span> Live availability
            </div>
            <button type="button" @click="nextMonth()" class="grid h-9 w-9 place-items-center rounded-full text-ink-800 transition hover:bg-fog-100" aria-label="Next month">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 6l6 6-6 6" /></svg>
            </button>
        </div>

        <div class="grid grid-cols-1 gap-8 sm:grid-cols-2">
            <template x-for="offset in [0, 1]" :key="offset">
                <div :class="offset === 1 ? 'hidden sm:block' : ''">
                    <p class="mb-3 text-center font-display text-base text-ink-900" x-text="monthLabel(offset)"></p>

                    <div class="grid grid-cols-7 gap-y-1 text-center">
                        <template x-for="d in ['S', 'M', 'T', 'W', 'T', 'F', 'S']" :key="offset + d + Math.random()">
                            <span class="pb-2 font-mono text-[10px] text-tide-400" x-text="d"></span>
                        </template>

                        <template x-for="(cell, i) in grid(offset)" :key="offset + '-' + i">
                            <div class="relative aspect-square">
                                <button
                                    x-show="!cell.blank"
                                    type="button"
                                    @click="select(cell)"
                                    :disabled="cell.disabled"
                                    :title="cell.isBooked ? 'Fully booked' : (cell.isLimited ? 'Only a couple cottages left' : '')"
                                    class="relative grid h-full w-full place-items-center rounded-full text-sm transition"
                                    :class="{
                                        'text-fog-300 cursor-not-allowed': cell.isPast,
                                        'text-tide-400 cursor-not-allowed line-through decoration-1 decoration-fog-300': cell.isBooked,
                                        'text-ink-800 hover:bg-brand-50 cursor-pointer': !cell.disabled && !cell.isArrival && !cell.isDeparture,
                                        'bg-brand-600 text-white font-semibold shadow-md shadow-brand-600/30': cell.isArrival || cell.isDeparture,
                                        'bg-brand-50 text-brand-800 rounded-none': cell.inRange,
                                    }"
                                >
                                    <span x-text="cell.day"></span>
                                    <span x-show="cell.isLimited && !cell.disabled" class="absolute bottom-1 h-1 w-1 rounded-full bg-buoy-500"></span>
                                </button>
                            </div>
                        </template>
                    </div>
                </div>
            </template>
        </div>

        <div class="mt-6 flex flex-col gap-3 border-t border-fog-200 pt-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex flex-wrap items-center gap-4 font-mono text-[10px] uppercase tracking-wide text-tide-500">
                <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-brand-600"></span>Selected</span>
                <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-buoy-500"></span>Few left</span>
                <span class="flex items-center gap-1.5"><span class="h-px w-3 -rotate-12 bg-tide-400"></span>Booked</span>
            </div>
            <div class="flex items-center gap-4">
                <p class="font-mono text-[11px] text-tide-500">
                    <span x-show="!nights">Select your dates</span>
                    <span x-show="nights" x-text="nights + ' night' + (nights > 1 ? 's' : '')"></span>
                </p>
                <button type="button" @click="clear()" class="text-xs font-medium text-brand-600 hover:text-brand-700">Clear</button>
            </div>
        </div>
    </div>

    {{-- Guests dropdown --}}
    <div
        x-show="guestsOpen"
        x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 translate-y-2" x-transition:enter-end="opacity-100 translate-y-0"
        x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
        class="fixed inset-x-3 top-24 z-50 rounded-3xl bg-white p-6 shadow-2xl shadow-ink-900/25 ring-1 ring-black/5 sm:absolute sm:inset-x-auto sm:right-0 sm:top-full sm:mt-3 sm:w-80"
        style="display: none;"
        @click.stop
    >
        <template x-for="row in [
            { key: 'adults', label: 'Adults', hint: 'Ages 13+', min: 1, max: 10 },
            { key: 'children', label: 'Children', hint: 'Ages 2–12', min: 0, max: 8 },
            { key: 'pets', label: 'Pets', hint: 'Pet-friendly cottages only', min: 0, max: 3 },
        ]" :key="row.key">
            <div class="flex items-center justify-between py-3">
                <div>
                    <p class="text-sm font-medium text-ink-900" x-text="row.label"></p>
                    <p class="font-mono text-[10px] text-tide-500" x-text="row.hint"></p>
                </div>
                <div class="flex items-center gap-3">
                    <button type="button" @click="decGuest(row.key, row.min)" :disabled="$data[row.key] <= row.min"
                            class="grid h-8 w-8 place-items-center rounded-full border border-fog-300 text-ink-800 transition hover:border-brand-400 disabled:opacity-30">−</button>
                    <span class="w-4 text-center text-sm font-medium" x-text="$data[row.key]"></span>
                    <button type="button" @click="incGuest(row.key, row.max)" :disabled="$data[row.key] >= row.max"
                            class="grid h-8 w-8 place-items-center rounded-full border border-fog-300 text-ink-800 transition hover:border-brand-400 disabled:opacity-30">+</button>
                </div>
            </div>
        </template>

        <button type="button" @click="guestsOpen = false" class="mt-4 w-full rounded-full bg-ink-900 py-2.5 text-sm font-semibold text-white transition hover:bg-ink-800">
            Done
        </button>
    </div>
</div>