{{-- resources/views/components/cottage-openings.blade.php

     Availability chips for a cottage card.

     `cottage` is accepted but unused — callers pass it, and declaring it here
     keeps it out of $attributes (where an object would otherwise end up being
     spread onto the element). --}}
@props([
    'cottage' => null,
    'windows' => [],
    'adults'  => 2,
    'limit'   => 3,
])

<div class="mt-4">
    @if (!empty($windows))
        <p class="font-mono text-[10px] uppercase tracking-[0.12em] text-tide-500">Next open</p>

        <div class="mt-2 flex flex-wrap gap-1.5">
            @foreach (array_slice($windows, 0, $limit) as $w)
                @php
                    $ws = \Illuminate\Support\Carbon::parse($w['start']);
                    $we = \Illuminate\Support\Carbon::parse($w['end'])->addDay();
                @endphp
                <span class="inline-block rounded-full bg-brand-50 px-2.5 py-1 text-[11px] font-medium text-brand-800 ring-1 ring-brand-100">
                    {{ $ws->format('M j') }}&ndash;{{ $we->format($ws->month === $we->month ? 'j' : 'M j') }}
                    <span class="font-mono text-[9px] text-brand-600">{{ $w['nights'] }}n</span>
                </span>
            @endforeach
        </div>
    @else
        <p class="text-xs italic text-tide-400">
            Fully booked for the next {{ config('lodgify.availability_window_days', 90) }} days.
        </p>
    @endif
</div>