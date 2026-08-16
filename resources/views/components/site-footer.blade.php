{{-- resources/views/components/footer.blade.php --}}
@php
    use Illuminate\Support\Facades\Route as RouteFacade;
    $to = fn (string $name, string $fallback) => RouteFacade::has($name) ? route($name) : url($fallback);
@endphp

<footer class="relative mt-24 overflow-hidden bg-ink-900 text-brand-50">
    <svg class="absolute -top-px left-0 w-full text-fog-50" viewBox="0 0 1440 48" preserveAspectRatio="none" fill="currentColor" aria-hidden="true">
        <path d="M0,24 C240,48 480,0 720,12 C960,24 1200,48 1440,24 L1440,0 L0,0 Z" />
    </svg>

    <div class="mx-auto max-w-7xl px-6 pb-10 pt-20 lg:px-8">
        <div class="grid gap-12 lg:grid-cols-4">

            <div class="lg:col-span-2">
                <span class="font-display text-2xl font-medium text-white">
                    Ocean Escape <span class="italic text-brand-300">Cottages</span>
                </span>
                <p class="mt-4 max-w-sm text-sm leading-relaxed text-brand-100/70">
                    Six oceanfront cottages on the Nova Scotia coast. Real-time
                    availability and transparent pricing — see the total before
                    you book, not at checkout.
                </p>

                <address class="mt-6 not-italic text-sm leading-relaxed text-brand-100/70">
                    1 Gull Rock Road<br>
                    Lockeport, Nova Scotia<br>
                    Canada B0T 1L0
                </address>
            </div>

            <div>
                <h3 class="font-mono text-xs uppercase tracking-wider text-brand-300">Explore</h3>
                <ul class="mt-4 space-y-3 text-sm text-brand-100/70">
                    <li><a href="{{ $to('cottages.index', '/cottages') }}" class="transition-colors hover:text-white">All Cottages</a></li>
                    <li><a href="{{ $to('availability.search', '/availability') }}" class="transition-colors hover:text-white">Availability</a></li>
                    <li><a href="{{ $to('things-to-do', '/things-to-do') }}" class="transition-colors hover:text-white">Things to Do</a></li>
                    <li><a href="{{ $to('business-stays.create', '/business-stays') }}" class="transition-colors hover:text-white">Business &amp; Group Stays</a></li>
                </ul>
            </div>

            <div>
                <h3 class="font-mono text-xs uppercase tracking-wider text-brand-300">Contact</h3>
                <ul class="mt-4 space-y-3 text-sm text-brand-100/70">
                    <li><a href="tel:19023981020" class="font-mono transition-colors hover:text-white">902-398-1020</a></li>
                    <li><a href="mailto:info@oceanescapecottages.ca" class="break-words transition-colors hover:text-white">info@oceanescapecottages.ca</a></li>
                    <li>Nova Scotia, Canada</li>
                </ul>

                <h3 class="mt-8 font-mono text-xs uppercase tracking-wider text-brand-300">Legal</h3>
                <ul class="mt-4 space-y-3 text-sm text-brand-100/70">
                    <li><a href="{{ $to('privacy', '/privacy-and-policy') }}" class="transition-colors hover:text-white">Privacy &amp; Policy</a></li>
                </ul>
            </div>
        </div>

        <div class="mt-16 flex flex-col items-center justify-between gap-4 border-t border-white/10 pt-8 font-mono text-[11px] tracking-wide text-brand-100/50 sm:flex-row">
            <p>&copy; {{ date('Y') }} OCEAN ESCAPE COTTAGES. ALL RIGHTS RESERVED.</p>
            <p class="flex items-center gap-4">
                <span>BUILT IN-HOUSE</span>
                @guest
                    <a href="{{ route('login') }}" class="transition-colors hover:text-brand-100">STAFF</a>
                @endguest
            </p>
        </div>
    </div>
</footer>