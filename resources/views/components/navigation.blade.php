{{-- resources/views/components/navbar.blade.php --}}
@props(['transparent' => false])

@php
    use Illuminate\Support\Facades\Route as RouteFacade;

    /**
     * Live ticker of real next-open windows.
     *
     * Wrapped in try/catch and cached: the header renders on every page, so a
     * Lodgify hiccup must never take the whole site down. On failure we fall
     * back to a quiet generic line rather than stale or invented dates.
     */
    $ticker = \Illuminate\Support\Facades\Cache::remember('nav:ticker', 300, function () {
        try {
            return app(\App\Services\Lodgify\LodgifyRepository::class)
                ->cottagesWithOpenings(windowsPerCottage: 1)
                ->map(function ($listing) {
                    $window = $listing['windows'][0] ?? null;
                    if (!$window) {
                        return null;
                    }
                    $start = \Illuminate\Support\Carbon::parse($window['start']);
                    $end = \Illuminate\Support\Carbon::parse($window['end'])->addDay();

                    return strtoupper(
                        \Illuminate\Support\Str::limit($listing['cottage']->name, 28, '') .
                            ' · NEXT OPEN ' .
                            $start->format('M j') .
                            '–' .
                            $end->format($start->month === $end->month ? 'j' : 'M j') .
                            ' · ' .
                            $window['nights'] .
                            ' NIGHTS',
                    );
                })
                ->filter()
                ->values()
                ->all();
        } catch (\Throwable $e) {
            report($e);
            return [];
        }
    });

    if (empty($ticker)) {
        $ticker = ['SIX OCEANFRONT COTTAGES · LOCKEPORT, NOVA SCOTIA · CALL 902-398-1020'];
    }

    // Fall back to a plain path when a route name isn't registered yet, so the
// nav never throws on a half-built site.
$to = fn(string $name, string $fallback) => RouteFacade::has($name) ? route($name) : url($fallback);

$navLinks = [
    'Cottages' => $to('cottages.index', '/cottages'),
    'Availability' => $to('availability.search', '/availability'),
    'Things to Do' => $to('things-to-do', '/things-to-do'),
    'Gallery' => $to('gallery', '/gallery'),
    'Business Stays' => $to('business-stays.create', '/business-stays'),
    'Contact' => $to('contact', '/contact'),
    'Reviews' => $to('reviews', '/reviews'),
];

$bookUrl = $to('availability.search', '/availability');

// Shared classes so Sign in / My stays read as the same nav-link family as
// the primary menu items above, just without the "current page" bolding.
$authLinkClass = 'nav-link text-sm transition-colors duration-500';
$authLinkColorAttr = "'text-ink-800 hover:text-brand-600' : 'text-white/90 hover:text-white'";
@endphp

<header x-data="{
    scrolled: false,
    mobileOpen: false,
    transparent: {{ $transparent ? 'true' : 'false' }},
}" x-init="scrolled = window.scrollY > 40;
window.addEventListener('scroll', () => scrolled = window.scrollY > 40, { passive: true });"
    x-effect="document.documentElement.classList.toggle('overflow-hidden', mobileOpen)"
    class="fixed inset-x-0 top-0 z-50">
    {{-- Live strip: a departures-board ticker of real availability. Always
         dark/mono — the one constant identity anchor whether the nav below is
         transparent or solid. --}}
    <div class="overflow-hidden border-b border-white/5 bg-ink-900 text-brand-200">
        <div class="flex h-8 items-center">
            <div
                class="flex shrink-0 animate-marquee items-center gap-16 whitespace-nowrap font-mono text-[11px] tracking-wider">
                @for ($i = 0; $i < 2; $i++)
                    @foreach ($ticker as $item)
                        <span class="flex items-center gap-2">
                            <span class="h-1.5 w-1.5 rounded-full bg-buoy-500"></span>
                            {{ $item }}
                        </span>
                    @endforeach
                @endfor
            </div>
        </div>
    </div>

    {{-- Main nav row --}}
    <div class="transition-all duration-500"
        :class="(scrolled || !transparent || mobileOpen) ?
        'bg-fog-50/95 backdrop-blur-md border-b border-fog-200 shadow-[0_1px_0_0_rgba(15,30,33,0.04)]' :
        'bg-transparent border-b border-transparent'">
        <nav class="mx-auto flex max-w-7xl items-center justify-between px-6 py-4 lg:px-8" aria-label="Main navigation">

            <a href="{{ route('home') }}" class="group flex items-center gap-2.5">
                <img src="{{ asset('assets/images/logo.png') }}" class="w-12" alt="Ocean Escape Cottages">
            </a>

            {{-- Desktop links --}}
            <div class="hidden items-center gap-7 lg:flex">
                @foreach ($navLinks as $label => $href)
                    @php $isCurrent = url()->current() === $href; @endphp
                    <a href="{{ $href }}" @if ($isCurrent) aria-current="page" @endif
                        class="nav-link text-sm transition-colors duration-500"
                        :class="(scrolled || !transparent) ?
                        '{{ $isCurrent ? 'text-brand-700 font-medium' : 'text-ink-800 hover:text-brand-600' }}' :
                        '{{ $isCurrent ? 'text-white font-medium' : 'text-white/90 hover:text-white' }}'">{{ $label }}</a>
                @endforeach
            </div>

            {{-- Right actions --}}
            <div class="hidden items-center gap-5 lg:flex">
                <a href="tel:19023981020"
                    class="flex items-center gap-2 font-mono text-xs transition-colors duration-500"
                    :class="(scrolled || !transparent) ? 'text-tide-500' : 'text-white/80'">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="1.8">
                        <path
                            d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92Z" />
                    </svg>
                    902-398-1020
                </a>

                @auth
                    @if (!auth()->user()->isAdmin())
                        <a href="{{ route('profile.index') }}"
                            class="{{ $authLinkClass }}"
                            :class="(scrolled || !transparent) ? {{ $authLinkColorAttr }}">My stays</a>
                    @endif
                @else
                    <a href="{{ route('login') }}"
                        class="{{ $authLinkClass }}"
                        :class="(scrolled || !transparent) ? {{ $authLinkColorAttr }}">Sign in</a>
                @endauth
                {{-- @auth
                    @if (auth()->user()->isAdmin())
                        <a href="{{ route('admin.business-stays.index') }}"
                            class="font-mono text-[10px] uppercase tracking-wider transition-colors duration-500"
                            :class="(scrolled || !transparent) ? 'text-tide-500 hover:text-ink-900' :
                            'text-white/70 hover:text-white'">
                            Admin
                        </a>
                    @endif
                @endauth --}}

                <a href="{{ $bookUrl }}"
                    class="rounded-full bg-brand-600 px-5 py-2.5 text-sm font-semibold text-white shadow-lg shadow-brand-600/20 transition-all duration-300 hover:-translate-y-0.5 hover:bg-brand-700 hover:shadow-brand-600/30">
                    Book Now
                </a>
            </div>

            {{-- Mobile toggle --}}
            <button @click="mobileOpen = !mobileOpen" class="-mr-2 p-2 lg:hidden" :aria-expanded="mobileOpen"
                aria-label="Toggle menu">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                    stroke-width="2" :class="(scrolled || !transparent || mobileOpen) ? 'text-ink-900' : 'text-white'">
                    <path x-show="!mobileOpen" d="M4 7h16M4 12h16M4 17h16" stroke-linecap="round" />
                    <path x-show="mobileOpen" d="M6 6l12 12M18 6L6 18" stroke-linecap="round" style="display:none" />
                </svg>
            </button>
        </nav>
    </div>

    {{-- Mobile menu --}}
    <div x-show="mobileOpen" x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in duration-200"
        x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
        class="fixed inset-x-0 bottom-0 top-[calc(2rem+65px)] z-40 bg-fog-50 lg:hidden" style="display: none;">
        <div class="flex h-full flex-col justify-between overflow-y-auto px-6 py-8">
            <div class="flex flex-col">
                @foreach ($navLinks as $label => $href)
                    <a href="{{ $href }}" x-show="mobileOpen"
                        x-transition:enter="transition ease-out duration-500"
                        x-transition:enter-start="opacity-0 -translate-x-3"
                        x-transition:enter-end="opacity-100 translate-x-0"
                        style="transition-delay: {{ $loop->index * 60 }}ms"
                        class="border-b border-fog-200 py-4 font-display text-3xl text-ink-900 transition-colors hover:text-brand-600">{{ $label }}</a>
                @endforeach

                @auth
                    @if (!auth()->user()->isAdmin())
                        <a href="{{ route('profile.index') }}" x-show="mobileOpen"
                            x-transition:enter="transition ease-out duration-500"
                            x-transition:enter-start="opacity-0 -translate-x-3"
                            x-transition:enter-end="opacity-100 translate-x-0"
                            style="transition-delay: {{ count($navLinks) * 60 }}ms"
                            class="border-b border-fog-200 py-4 font-display text-3xl text-ink-900 transition-colors hover:text-brand-600">My stays</a>
                    @endif
                @else
                    <a href="{{ route('login') }}" x-show="mobileOpen"
                        x-transition:enter="transition ease-out duration-500"
                        x-transition:enter-start="opacity-0 -translate-x-3"
                        x-transition:enter-end="opacity-100 translate-x-0"
                        style="transition-delay: {{ count($navLinks) * 60 }}ms"
                        class="border-b border-fog-200 py-4 font-display text-3xl text-ink-900 transition-colors hover:text-brand-600">Sign in</a>
                @endauth
            </div>

            <div class="flex flex-col gap-4 pt-8">
                @auth
                    @if (auth()->user()->isAdmin())
                        <a href="{{ route('admin.business-stays.index') }}"
                            class="font-mono text-xs uppercase tracking-wider text-tide-500">
                            Admin dashboard
                        </a>
                    @endif
                @endauth

                <a href="tel:19023981020" class="flex items-center gap-2 font-mono text-sm text-tide-500">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="1.8">
                        <path
                            d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92Z" />
                    </svg>
                    902-398-1020
                </a>
                <a href="{{ $bookUrl }}"
                    class="rounded-full bg-brand-600 px-5 py-3.5 text-center font-semibold text-white shadow-lg shadow-brand-600/20">
                    Book Now
                </a>
            </div>
        </div>
    </div>
</header>