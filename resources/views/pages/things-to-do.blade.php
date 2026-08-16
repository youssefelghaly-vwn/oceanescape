{{-- resources/views/pages/things-to-do.blade.php --}}
<x-website-layout
    title="Things to Do Around Ocean Escape Cottages | Lockeport, Nova Scotia"
    :transparent-nav="true"
>
    @php
        /*
         * Kept inline rather than in the database: this is editorial copy that
         * changes a couple of times a year, and a CMS table for six items would
         * be more machinery than the content justifies.
         */
        $activities = [
            [
                'number' => '01',
                'kind'   => 'Beach',
                'title'  => 'Crescent Beach',
                'body'   => 'A peaceful coastal setting with expansive views and a relaxed pace. Perfect for long walks, fresh ocean air, and moments of quiet reflection.',
                'tags'   => ['Walking', 'Swimming', 'Scenic Views'],
                'image'  => 'assets/images/things/crescent-beach.jpg',
            ],
            [
                'number' => '02',
                'kind'   => 'Trail',
                'title'  => 'Lockeport Walking Trail',
                'body'   => 'A 4 km trail passing through salt marshes, sand dunes, old trestle bridges, and coastal views. Ideal for walking, birdwatching, light hiking, or cycling.',
                'tags'   => ['4 km Trail', 'Birdwatching', 'Cycling'],
                'image'  => 'assets/images/things/walking-trail.jpg',
            ],
            [
                'number' => '03',
                'kind'   => 'Heritage',
                'title'  => 'Historic Town Walk',
                'body'   => 'Walk among five well-preserved historic homes from the 1800s — Colonial, Georgian, and Victorian architecture. A glimpse into local heritage and the charm of old Nova Scotia.',
                'tags'   => ['Self-guided', 'Architecture', 'History'],
                'image'  => 'assets/images/things/historic-walk.jpg',
            ],
            [
                'number' => '04',
                'kind'   => 'Golf',
                'title'  => 'River Hills Golf & Country Club',
                'body'   => 'A picturesque 18-hole course just a short drive from the cottages. Perfect for both experienced golfers and casual players looking to spend time outdoors.',
                'tags'   => ['18 Holes', 'All Levels', 'Short Drive'],
                'image'  => 'assets/images/things/golf.jpg',
            ],
            [
                'number' => '05',
                'kind'   => 'Scenic',
                'title'  => 'Explore Lighthouse Views',
                'body'   => 'Follow the coastline beyond town to discover dramatic scenery and iconic lighthouses. Quiet roads and open viewpoints make this ideal for scenic drives, photo stops, and rugged coastal beauty.',
                'tags'   => ['Scenic Drive', 'Photography', 'Lighthouses'],
                'image'  => 'assets/images/things/lighthouse.jpg',
            ],
            [
                'number' => '06',
                'kind'   => 'Family',
                'title'  => 'The Seacaps Playpark',
                'body'   => 'Newly built and just across the street — less than two minutes from your cottage. Designed with families in mind, children play freely while parents savour unhurried coastal moments.',
                'tags'   => ['2 min walk', 'Family Friendly', 'New 2025'],
                'image'  => 'assets/images/things/playpark.jpg',
            ],
        ];
    @endphp

    {{-- ------------------------------------------------------------ HERO --}}
    <section class="relative flex min-h-[52vh] items-end overflow-hidden bg-ink-900 pb-16 pt-40">
        <img src="{{ asset('assets/images/hero.png') }}"
             alt="The Nova Scotia coastline near Lockeport"
             onerror="this.style.display='none'"
             class="absolute inset-0 h-full w-full object-cover opacity-45">
        <div class="absolute inset-0 bg-gradient-to-t from-ink-900 via-ink-900/70 to-ink-900/20"></div>

        <div class="relative z-10 mx-auto w-full max-w-6xl px-6 lg:px-8">
            <p data-reveal class="font-mono text-xs tracking-[0.25em] text-brand-200">
                LOCKEPORT, NOVA SCOTIA
            </p>
            <h1 data-reveal class="mt-4 max-w-3xl font-display text-4xl font-medium leading-[1.1] text-white sm:text-5xl lg:text-6xl">
                Things to Do Around
                <span class="italic text-brand-300">Ocean Escape Cottages</span>
            </h1>
            <p data-reveal class="mt-5 max-w-xl text-base leading-relaxed text-white/75 sm:text-lg">
                Whether you&rsquo;re looking to relax by the sea, explore the natural beauty of Nova
                Scotia, or enjoy local attractions &mdash; Lockeport has something for everyone.
            </p>
        </div>
    </section>

    {{-- ------------------------------------------------------ ACTIVITIES --}}
    <section class="bg-white">
        <div class="mx-auto max-w-6xl px-6 lg:px-8">
            @foreach ($activities as $i => $activity)
                <article data-reveal
                         class="grid items-center gap-8 border-b border-fog-200 py-14 lg:grid-cols-2 lg:gap-14 lg:py-20">

                    {{-- image; alternates side on desktop so the eye zig-zags
                         down the page rather than running in one column --}}
                    <div class="{{ $i % 2 === 1 ? 'lg:order-2' : '' }}">
                        <div class="relative aspect-[4/3] overflow-hidden rounded-3xl bg-fog-100">
                            <img src="{{ asset($activity['image']) }}"
                                 alt="{{ $activity['title'] }}"
                                 loading="lazy"
                                 onerror="this.closest('.relative').querySelector('[data-fallback]').classList.remove('hidden'); this.remove();"
                                 class="h-full w-full object-cover transition duration-700 hover:scale-[1.03]">

                            <div data-fallback
                                 class="absolute inset-0 hidden place-items-center bg-gradient-to-br from-fog-100 to-fog-200 text-fog-400"
                                 style="display:grid">
                                <span class="font-display text-5xl text-fog-300">{{ $activity['number'] }}</span>
                            </div>
                        </div>
                    </div>

                    {{-- copy --}}
                    <div class="{{ $i % 2 === 1 ? 'lg:order-1' : '' }}">
                        <p class="flex items-center gap-3 font-mono text-[11px] uppercase tracking-[0.2em] text-brand-600">
                            <span>{{ $activity['number'] }}</span>
                            <span class="h-px w-8 bg-brand-200"></span>
                            <span>{{ $activity['kind'] }}</span>
                        </p>

                        <h2 class="mt-4 font-display text-3xl font-medium leading-tight text-ink-900">
                            {{ $activity['title'] }}
                        </h2>

                        <p class="mt-4 text-base leading-relaxed text-tide-700">
                            {{ $activity['body'] }}
                        </p>

                        <ul class="mt-6 flex flex-wrap gap-2">
                            @foreach ($activity['tags'] as $tag)
                                <li class="rounded-full bg-fog-50 px-3.5 py-1.5 font-mono text-[10px] uppercase tracking-wide text-tide-600 ring-1 ring-fog-200">
                                    {{ $tag }}
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </article>
            @endforeach
        </div>
    </section>

    {{-- ------------------------------------------------------------- CTA --}}
    <section class="relative overflow-hidden bg-ink-900 py-24 text-white">
        <img src="{{ asset('assets/images/beach.jpg') }}" alt=""
             loading="lazy" onerror="this.style.display='none'"
             class="absolute inset-0 h-full w-full object-cover opacity-25">
        <div class="absolute inset-0 bg-gradient-to-r from-ink-900 via-ink-900/85 to-ink-900/50"></div>

        <div class="relative z-10 mx-auto max-w-2xl px-6 text-center">
            <p data-reveal class="font-mono text-[11px] uppercase tracking-[0.2em] text-brand-300">
                Ready for your getaway?
            </p>
            <h2 data-reveal class="mt-3 font-display text-3xl font-medium sm:text-4xl">
                Book Your Stay
            </h2>
            <p data-reveal class="mx-auto mt-4 max-w-lg text-base leading-relaxed text-white/75">
                Ocean views, cozy comforts, and direct beach access. Your coastal escape is just a
                few clicks away.
            </p>

            <div data-reveal class="mt-9 flex flex-wrap items-center justify-center gap-3">
                <a href="{{ route('availability.search') }}"
                   class="rounded-full bg-brand-600 px-7 py-3.5 text-sm font-semibold text-white transition hover:bg-brand-700">
                    Reserve Your Cottage
                </a>
                <a href="{{ route('cottages.index') }}"
                   class="rounded-full px-7 py-3.5 text-sm font-semibold text-white/90 ring-1 ring-white/25 transition hover:bg-white/10">
                    See the cottages
                </a>
            </div>

            <p data-reveal class="mt-8 text-sm text-white/60">
                1 Gull Rock Road, Lockeport, Nova Scotia, Canada B0T 1L0<br>
                <a href="mailto:info@oceanescapecottages.ca" class="hover:text-white hover:underline">
                    info@oceanescapecottages.ca
                </a>
                &middot;
                <a href="tel:9023981020" class="hover:text-white hover:underline">902-398-1020</a>
            </p>
        </div>
    </section>
</x-website-layout>