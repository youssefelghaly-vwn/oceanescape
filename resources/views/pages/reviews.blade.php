{{-- resources/views/pages/reviews.blade.php --}}
<x-website-layout title="Guest Reviews | Ocean Escape Cottages">

    @php
        $rating  = $data['rating'] ?? null;
        $total   = $data['total'] ?? null;
        $reviews = $data['reviews'] ?? [];
    @endphp

    {{-- ---------------------------------------------------------- HEADER --}}
    <section class="border-b border-fog-200 bg-fog-50 pb-10 pt-32">
        <div class="mx-auto max-w-5xl px-6 lg:px-8">
            <p class="font-mono text-[11px] uppercase tracking-[0.2em] text-brand-600">Guest reviews</p>
            <h1 class="mt-3 font-display text-3xl font-medium leading-tight text-ink-900 sm:text-4xl">
                What our guests say
            </h1>

            @if ($rating)
                <div class="mt-6 flex flex-wrap items-center gap-x-6 gap-y-3">
                    <div class="flex items-baseline gap-2">
                        <span class="font-display text-4xl font-medium text-ink-900">
                            {{ number_format($rating, 1) }}
                        </span>
                        <span class="font-mono text-xs text-tide-500">/ 5</span>
                    </div>

                    <div>
                        <div class="flex gap-0.5" role="img" aria-label="{{ number_format($rating, 1) }} out of 5 stars">
                            @for ($i = 1; $i <= 5; $i++)
                                <svg width="18" height="18" viewBox="0 0 24 24"
                                     class="{{ $i <= round($rating) ? 'text-amber-400' : 'text-fog-300' }}"
                                     fill="currentColor" aria-hidden="true">
                                    <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/>
                                </svg>
                            @endfor
                        </div>
                        @if ($total)
                            <p class="mt-1 font-mono text-[10px] uppercase tracking-wide text-tide-500">
                                {{ number_format($total) }} Google {{ Str::plural('review', $total) }}
                            </p>
                        @endif
                    </div>

                    @if (!empty($data['url']))
                        <a href="{{ $data['url'] }}" target="_blank" rel="noopener nofollow"
                           class="inline-flex items-center gap-1.5 rounded-full bg-white px-4 py-2 text-sm font-medium text-brand-700 ring-1 ring-fog-300 transition hover:ring-brand-300">
                            Read all on Google
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6M15 3h6v6M10 14 21 3"/>
                            </svg>
                        </a>
                    @endif
                </div>
            @endif
        </div>
    </section>

    {{-- --------------------------------------------------------- REVIEWS --}}
    <section class="bg-white py-12">
        <div class="mx-auto max-w-5xl px-6 lg:px-8">

            @if (!($data['configured'] ?? false))
                {{-- Setup state: shown only until the API key is in place. --}}
                <div class="rounded-3xl bg-fog-50 px-6 py-14 text-center ring-1 ring-black/5">
                    <div class="mx-auto grid h-12 w-12 place-items-center rounded-full bg-white text-tide-400 ring-1 ring-fog-200">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
                            <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/>
                        </svg>
                    </div>
                    <h2 class="mt-4 font-display text-xl text-ink-900">Reviews aren&rsquo;t connected yet</h2>
                    <p class="mx-auto mt-2 max-w-md text-sm leading-relaxed text-tide-600">
                        Add <span class="font-mono text-xs">GOOGLE_MAPS_API_KEY</span> and
                        <span class="font-mono text-xs">GOOGLE_PLACE_ID</span> to your environment
                        to pull live reviews from Google.
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
                    <p class="mt-1 text-sm text-tide-600">Stayed with us? A few words on Google would mean a lot.</p>
                </div>

            @else
                {{-- CSS columns keep long and short reviews from leaving gaps --}}
                <div class="columns-1 gap-5 md:columns-2 lg:columns-3 [&>*]:mb-5">
                    @foreach ($reviews as $review)
                        <figure class="break-inside-avoid rounded-3xl bg-fog-50 p-6 ring-1 ring-black/5">
                            <div class="flex gap-0.5" role="img" aria-label="{{ $review['rating'] }} out of 5">
                                @for ($i = 1; $i <= 5; $i++)
                                    <svg width="14" height="14" viewBox="0 0 24 24"
                                         class="{{ $i <= $review['rating'] ? 'text-amber-400' : 'text-fog-300' }}"
                                         fill="currentColor" aria-hidden="true">
                                        <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/>
                                    </svg>
                                @endfor
                            </div>

                            <blockquote class="mt-4 text-sm leading-relaxed text-tide-700">
                                {{ $review['text'] }}
                            </blockquote>

                            <figcaption class="mt-5 flex items-center gap-3 border-t border-fog-200 pt-4">
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

                {{-- Being upfront about the cap is better than implying these
                     five are everything. --}}
                <div class="mt-10 rounded-3xl bg-fog-50 px-6 py-8 text-center ring-1 ring-black/5">
                    <p class="text-sm text-tide-700">
                        Google shares a selection of reviews publicly.
                        @if ($total)
                            All {{ number_format($total) }} are on our Google listing.
                        @endif
                    </p>
                    @if (!empty($data['url']))
                        <a href="{{ $data['url'] }}" target="_blank" rel="noopener nofollow"
                           class="mt-4 inline-flex items-center gap-2 rounded-full bg-brand-600 px-6 py-3 text-sm font-semibold text-white transition hover:bg-brand-700">
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
</x-website-layout>
