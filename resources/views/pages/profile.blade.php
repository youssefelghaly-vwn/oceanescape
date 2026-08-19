<x-website-layout title="My Stays | Ocean Escape Cottages">

@php
    $sections = [
        ['key' => 'current',   'title' => 'You\'re here now',   'note' => null],
        ['key' => 'upcoming',  'title' => 'Coming up',          'note' => null],
        ['key' => 'past',      'title' => 'Previous stays',     'note' => null],
        ['key' => 'cancelled', 'title' => 'Cancelled',          'note' => null],
    ];
@endphp

<section class="border-b border-fog-200 bg-fog-50 pb-10 pt-32">
    <div class="mx-auto max-w-5xl px-6 lg:px-8">
        <p class="font-mono text-[11px] uppercase tracking-[0.2em] text-brand-600">Your account</p>
        <h1 class="mt-3 font-display text-3xl font-medium leading-tight text-ink-900 sm:text-4xl">
            Welcome back, {{ \Illuminate\Support\Str::before($user->name, ' ') }}
        </h1>

        @if ($total > 0)
            <p class="mt-3 text-base text-tide-700">
                {{ $total }} {{ Str::plural('booking', $total) }}
                @if ($nights > 0)
                    &middot; {{ $nights }} {{ Str::plural('night', $nights) }} with us
                @endif
            </p>
        @endif

        <div class="mt-6 flex flex-wrap gap-3">
            <a href="{{ route('availability.search') }}"
               class="rounded-full bg-brand-600 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-brand-700">
                Book another stay
            </a>
            <a href="{{ route('account.edit') }}"
               class="rounded-full px-5 py-2.5 text-sm font-semibold text-brand-700 ring-1 ring-brand-200 transition hover:bg-brand-50">
                Account settings
            </a>
        </div>
    </div>
</section>

<section class="bg-white py-12">
    <div class="mx-auto max-w-5xl space-y-12 px-6 lg:px-8">

        @if (session('profile_error'))
            <div class="rounded-2xl bg-amber-50 px-5 py-4 text-sm text-amber-900 ring-1 ring-amber-200">
                {{ session('profile_error') }}
            </div>
        @endif

        @if ($failed)
            <div class="rounded-3xl bg-amber-50 px-6 py-5 text-sm text-amber-900 ring-1 ring-amber-200">
                <p class="font-medium">We couldn&rsquo;t load your bookings just now.</p>
                <p class="mt-1">
                    Please try again shortly, or call
                    <a href="tel:9023981020" class="font-semibold underline">902-398-1020</a>
                    and we&rsquo;ll look them up for you.
                </p>
            </div>

        @elseif ($total === 0)
            <div class="rounded-3xl bg-fog-50 px-6 py-16 text-center ring-1 ring-black/5">
                <div class="mx-auto grid h-14 w-14 place-items-center rounded-full bg-white text-tide-400 ring-1 ring-fog-200">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                        <rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>
                    </svg>
                </div>
                <h2 class="mt-5 font-display text-xl text-ink-900">No bookings on this address yet</h2>
                <p class="mx-auto mt-2 max-w-md text-sm leading-relaxed text-tide-600">
                    We match stays to <span class="font-medium text-ink-900">{{ $user->email }}</span>.
                    If you booked with a different address, add it in
                    <a href="{{ route('account.edit') }}" class="font-medium text-brand-700 underline">account settings</a>,
                    or <a href="mailto:info@oceanescapecottages.ca" class="font-medium text-brand-700 underline">email us</a>
                    and we&rsquo;ll connect them.
                </p>
                <a href="{{ route('availability.search') }}"
                   class="mt-6 inline-block rounded-full bg-brand-600 px-6 py-3 text-sm font-semibold text-white transition hover:bg-brand-700">
                    Find your dates
                </a>
            </div>

        @else
            @foreach ($sections as $section)
                @php $items = $grouped[$section['key']]; @endphp
                @continue($items->isEmpty())

                <div>
                    <div class="mb-5 flex flex-wrap items-baseline justify-between gap-3">
                        <h2 class="font-display text-2xl font-medium text-ink-900">{{ $section['title'] }}</h2>
                        <span class="font-mono text-[11px] uppercase tracking-wide text-tide-500">
                            {{ $items->count() }} {{ Str::plural('stay', $items->count()) }}
                        </span>
                    </div>

                    <div class="space-y-4">
                        @foreach ($items as $r)
                            <article class="overflow-hidden rounded-3xl bg-white ring-1 ring-black/5 transition hover:shadow-lg hover:shadow-ink-900/5">
                                <div class="flex flex-col gap-5 p-5 sm:flex-row sm:items-center sm:justify-between">

                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <h3 class="font-display text-lg font-medium text-ink-900">
                                                {{ $r->propertyName ?: 'Your cottage' }}
                                            </h3>
                                            @if ($r->timeframe() === 'current')
                                                <span class="rounded-full bg-emerald-50 px-2.5 py-1 font-mono text-[9px] font-semibold uppercase tracking-wide text-emerald-800 ring-1 ring-emerald-200">
                                                    In progress
                                                </span>
                                            @elseif ($r->timeframe() === 'cancelled')
                                                <span class="rounded-full bg-fog-100 px-2.5 py-1 font-mono text-[9px] font-semibold uppercase tracking-wide text-tide-600 ring-1 ring-fog-300">
                                                    Cancelled
                                                </span>
                                            @endif
                                        </div>

                                        <p class="mt-1.5 text-sm text-tide-700">{{ $r->stayLabel() }}</p>
                                        <p class="mt-0.5 font-mono text-[10px] uppercase tracking-wide text-tide-500">
                                            {{ $r->nights ? $r->nights . ' nights · ' : '' }}{{ $r->guestCount() }} guests
                                           · {{ $r->reference() }}
                                        </p>

                                        @if ($r->timeframe() === 'upcoming' && $r->arrival)
                                            <p class="mt-2 font-mono text-[11px] text-brand-700">
                                                {{ $r->arrival->diffForHumans(['parts' => 1]) }} to go
                                            </p>
                                        @endif
                                    </div>

                                    <div class="flex shrink-0 items-end justify-between gap-4 sm:flex-col sm:items-end">
                                        <div class="text-left sm:text-right">
                                            <p class="font-display text-xl font-medium text-ink-900">
                                                {{ $r->money($r->total) }}
                                            </p>
                                            @if (($r->amountDue ?? 0) > 0 && !$r->isCancelled())
                                                <p class="mt-0.5 font-mono text-[10px] uppercase tracking-wide text-amber-700">
                                                    {{ $r->money($r->amountDue) }} outstanding
                                                </p>
                                            @endif
                                        </div>

                                        <a href="{{ route('profile.show', $r->id) }}"
                                           class="inline-flex items-center gap-1.5 rounded-full px-4 py-2 text-sm font-semibold text-brand-700 ring-1 ring-brand-200 transition hover:bg-brand-50">
                                            Details
                                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                                        </a>
                                    </div>
                                </div>
                            </article>
                        @endforeach
                    </div>
                </div>
            @endforeach
        @endif

        <p class="border-t border-fog-200 pt-8 text-xs leading-relaxed text-tide-500">
            Bookings shown here are matched to <span class="font-medium">{{ $user->email }}</span> and read
            live from our reservation system. If something looks wrong, please
            <a href="{{ route('contact') }}" class="font-medium text-brand-700 underline">get in touch</a>
            &mdash; we&rsquo;d rather fix it than have you wonder.
        </p>
    </div>
</section>

</x-website-layout>