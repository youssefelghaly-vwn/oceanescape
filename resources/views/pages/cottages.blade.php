{{-- resources/views/pages/cottages.blade.php --}}
<x-website-layout title="Our Cottages | Ocean Escape Cottages, Lockeport NS">

    {{-- Page header --}}
    <section class="border-b border-fog-200 bg-fog-50 pb-14 pt-32">
        <div class="mx-auto max-w-7xl px-6 lg:px-8">
            <p class="font-mono text-[11px] uppercase tracking-[0.2em] text-brand-600">
                Lockeport, Nova Scotia
            </p>
            <h1 class="mt-3 max-w-3xl font-display text-4xl font-medium leading-[1.1] text-ink-900 sm:text-5xl">
                Our <span class="italic text-brand-700">Cottages</span>
            </h1>
            <p class="mt-5 max-w-2xl text-base leading-relaxed text-tide-700">
                We currently have six unique cottages, all with ocean views and their own personality.
                From cozy couple&rsquo;s retreats to family-sized stays, each one is thoughtfully outfitted
                so you can simply arrive, unpack, and enjoy the beach.
            </p>

            @if ($degraded)
                <div class="mt-6 flex max-w-2xl items-start gap-2.5 rounded-2xl bg-amber-50 px-4 py-3 text-sm text-amber-900 ring-1 ring-amber-200">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="mt-0.5 shrink-0">
                        <circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/>
                    </svg>
                    <span>Live availability is partially unavailable right now, so some openings may be missing. Refresh or contact us to confirm.</span>
                </div>
            @endif

            <div class="mt-8 max-w-3xl">
                <x-booking-search />
            </div>
        </div>
    </section>

    {{-- Listing grid --}}
    <section class="bg-white py-16">
        <div class="mx-auto max-w-7xl px-6 lg:px-8">
            @if ($listings->isEmpty())
                <div class="rounded-3xl bg-fog-50 px-6 py-12 text-center ring-1 ring-black/5">
                    <p class="text-sm text-tide-600">
                        We couldn&rsquo;t load the cottage list just now. Please refresh, or
                        <a href="mailto:info@oceanescapecottages.ca" class="font-medium text-brand-700 underline">email us</a>
                        and we&rsquo;ll help directly.
                    </p>
                </div>
            @else
                <div class="mb-8 flex flex-wrap items-baseline justify-between gap-3">
                    <h2 class="font-display text-2xl font-medium text-ink-900">
                        {{ $listings->count() }} {{ \Illuminate\Support\Str::plural('cottage', $listings->count()) }}
                    </h2>
                    <p class="font-mono text-[11px] uppercase tracking-wide text-tide-500">
                        openings shown for the next {{ config('lodgify.availability_window_days', 90) }} days
                    </p>
                </div>

                <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($listings as $listing)
                        <div data-reveal>
                            <x-cottage-card :cottage="$listing['cottage']" variant="plain">
                                <x-cottage-openings
                                    :cottage="$listing['cottage']"
                                    :windows="$listing['windows']"
                                    :limit="3"
                                />
                            </x-cottage-card>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </section>

    {{-- At a Glance --}}
    <section class="border-y border-fog-200 bg-fog-50 py-20">
        <div class="mx-auto max-w-7xl px-6 lg:px-8">
            <div class="mx-auto max-w-2xl text-center">
                <p class="font-mono text-[11px] uppercase tracking-[0.2em] text-brand-600">⭐ At a Glance</p>
                <h2 class="mt-3 font-display text-3xl font-medium text-ink-900 sm:text-4xl">
                    What every stay includes
                </h2>
            </div>

            <ul class="mx-auto mt-12 grid max-w-5xl gap-4 sm:grid-cols-2">
                @foreach ([
                    ['Six oceanfront cottages', 'Private entrances and outdoor seating at every one.'],
                    ['Direct beach access', 'Crescent Beach and the coastal walking trails are on your doorstep.'],
                    ['Full kitchens', 'Every cottage — ideal for longer stays.'],
                    ['Cozy living areas', 'Smart TVs and fast Wi-Fi throughout.'],
                    ['Family-friendly layouts', 'With room for kids to actually play.'],
                    ['Pet-friendly options', 'Ask us which cottages welcome dogs.'],
                    ['Minutes to town', 'Lockeport shops, cafés, playgrounds and look-offs.'],
                    ['Transparent pricing', 'Your full total before you book — never at checkout.'],
                ] as [$title, $body])
                    <li data-reveal class="flex gap-4 rounded-2xl bg-white p-5 ring-1 ring-black/5">
                        <span class="mt-0.5 grid h-7 w-7 shrink-0 place-items-center rounded-full bg-brand-50 text-brand-600 ring-1 ring-brand-100">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M20 6 9 17l-5-5"/>
                            </svg>
                        </span>
                        <div>
                            <p class="text-sm font-semibold text-ink-900">{{ $title }}</p>
                            <p class="mt-0.5 text-sm leading-relaxed text-tide-600">{{ $body }}</p>
                        </div>
                    </li>
                @endforeach
            </ul>
        </div>
    </section>

    {{-- Experience Lockeport --}}
    <section class="relative overflow-hidden bg-ink-900 py-24 text-white">
        <img src="{{ asset('assets/images/beach.jpg') }}"
             alt="Crescent Beach at sunset"
             loading="lazy"
             onerror="this.style.display='none'"
             class="absolute inset-0 h-full w-full object-cover opacity-30">
        <div class="absolute inset-0 bg-gradient-to-r from-ink-900 via-ink-900/85 to-ink-900/40"></div>

        <div class="relative z-10 mx-auto max-w-7xl px-6 lg:px-8">
            <div class="max-w-2xl">
                <p class="font-mono text-[11px] uppercase tracking-[0.2em] text-brand-300">
                    Experience Lockeport &amp; Crescent Beach
                </p>
                <h2 class="mt-3 font-display text-3xl font-medium leading-tight sm:text-4xl">
                    A kilometre of soft sand, minutes from your door
                </h2>
                <div class="mt-6 space-y-4 text-base leading-relaxed text-white/75">
                    <p>
                        Crescent Beach is one of Nova Scotia&rsquo;s most beautiful sandy beaches, stretching
                        for over a kilometre with soft sand, gentle waves, and incredible sunsets. From your
                        cottage, you can stroll the beach, explore coastal trails, visit the Lockeport Crescent
                        Beach Centre, or drive a few minutes into town for seafood, cafés, playgrounds, and
                        local shops.
                    </p>
                    <p>
                        In every season, Lockeport offers a slower pace, fresh ocean air, and the simple joy of
                        being by the sea.
                    </p>
                </div>
            </div>
        </div>
    </section>

    {{-- CTA --}}
    <section class="bg-white py-20">
        <div class="mx-auto max-w-3xl px-6 text-center">
            <h2 class="font-display text-3xl font-medium text-ink-900 sm:text-4xl">
                Ready when you are
            </h2>
            <p class="mx-auto mt-4 max-w-lg text-base text-tide-600">
                Pick your dates and we&rsquo;ll show every cottage that&rsquo;s free — plus the closest
                alternatives if they&rsquo;re not.
            </p>
            <div class="mt-10">
                <x-booking-search />
            </div>
        </div>
    </section>

</x-website-layout>