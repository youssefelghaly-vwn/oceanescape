{{-- resources/views/pages/reviews.blade.php --}}
<x-website-layout title="Guest Reviews | Ocean Escape Cottages">

@php
    $rating  = $data['rating'] ?? null;
    $total   = $data['total'] ?? null;
    $reviews = $data['reviews'] ?? [];
    $photos  = $data['photos'] ?? [];
@endphp

<div x-data="{
        open: false,
        active: null,
        reviews: @js($reviews),
        show(i, trigger) {
            this.active = this.reviews[i] ?? null;
            if (!this.active) return;
            this.open = true;
            this.returnTo = trigger;
            document.body.style.overflow = 'hidden';
            this.$nextTick(() => this.$refs.dialog?.focus());
        },
        hide() {
            this.open = false;
            document.body.style.overflow = '';
            if (this.returnTo?.focus) this.$nextTick(() => this.returnTo.focus());
        },
        returnTo: null,
     }"
     @keydown.window.escape="open && hide()">

{{-- ------------------------------------------------------------- HEADER --}}
<section class="border-b border-fog-200 bg-fog-50 pb-10 pt-32">
    <div class="mx-auto max-w-6xl px-6 lg:px-8">
        <p class="font-mono text-[11px] uppercase tracking-[0.2em] text-brand-600">Guest reviews</p>
        <h1 class="mt-3 font-display text-3xl font-medium leading-tight text-ink-900 sm:text-4xl">
            What our guests say
        </h1>

        @if ($rating)
            <div class="mt-6 flex flex-wrap items-center gap-x-8 gap-y-4">
                <div>
                    <div class="flex items-baseline gap-2">
                        <span class="font-display text-5xl font-medium text-ink-900">{{ number_format($rating, 1) }}</span>
                        <span class="font-mono text-xs text-tide-500">/ 5</span>
                    </div>
                    <div class="mt-1.5 flex gap-0.5" role="img" aria-label="{{ number_format($rating, 1) }} out of 5 stars">
                        @for ($i = 1; $i <= 5; $i++)
                            <svg width="18" height="18" viewBox="0 0 24 24"
                                 class="{{ $i <= round($rating) ? 'text-amber-400' : 'text-fog-300' }}"
                                 fill="currentColor" aria-hidden="true">
                                <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/>
                            </svg>
                        @endfor
                    </div>
                </div>

                @if ($total)
                    <div class="border-l border-fog-300 pl-8">
                        <p class="font-display text-3xl font-medium text-ink-900">{{ number_format($total) }}</p>
                        <p class="mt-1 font-mono text-[10px] uppercase tracking-wide text-tide-500">
                            Google {{ Str::plural('review', $total) }}
                        </p>
                    </div>
                @endif

                @if (!empty($data['url']))
                    <a href="{{ $data['url'] }}" target="_blank" rel="noopener nofollow"
                       class="inline-flex items-center gap-2 rounded-full bg-white px-5 py-3 text-sm font-medium text-brand-700 ring-1 ring-fog-300 transition hover:ring-brand-300">
                        Read all {{ $total ? number_format($total) : '' }} on Google
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6M15 3h6v6M10 14 21 3"/>
                        </svg>
                    </a>
                @endif
            </div>
        @endif
    </div>
</section>

{{-- ------------------------------------------------------------ REVIEWS --}}
<section class="bg-white py-12">
    <div class="mx-auto max-w-6xl px-6 lg:px-8">

        @if (!($data['configured'] ?? false))
            <div class="rounded-3xl bg-fog-50 px-6 py-14 text-center ring-1 ring-black/5">
                <h2 class="font-display text-xl text-ink-900">Reviews aren&rsquo;t connected yet</h2>
                <p class="mx-auto mt-2 max-w-md text-sm leading-relaxed text-tide-600">
                    {{ $data['error'] ?? 'Add your Google API credentials to pull live reviews.' }}
                </p>
            </div>

        @elseif (!empty($data['error']))
            <div class="rounded-3xl bg-fog-50 px-6 py-14 text-center ring-1 ring-black/5">
                <p class="text-sm text-tide-600">{{ $data['error'] }}</p>
                @if (!empty($data['url']))
                    <a href="{{ $data['url'] }}" target="_blank" rel="noopener nofollow"
                       class="mt-4 inline-block text-sm font-medium text-brand-700 hover:underline">
                        Read our reviews on Google instead
                    </a>
                @endif
            </div>

        @elseif (empty($reviews))
            <div class="rounded-3xl bg-fog-50 px-6 py-14 text-center ring-1 ring-black/5">
                <p class="font-display text-lg text-ink-900">No written reviews yet</p>
            </div>

        @else
            <div class="grid gap-5 md:grid-cols-2 lg:grid-cols-3">
                @foreach ($reviews as $i => $review)
                    <figure class="flex flex-col rounded-3xl bg-fog-50 p-6 ring-1 ring-black/5">

                        <div class="flex gap-0.5" role="img" aria-label="{{ $review['rating'] }} out of 5">
                            @for ($s = 1; $s <= 5; $s++)
                                <svg width="14" height="14" viewBox="0 0 24 24"
                                     class="{{ $s <= $review['rating'] ? 'text-amber-400' : 'text-fog-300' }}"
                                     fill="currentColor" aria-hidden="true">
                                    <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/>
                                </svg>
                            @endfor
                        </div>

                        {{-- Excerpt only. Full text lives in the modal, so cards
                             stay a uniform, scannable height. --}}
                        <blockquote class="mt-4 whitespace-pre-line text-sm leading-relaxed text-tide-700">
                            {{ $review['excerpt'] }}
                        </blockquote>

                        @if ($review['truncated'])
                            <button type="button" @click="show({{ $i }}, $event.currentTarget)"
                                    class="mt-3 inline-flex items-center gap-1.5 self-start text-sm font-semibold text-brand-700 transition hover:gap-2.5">
                                Read full review
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                                    <path d="M5 12h14M13 6l6 6-6 6"/>
                                </svg>
                            </button>
                        @endif

                        <figcaption class="mt-auto flex items-center gap-3 border-t border-fog-200 pt-4 {{ $review['truncated'] ? 'mt-5' : 'mt-5' }}">
                            @if ($review['photo'])
                                <img src="{{ $review['photo'] }}" alt="" loading="lazy"
                                     referrerpolicy="no-referrer"
                                     class="h-9 w-9 shrink-0 rounded-full object-cover ring-1 ring-black/5">
                            @else
                                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-brand-100 font-display text-sm text-brand-700">
                                    {{ Str::substr($review['author'], 0, 1) }}
                                </span>
                            @endif
                            <span class="min-w-0">
                                <span class="block truncate text-sm font-medium text-ink-900">{{ $review['author'] }}</span>
                                @if ($review['relative'])
                                    <span class="block font-mono text-[10px] uppercase tracking-wide text-tide-500">
                                        {{ $review['relative'] }}
                                    </span>
                                @endif
                            </span>
                        </figcaption>
                    </figure>
                @endforeach
            </div>

            {{-- Say plainly that this is a sample. Showing five as though they
                 were all 43 would read as cherry-picking. --}}
            <div class="mt-10 rounded-3xl bg-fog-50 px-6 py-8 text-center ring-1 ring-black/5">
                <p class="mx-auto max-w-lg text-sm leading-relaxed text-tide-700">
                    Google publishes a selection of reviews through its API &mdash;
                    these {{ count($reviews) }} are what it shares.
                    @if ($total)
                        All {{ number_format($total) }} are on our Google listing.
                    @endif
                </p>
                @if (!empty($data['url']))
                    <a href="{{ $data['url'] }}" target="_blank" rel="noopener nofollow"
                       class="mt-5 inline-flex items-center gap-2 rounded-full bg-brand-600 px-6 py-3 text-sm font-semibold text-white transition hover:bg-brand-700">
                        Read every review on Google
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6M15 3h6v6M10 14 21 3"/>
                        </svg>
                    </a>
                @endif
            </div>
        @endif
    </div>
</section>

{{-- ------------------------------------------------- PHOTOS FROM GOOGLE --}}
@if (!empty($photos))
    <section class="border-t border-fog-200 bg-fog-50 py-14">
        <div class="mx-auto max-w-6xl px-6 lg:px-8">
            <h2 class="font-display text-2xl font-medium text-ink-900">Photos from our Google listing</h2>
            <p class="mt-2 max-w-xl text-sm text-tide-600">
                Contributed by guests and by us. Reviews and photos are separate on Google,
                so these aren&rsquo;t tied to the reviews above.
            </p>

            <div class="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                @foreach ($photos as $photo)
                    <a href="{{ $photo['url'] }}" target="_blank" rel="noopener"
                       class="group relative block aspect-square overflow-hidden rounded-2xl bg-fog-200 ring-1 ring-black/5">
                        <img src="{{ $photo['url'] }}" alt="Ocean Escape Cottages" loading="lazy"
                             referrerpolicy="no-referrer"
                             class="h-full w-full object-cover transition duration-500 group-hover:scale-105">
                        @if ($photo['author'])
                            <span class="absolute inset-x-0 bottom-0 truncate bg-gradient-to-t from-ink-900/80 to-transparent px-3 pb-2 pt-6 font-mono text-[9px] uppercase tracking-wide text-white/80">
                                {{ $photo['author'] }}
                            </span>
                        @endif
                    </a>
                @endforeach
            </div>
        </div>
    </section>
@endif

{{-- --------------------------------------------------------- FULL REVIEW --}}
<template x-teleport="body">
    <div x-show="open" x-cloak
         class="fixed inset-0 z-[100] flex items-end justify-center sm:items-center"
         role="dialog" aria-modal="true" aria-label="Full review">

        <div x-show="open"
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
             x-transition:leave="transition ease-in duration-150"
             x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
             class="absolute inset-0 bg-ink-900/60 backdrop-blur-sm" @click="hide()"></div>

        <div x-show="open"
             x-transition:enter="transition ease-out duration-250"
             x-transition:enter-start="opacity-0 translate-y-8 sm:translate-y-4 sm:scale-95"
             x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
             x-transition:leave="transition ease-in duration-150"
             x-transition:leave-start="opacity-100 sm:scale-100"
             x-transition:leave-end="opacity-0 translate-y-6 sm:scale-95"
             x-ref="dialog" tabindex="-1"
             class="relative flex max-h-[88vh] w-full flex-col overflow-hidden rounded-t-3xl bg-white shadow-2xl sm:max-w-2xl sm:rounded-3xl">

            <template x-if="active">
                <div class="flex min-h-0 flex-col">
                    {{-- header --}}
                    <div class="flex items-start justify-between gap-4 border-b border-fog-200 px-6 py-5">
                        <div class="flex min-w-0 items-center gap-3">
                            <template x-if="active.photo">
                                <img :src="active.photo" alt="" referrerpolicy="no-referrer"
                                     class="h-11 w-11 shrink-0 rounded-full object-cover ring-1 ring-black/5">
                            </template>
                            <template x-if="!active.photo">
                                <span class="grid h-11 w-11 shrink-0 place-items-center rounded-full bg-brand-100 font-display text-base text-brand-700"
                                      x-text="active.author?.charAt(0)"></span>
                            </template>
                            <div class="min-w-0">
                                <p class="truncate font-display text-lg text-ink-900" x-text="active.author"></p>
                                <div class="mt-0.5 flex items-center gap-2">
                                    <span class="flex gap-0.5">
                                        <template x-for="s in 5" :key="s">
                                            <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor"
                                                 :class="s <= active.rating ? 'text-amber-400' : 'text-fog-300'">
                                                <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/>
                                            </svg>
                                        </template>
                                    </span>
                                    <span class="font-mono text-[10px] uppercase tracking-wide text-tide-500"
                                          x-text="active.relative"></span>
                                </div>
                            </div>
                        </div>

                        <button type="button" @click="hide()" aria-label="Close"
                                class="grid h-9 w-9 shrink-0 place-items-center rounded-full text-tide-400 transition hover:bg-fog-100 hover:text-ink-900">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M18 6 6 18M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>

                    {{-- body --}}
                    <div class="min-h-0 flex-1 overflow-y-auto px-6 py-6">
                        <blockquote class="whitespace-pre-line text-[15px] leading-relaxed text-tide-700"
                                    x-text="active.text"></blockquote>
                    </div>

                    {{-- footer --}}
                    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-fog-200 bg-fog-50 px-6 py-4">
                        <a :href="active.url" target="_blank" rel="noopener nofollow"
                           x-show="active.url"
                           class="inline-flex items-center gap-1.5 text-sm font-medium text-brand-700 hover:underline">
                            View on Google
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6M15 3h6v6M10 14 21 3"/>
                            </svg>
                        </a>
                        <button type="button" @click="hide()"
                                class="rounded-full bg-brand-600 px-6 py-2.5 text-sm font-semibold text-white transition hover:bg-brand-700">
                            Close
                        </button>
                    </div>
                </div>
            </template>
        </div>
    </div>
</template>

</div>
</x-website-layout>