{{-- resources/views/pages/gallery.blade.php --}}
<x-website-layout title="Guest Gallery | Ocean Escape Cottages">

    <section class="border-b border-fog-200 bg-fog-50 pb-8 pt-32">
        <div class="mx-auto max-w-7xl px-6 lg:px-8">
            <p class="font-mono text-[11px] uppercase tracking-[0.2em] text-brand-600">Guest gallery</p>
            <h1 class="mt-3 font-display text-3xl font-medium leading-tight text-ink-900 sm:text-4xl">
                Through our guests&rsquo; eyes
            </h1>
<div class="mt-6 flex flex-col items-start gap-4 sm:flex-row sm:items-center sm:justify-between">
    <p class="max-w-xl text-base leading-relaxed text-tide-700">
        Photographs sent in by people who&rsquo;ve stayed with us. Been before?
    </p>
    <a href="{{ route('photos.create') }}"
       class="inline-flex shrink-0 items-center gap-2 rounded-full bg-brand-600 px-6 py-3 text-sm font-semibold text-white transition hover:bg-brand-700">
        Add your photos
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M5 12h14M12 5l7 7-7 7"/>
        </svg>
    </a>
</div>

            @if ($cottages->isNotEmpty())
                <div class="mt-6 flex flex-wrap gap-2">
                    <a href="{{ route('gallery') }}"
                       class="rounded-full px-4 py-2 font-mono text-[10px] uppercase tracking-wide transition
                              {{ !$active ? 'bg-ink-900 text-white' : 'bg-white text-tide-600 ring-1 ring-fog-300 hover:ring-brand-300' }}">
                        All
                    </a>
                    @foreach ($cottages as $c)
                        <a href="{{ route('gallery', ['cottage' => $c->cottage_id]) }}"
                           class="rounded-full px-4 py-2 font-mono text-[10px] uppercase tracking-wide transition
                                  {{ $active === (int) $c->cottage_id ? 'bg-ink-900 text-white' : 'bg-white text-tide-600 ring-1 ring-fog-300 hover:ring-brand-300' }}">
                            {{ Str::limit($c->cottage_name, 26) }}
                            <span class="opacity-60">{{ $c->total }}</span>
                        </a>
                    @endforeach
                </div>
            @endif
        </div>
    </section>

    <section class="bg-white py-12"
             x-data="imageLightbox({ images: @js($photos->map(fn ($p) => [
                 'thumb' => $p->url,
                 'full'  => $p->url,
                 'alt'   => $p->caption ?: ('Photo by ' . $p->credit),
             ])->values()) })"
             @keydown.window="onKey($event)">
        <div class="mx-auto max-w-7xl px-6 lg:px-8">

            @if ($photos->isEmpty())
                <div class="rounded-3xl bg-fog-50 px-6 py-20 text-center ring-1 ring-black/5">
                    <div class="mx-auto grid h-14 w-14 place-items-center rounded-full bg-white text-tide-400 ring-1 ring-fog-200">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                            <rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/>
                        </svg>
                    </div>
                    <h2 class="mt-5 font-display text-xl text-ink-900">No photos here yet</h2>
                    <p class="mx-auto mt-2 max-w-sm text-sm text-tide-600">
                        Be the first — we&rsquo;d love to see the coast through your lens.
                    </p>
                    <a href="{{ route('photos.create') }}"
                       class="mt-6 inline-block rounded-full bg-brand-600 px-6 py-3 text-sm font-semibold text-white transition hover:bg-brand-700">
                        Share your photos
                    </a>
                </div>
            @else
                {{-- masonry via CSS columns: photos keep their own aspect ratio
                     rather than being cropped into a uniform grid --}}
                <div class="columns-2 gap-4 sm:columns-3 lg:columns-4 [&>*]:mb-4">
                    @foreach ($photos as $i => $photo)
                        <button type="button" @click="show({{ $i }}, $event.currentTarget)"
                                class="group relative block w-full overflow-hidden rounded-2xl bg-fog-100 ring-1 ring-black/5">
                            <img src="{{ $photo->url }}"
                                 alt="{{ $photo->caption ?: 'Photo by ' . $photo->credit }}"
                                 loading="lazy"
                                 class="w-full transition duration-500 group-hover:scale-[1.03]">

                            <span class="pointer-events-none absolute inset-x-0 bottom-0 bg-gradient-to-t from-ink-900/80 to-transparent p-3 pt-8 text-left opacity-0 transition group-hover:opacity-100">
                                @if ($photo->caption)
                                    <span class="block text-xs leading-snug text-white">{{ $photo->caption }}</span>
                                @endif
                                <span class="mt-0.5 block font-mono text-[9px] uppercase tracking-wide text-white/70">
                                    {{ $photo->credit }}@if ($photo->cottage_name) · {{ Str::limit($photo->cottage_name, 24) }}@endif
                                </span>
                            </span>

                            @if ($photo->is_featured)
                                <span class="absolute left-3 top-3 rounded-full bg-buoy-500 px-2.5 py-1 font-mono text-[9px] font-semibold uppercase tracking-wide text-white">
                                    Featured
                                </span>
                            @endif
                        </button>
                    @endforeach
                </div>

                <div class="mt-10">{{ $photos->links() }}</div>
            @endif
        </div>

        {{-- reuses the same lightbox as the cottage gallery --}}
        <template x-teleport="body">
            <div x-show="open" x-cloak
                 class="fixed inset-0 z-[100] flex flex-col bg-ink-900/97 backdrop-blur-sm"
                 x-transition:enter="transition ease-out duration-200"
                 x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                 x-transition:leave="transition ease-in duration-150"
                 x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
                 role="dialog" aria-modal="true" aria-label="Guest photo gallery"
                 x-ref="dialog" tabindex="-1" @click.self="hide()">

                <div class="flex items-center justify-between px-5 py-4 sm:px-8">
                    <p class="font-mono text-[11px] uppercase tracking-wider text-white/60">
                        <span x-text="index + 1"></span> / <span x-text="count"></span>
                    </p>
                    <button type="button" @click="hide()" aria-label="Close"
                            class="grid h-10 w-10 place-items-center rounded-full text-white/70 transition hover:bg-white/10 hover:text-white">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18M6 6l12 12"/></svg>
                    </button>
                </div>

                <div class="relative flex min-h-0 flex-1 items-center justify-center px-4 sm:px-16"
                     @touchstart.passive="onTouchStart($event)" @touchend.passive="onTouchEnd($event)">
                    <button type="button" @click="prev()" x-show="hasMultiple" aria-label="Previous"
                            class="absolute left-2 z-10 grid h-11 w-11 place-items-center rounded-full bg-white/10 text-white backdrop-blur transition hover:bg-white/20 sm:left-5">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 18l-6-6 6-6"/></svg>
                    </button>

                    <template x-if="current">
                        <img :src="current.full" :alt="current.alt" @load="loading = false"
                             class="max-h-full max-w-full rounded-xl object-contain shadow-2xl">
                    </template>

                    <button type="button" @click="next()" x-show="hasMultiple" aria-label="Next"
                            class="absolute right-2 z-10 grid h-11 w-11 place-items-center rounded-full bg-white/10 text-white backdrop-blur transition hover:bg-white/20 sm:right-5">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 6l6 6-6 6"/></svg>
                    </button>
                </div>

                <div class="px-5 py-5 text-center sm:px-8">
                    <p class="mx-auto max-w-2xl text-sm text-white/70" x-text="current?.alt"></p>
                </div>
            </div>
        </template>
    </section>
</x-website-layout>
