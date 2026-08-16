{{-- resources/views/components/cottage-card.blade.php

     REBUILT DEFENSIVELY.

     A previous version rendered with a large void between the image and the
     text, and the text escaping the card's left edge. Rather than patch it,
     this version removes every construct that could cause that class of bug:

       - no `h-full` / `flex-1` height negotiation with the grid
       - no `mt-auto` spacer that depends on the card being over-tall
       - no aspect-ratio utility on the image (fixed heights instead)
       - fixed min-heights instead of clamps for row alignment

     The trade-off is that CTAs align by reserved height rather than by being
     pushed to the bottom of a stretched card. Less clever, and it cannot
     produce a 300px hole.
--}}
@props([
    'cottage',
    'arrival'      => null,
    'departure'    => null,
    'adults'       => 2,
    'children'     => 0,
    'pets'         => 0,
    'offsetDays'   => 0,
    'windowNights' => null,
    'variant'      => 'exact',
    'price'        => null,
])

@php
    $query = array_filter([
        'arrival'   => $arrival,
        'departure' => $departure,
        'adults'    => $adults ?: null,
        'children'  => $children ?: null,
        'pets'      => $pets ?: null,
    ], fn ($v) => $v !== null && $v !== '');

    $href = route('cottage.show', ['slug' => $cottage->slug])
          . (empty($query) ? '' : '?' . http_build_query($query));

    $location = collect([$cottage->city, $cottage->country])->filter()->implode(', ');

    $facts = collect([
        $cottage->maxGuests ? $cottage->maxGuests . ' guests' : null,
        $cottage->bedrooms  ? $cottage->bedrooms . ' bed'     : null,
        $cottage->bathrooms ? $cottage->bathrooms . ' bath'   : null,
    ])->filter();

    $dateLabel = match ($variant) {
        'nearby'      => 'Closest matching dates',
        'alternative' => 'Next open window',
        default       => 'Your dates',
    };

    $symbol = ($cottage->currency ?? 'CAD') === 'CAD' ? 'CA$' : '$';
@endphp

<a href="{{ $href }}"
   class="group block overflow-hidden rounded-3xl bg-white ring-1 ring-black/5 transition duration-300 hover:-translate-y-1 hover:shadow-2xl hover:shadow-ink-900/10">

    {{-- IMAGE — fixed height, no aspect-ratio utility --}}
    <div class="relative h-52 w-full overflow-hidden bg-fog-100">
        @if ($cottage->heroImage)
            <img src="{{ $cottage->heroImage }}"
                 alt="{{ $cottage->altFor($cottage->heroImage, 0) }}"
                 loading="lazy" referrerpolicy="no-referrer"
                 class="h-full w-full object-cover transition duration-500 group-hover:scale-[1.04]">
        @else
            <span class="absolute inset-0 grid place-items-center bg-gradient-to-br from-fog-100 to-fog-200 text-fog-400">
                <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2">
                    <path d="M3 21h18M5 21V8l7-5 7 5v13M9 21v-6h6v6"/>
                </svg>
            </span>
        @endif

        @if ($variant === 'nearby' && $offsetDays > 0)
            <span class="absolute left-3 top-3 rounded-full bg-buoy-500 px-2.5 py-1 font-mono text-[9px] font-semibold uppercase tracking-wide text-white shadow-lg">
                {{ $offsetDays }} day{{ $offsetDays > 1 ? 's' : '' }} off
            </span>
        @elseif ($variant === 'alternative' && $windowNights)
            <span class="absolute left-3 top-3 rounded-full bg-ink-900/85 px-2.5 py-1 font-mono text-[9px] font-semibold uppercase tracking-wide text-white backdrop-blur">
                {{ $windowNights }} night{{ $windowNights > 1 ? 's' : '' }} open
            </span>
        @endif

        @if ($cottage->petFriendly)
            <span class="absolute right-3 top-3 grid h-8 w-8 place-items-center rounded-full bg-white/90 shadow-md backdrop-blur" title="Pet-friendly">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor" class="text-brand-600">
                    <circle cx="5.5" cy="9.5" r="2.2"/><circle cx="10" cy="6" r="2.2"/>
                    <circle cx="14" cy="6" r="2.2"/><circle cx="18.5" cy="9.5" r="2.2"/>
                    <path d="M12 12.5c2.6 0 4.8 1.9 4.8 4.2 0 1.7-1.3 2.8-3 2.8-.9 0-1.3-.3-1.8-.3s-.9.3-1.8.3c-1.7 0-3-1.1-3-2.8 0-2.3 2.2-4.2 4.8-4.2z"/>
                </svg>
            </span>
        @endif
    </div>

    {{-- BODY — plain block flow, no flex height negotiation --}}
    <div class="p-5">

        {{-- Reserved height keeps one- and two-line names aligned across a row --}}
        <h3 class="min-h-[2.9rem] break-words font-display text-[17px] font-medium leading-snug text-ink-900">
            {{ \Illuminate\Support\Str::limit($cottage->name, 58) }}
        </h3>

        @if ($location)
            <p class="mt-1 truncate font-mono text-[10px] uppercase tracking-[0.12em] text-tide-500">
                {{ $location }}
            </p>
        @endif

        @if ($facts->isNotEmpty())
            <p class="mt-3 text-xs text-tide-600">
                {{ $facts->implode(' · ') }}
            </p>
        @endif

        @if ($arrival && $departure)
            <div class="mt-4 rounded-2xl bg-fog-50 px-4 py-3">
                <p class="font-mono text-[10px] uppercase tracking-[0.12em] text-tide-500">{{ $dateLabel }}</p>
                <p class="mt-1 text-sm font-medium text-ink-900">
                    {{ \Illuminate\Support\Carbon::parse($arrival)->format('M j') }}
                    &rarr;
                    {{ \Illuminate\Support\Carbon::parse($departure)->format('M j, Y') }}
                </p>
            </div>
        @endif

        {{ $slot }}

        @if ($price && isset($price['total']))
            <p class="mt-4">
                <span class="font-display text-2xl font-medium text-ink-900">
                    {{ $symbol }}{{ number_format((float) $price['total'], 2) }}
                </span>
                @if (!empty($price['nights']))
                    <span class="font-mono text-[11px] text-tide-500">
                        total · {{ $price['nights'] }} night{{ $price['nights'] > 1 ? 's' : '' }}
                    </span>
                @endif
            </p>
        @elseif ($cottage->baseNightlyPrice)
            <p class="mt-4">
                <span class="font-display text-2xl font-medium text-ink-900">
                    {{ $symbol }}{{ number_format($cottage->baseNightlyPrice, 0) }}
                </span>
                <span class="font-mono text-[11px] text-tide-500">from / night</span>
            </p>
        @endif

        <p class="mt-5 font-mono text-[10px] uppercase tracking-[0.12em] text-brand-600">
            {{ $arrival ? 'View & book' : 'View cottage' }} &rarr;
        </p>
    </div>
</a>