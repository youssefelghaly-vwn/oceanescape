{{-- resources/views/pages/home.blade.php --}}
<x-website-layout
    title="Ocean Escape Cottages | Oceanfront Cottage Rentals in Nova Scotia"
    :transparent-nav="true"
>
    {{-- Hero — shorter than a full viewport; the search widget is the payoff, not scroll depth --}}
    <section class="relative flex min-h-[68vh] items-center justify-center overflow-hidden bg-ink-900 pb-24 pt-40">
        <img
            src="{{ asset('assets/images/hero.png') }}"
            alt="Oceanfront cottage on the Nova Scotia coast at dusk"
            class="absolute inset-0 h-full w-full object-cover opacity-60"
        >
        <div class="absolute inset-0 bg-gradient-to-t from-ink-900 via-ink-900/50 to-ink-900/10"></div>

        <div class="relative z-10 mx-auto max-w-2xl px-6 text-center text-white">
            <p data-reveal class="mb-4 font-mono text-xs tracking-[0.25em] text-brand-200">
                SIX COTTAGES · ATLANTIC COAST
            </p>
            <h1 data-reveal class="font-display text-4xl font-medium leading-[1.1] sm:text-5xl lg:text-6xl">
                Wake up to the <span class="italic text-brand-300">Atlantic</span>
            </h1>
            <p data-reveal class="mx-auto mt-5 max-w-lg text-base text-white/75 sm:text-lg">
                Real-time availability and transparent pricing — see the total
                before you book, not at checkout.
            </p>
        </div>
    </section>

    {{-- Search widget — floats over the hero/content seam --}}
    <div class="relative z-20 mx-auto -mt-12 max-w-3xl px-6">
        <x-booking-search />
    </div>

    {{-- Facts strip — real, not fabricated trust signals --}}
    <section class="border-b border-fog-200 bg-white pt-20">
        <div class="mx-auto grid max-w-7xl grid-cols-2 gap-8 px-6 py-10 sm:grid-cols-4 lg:px-8">
            @foreach ([['6', 'Oceanfront Cottages'], ['90-Day', 'Live Availability Window'], ['0', 'Hidden Fees at Checkout'], ['1', 'Stretch of Nova Scotia Coast']] as [$stat, $label])
                <div data-reveal class="text-center">
                    <p class="font-display text-3xl font-medium text-brand-700">{{ $stat }}</p>
                    <p class="mt-1 font-mono text-[11px] uppercase tracking-wide text-tide-500">{{ $label }}</p>
                </div>
            @endforeach
        </div>
    </section>

    {{-- Intro — the coastal escape pitch, paired with an interior shot --}}
    <section class="bg-white py-20 lg:py-24">
        <div class="mx-auto grid max-w-7xl items-center gap-12 px-6 lg:grid-cols-2 lg:gap-16 lg:px-8">
            <div data-reveal>
                <p class="font-mono text-[11px] uppercase tracking-[0.2em] text-brand-600">
                    Lockeport, Nova Scotia
                </p>
                <h2 class="mt-3 font-display text-3xl font-medium leading-tight text-ink-900 sm:text-4xl">
                    Oceanfront Cottages in Beautiful <span class="italic text-brand-700">Lockeport</span>
                </h2>
                <div class="mt-6 space-y-4 text-base leading-relaxed text-tide-700">
                    <p>
                        Welcome to your coastal escape. Our oceanfront cottages offer bright, comfortable
                        interiors, fully equipped kitchens, private outdoor spaces, and direct access to some
                        of Nova Scotia&rsquo;s most stunning beaches. Whether you&rsquo;re here for a quiet
                        getaway, a family vacation, or a weekend by the sea, our cottages give you the perfect
                        mix of comfort, charm, and relaxation.
                    </p>
                    <p>
                        Discover sweeping ocean views, picturesque coastal trails, and local attractions all
                        just minutes away. Your seaside retreat starts here.
                    </p>
                </div>
                <a href="{{ route('cottages.index') }}"
                   class="mt-8 inline-flex items-center gap-2 rounded-full bg-brand-600 px-6 py-3 text-sm font-semibold text-white transition hover:gap-3 hover:bg-brand-700">
                    Browse all six cottages
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M5 12h14M13 6l6 6-6 6"/>
                    </svg>
                </a>
            </div>

            <div data-reveal class="relative">
                <div class="aspect-[4/3] overflow-hidden rounded-3xl bg-fog-100 shadow-xl shadow-ink-900/10">
                    <img src="{{ asset('assets/images/interior.jpg') }}"
                         alt="Bright cottage living room with ocean view"
                         loading="lazy"
                         onerror="this.style.display='none'"
                         class="h-full w-full object-cover">
                </div>
                <div class="absolute -bottom-6 -left-6 hidden rounded-2xl bg-white p-5 shadow-xl shadow-ink-900/10 ring-1 ring-black/5 sm:block">
                    <p class="font-mono text-[10px] uppercase tracking-wide text-tide-500">Steps away</p>
                    <p class="mt-1 font-display text-xl text-ink-900">Crescent Beach</p>
                </div>
            </div>
        </div>
    </section>

    {{-- At a Glance --}}
    <section class="border-y border-fog-200 bg-fog-50 py-20">
        <div class="mx-auto max-w-7xl px-6 lg:px-8">
            <div data-reveal class="mx-auto max-w-2xl text-center">
                <p class="font-mono text-[11px] uppercase tracking-[0.2em] text-brand-600">
                    ⭐ At a Glance
                </p>
                <h2 class="mt-3 font-display text-3xl font-medium text-ink-900 sm:text-4xl">
                    Ocean Escape Cottages
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

    {{-- Our Cottages --}}
    <section id="cottages" class="bg-white py-20 lg:py-24">
        <div class="mx-auto max-w-7xl px-6 lg:px-8">
            <div data-reveal class="mb-12 max-w-2xl">
                <p class="font-mono text-[11px] uppercase tracking-[0.2em] text-brand-600">Our Cottages</p>
                <h2 class="mt-3 font-display text-3xl font-medium text-ink-900 sm:text-4xl">
                    Six cottages, each with its own personality
                </h2>
                <p class="mt-5 text-base leading-relaxed text-tide-700">
                    All with ocean views. From cozy couple&rsquo;s retreats to family-sized stays, each one is
                    thoughtfully outfitted so you can simply arrive, unpack, and enjoy the beach.
                </p>
            </div>

            @if ($listings->isEmpty())
                <div class="rounded-3xl bg-fog-50 px-6 py-10 text-center ring-1 ring-black/5">
                    <p class="text-sm text-tide-600">
                        Cottage listings are loading. Please refresh in a moment, or
                        <a href="mailto:info@oceanescapecottages.ca" class="font-medium text-brand-700 underline">email us</a>.
                    </p>
                </div>
            @else
                <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($listings as $listing)
                        <div data-reveal>
                            <x-cottage-card :cottage="$listing['cottage']" variant="plain">
                                <x-cottage-openings
                                    :cottage="$listing['cottage']"
                                    :windows="$listing['windows']"
                                    :limit="2"
                                />
                            </x-cottage-card>
                        </div>
                    @endforeach
                </div>

                <div class="mt-12 text-center">
                    <a href="{{ route('cottages.index') }}"
                       class="inline-flex items-center gap-2 rounded-full px-6 py-3 text-sm font-semibold text-brand-700 ring-1 ring-brand-200 transition hover:gap-3 hover:bg-brand-50">
                        See full details &amp; availability
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M5 12h14M13 6l6 6-6 6"/>
                        </svg>
                    </a>
                </div>
            @endif
        </div>
    </section>

    {{-- Experience Lockeport & Crescent Beach --}}
    <section class="relative overflow-hidden bg-ink-900 py-24 text-white">
        <img src="{{ asset('assets/images/beach.jpg') }}"
             alt="Crescent Beach at sunset"
             loading="lazy"
             onerror="this.style.display='none'"
             class="absolute inset-0 h-full w-full object-cover opacity-30">
        <div class="absolute inset-0 bg-gradient-to-r from-ink-900 via-ink-900/85 to-ink-900/40"></div>

        <div class="relative z-10 mx-auto max-w-7xl px-6 lg:px-8">
            <div data-reveal class="max-w-2xl">
                <p class="font-mono text-[11px] uppercase tracking-[0.2em] text-brand-300">
                    Experience Lockeport
                </p>
                <h2 class="mt-3 font-display text-3xl font-medium leading-tight sm:text-4xl">
                    Crescent Beach &amp; a slower pace
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

                <div class="mt-10 grid gap-6 sm:grid-cols-3">
                    @foreach ([
                        ['1 km+', 'of soft sand'],
                        ['5 min', 'to town'],
                        ['All year', 'ocean air'],
                    ] as [$stat, $label])
                        <div>
                            <p class="font-display text-2xl font-medium text-brand-300">{{ $stat }}</p>
                            <p class="mt-1 font-mono text-[10px] uppercase tracking-wide text-white/50">{{ $label }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </section>

    {{-- Closing CTA --}}
    <section class="bg-fog-50 py-20">
        <div class="mx-auto max-w-3xl px-6 text-center">
            <h2 data-reveal class="font-display text-3xl font-medium text-ink-900 sm:text-4xl">
                Find your dates
            </h2>
            <p data-reveal class="mx-auto mt-4 max-w-lg text-base text-tide-600">
                Live availability across all six cottages. If your dates are taken, we&rsquo;ll show you the
                closest openings.
            </p>
            <div data-reveal class="mt-10">
                <x-booking-search />
            </div>
        </div>
    </section>

</x-website-layout>