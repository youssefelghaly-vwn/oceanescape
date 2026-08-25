{{-- resources/views/admin/audits/index.blade.php

     Every audit row, newest first. Read-only by design: the rows are immutable
     (BookingAuditLog throws on update and delete), so there is nothing to offer an admin
     here but reading, filtering, and a link into one booking's full trail.

     The context column is already scrubbed on the way in by App\Support\ScrubbedContext —
     it is safe to render, and there is no card data anywhere in it to redact twice. --}}
<x-admin-layout title="Audit trail">
    <x-slot:heading>
        <h1 class="font-display text-2xl font-medium">Audit trail</h1>
        <p class="mt-0.5 text-sm text-tide-600">
            Every booking and payment event, in the order it happened.
        </p>
    </x-slot:heading>

    {{-- stats --}}
    <div class="mb-6 grid gap-4 sm:grid-cols-4">
        @foreach ([
            ['All events', number_format($stats['total']), 'text-ink-900', null],
            ['Today', number_format($stats['today']), 'text-ink-900', null],
            ['Last 7 days', number_format($stats['week']), 'text-ink-900', null],
            ['Needs a human', number_format($stats['attention']), $stats['attention'] > 0 ? 'text-rose-700' : 'text-tide-400', 'attention'],
        ] as [$label, $value, $colour, $filter])
            @if ($filter)
                {{-- The one tile that is a call to action, so it is also the link that
                     filters to it. --}}
                <a href="{{ route('admin.audits.index', ['attention' => 1]) }}"
                   class="rounded-3xl bg-white p-5 ring-1 ring-black/5 transition hover:ring-brand-300">
                    <p class="font-mono text-[10px] uppercase tracking-wide text-tide-500">{{ $label }}</p>
                    <p class="mt-1 font-display text-2xl font-medium {{ $colour }}">{{ $value }}</p>
                </a>
            @else
                <div class="rounded-3xl bg-white p-5 ring-1 ring-black/5">
                    <p class="font-mono text-[10px] uppercase tracking-wide text-tide-500">{{ $label }}</p>
                    <p class="mt-1 font-display text-2xl font-medium {{ $colour }}">{{ $value }}</p>
                </div>
            @endif
        @endforeach
    </div>

    {{-- filters --}}
    <form method="GET" class="mb-5 flex flex-wrap items-center gap-2">
        <input type="search" name="q" value="{{ $filters['q'] ?? '' }}"
               placeholder="BK-…, PAY-…, email, cottage, event…"
               class="min-w-0 flex-1 rounded-full border-0 bg-white px-4 py-2.5 text-sm ring-1 ring-fog-300 placeholder:text-tide-400 focus:ring-2 focus:ring-brand-400 sm:max-w-xs">

        <select name="group" class="rounded-full border-0 bg-white py-2.5 pl-4 pr-9 text-sm ring-1 ring-fog-300 focus:ring-2 focus:ring-brand-400">
            @foreach ($groups as $value => $label)
                <option value="{{ $value }}" @selected(($filters['group'] ?? 'all') === $value)>{{ $label }}</option>
            @endforeach
        </select>

        <select name="actor" class="rounded-full border-0 bg-white py-2.5 pl-4 pr-9 text-sm ring-1 ring-fog-300 focus:ring-2 focus:ring-brand-400">
            @foreach ($actors as $value => $label)
                <option value="{{ $value }}" @selected(($filters['actor'] ?? 'all') === $value)>{{ $label }}</option>
            @endforeach
        </select>

        <label class="inline-flex items-center gap-2 rounded-full bg-white px-4 py-2.5 text-sm ring-1 ring-fog-300">
            <input type="checkbox" name="attention" value="1" @checked(! empty($filters['attention']))
                   class="rounded border-fog-300 text-brand-600 focus:ring-brand-400">
            Needs a human
        </label>

        <button class="rounded-full bg-ink-900 px-5 py-2.5 text-sm font-medium text-white hover:bg-ink-800">Filter</button>

        @if (array_filter($filters, fn ($v) => filled($v) && $v !== 'all'))
            <a href="{{ route('admin.audits.index') }}" class="text-sm text-tide-600 underline hover:text-ink-900">Clear</a>
        @endif
    </form>

    @if ($entries->isEmpty())
        <div class="rounded-3xl bg-white px-6 py-16 text-center ring-1 ring-black/5">
            <p class="font-display text-lg text-ink-900">Nothing recorded here</p>
            <p class="mt-1 text-sm text-tide-600">
                @if (array_filter($filters, fn ($v) => filled($v) && $v !== 'all'))
                    No events match that filter.
                @else
                    Every booking and payment event will appear in this list as it happens.
                @endif
            </p>
        </div>
    @else
        <div class="overflow-hidden rounded-3xl bg-white ring-1 ring-black/5">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-fog-200 bg-fog-50">
                        <tr class="font-mono text-[10px] uppercase tracking-wide text-tide-500">
                            <th class="px-5 py-3 font-medium">When</th>
                            <th class="px-5 py-3 font-medium">Event</th>
                            <th class="px-5 py-3 font-medium">Booking</th>
                            <th class="px-5 py-3 font-medium">Change</th>
                            <th class="px-5 py-3 font-medium">Actor</th>
                            <th class="px-5 py-3 font-medium">Detail</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-fog-200">
                        @foreach ($entries as $entry)
                            <tr class="align-top transition hover:bg-fog-50">
                                <td class="whitespace-nowrap px-5 py-4 text-tide-600">
                                    {{ $entry->created_at->diffForHumans(short: true) }}
                                    <span class="mt-0.5 block text-xs text-tide-400">
                                        {{ $entry->created_at->format('M j, H:i:s') }}
                                    </span>
                                </td>

                                <td class="px-5 py-4">
                                    <span class="inline-flex rounded-full px-2.5 py-1 font-mono text-[10px] font-semibold uppercase tracking-wide ring-1 {{ $entry->badgeClasses() }}">
                                        {{ $entry->event }}
                                    </span>
                                    @if ($entry->needsHumanAttention())
                                        <span class="mt-1 block font-mono text-[10px] uppercase tracking-wide text-rose-700">
                                            needs a human
                                        </span>
                                    @endif
                                </td>

                                <td class="whitespace-nowrap px-5 py-4">
                                    @if ($entry->booking)
                                        {{-- Links to the whole trail, not to this row: one event on
                                             its own explains nothing. --}}
                                        <a href="{{ route('admin.audits.show', $entry->booking) }}"
                                           class="font-medium text-brand-700 hover:underline">
                                            {{ $entry->booking->reference }}
                                        </a>
                                        <span class="mt-0.5 block text-xs text-tide-500">
                                            {{ Str::limit($entry->booking->cottage_name, 24) }}
                                        </span>
                                        @if ($entry->bookingPayment)
                                            <span class="mt-0.5 block font-mono text-[10px] text-tide-400">
                                                {{ $entry->bookingPayment->reference }} · {{ $entry->bookingPayment->type->value }}
                                            </span>
                                        @endif
                                    @else
                                        {{-- nullOnDelete on both foreign keys: the trail deliberately
                                             outlives the records it describes. --}}
                                        <span class="text-tide-400">&mdash;</span>
                                    @endif
                                </td>

                                <td class="whitespace-nowrap px-5 py-4 text-tide-700">
                                    @if ($entry->from_status || $entry->to_status)
                                        <span class="font-mono text-[11px]">
                                            {{ $entry->from_status ?? '—' }}
                                            <span class="text-tide-400">&rarr;</span>
                                            {{ $entry->to_status ?? '—' }}
                                        </span>
                                    @else
                                        <span class="text-tide-400">&mdash;</span>
                                    @endif
                                </td>

                                <td class="whitespace-nowrap px-5 py-4 text-tide-700">
                                    {{ $entry->actor_type }}
                                    @if ($entry->actor)
                                        <span class="mt-0.5 block text-xs text-tide-500">{{ $entry->actor->email }}</span>
                                    @endif
                                    @if ($entry->ip_address)
                                        <span class="mt-0.5 block font-mono text-[10px] text-tide-400">{{ $entry->ip_address }}</span>
                                    @endif
                                </td>

                                <td class="px-5 py-4">
                                    @if ($json = $entry->contextJson())
                                        <details class="max-w-md">
                                            <summary class="cursor-pointer text-xs text-brand-600 hover:underline">Context</summary>
                                            <pre class="mt-2 overflow-x-auto rounded-xl bg-fog-50 p-3 font-mono text-[10px] leading-relaxed text-tide-700">{{ $json }}</pre>
                                        </details>
                                    @else
                                        <span class="text-tide-400">&mdash;</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-5">{{ $entries->links() }}</div>
    @endif

    <p class="mt-6 text-xs leading-relaxed text-tide-500">
        These rows are append-only and cannot be edited or deleted, here or anywhere else in
        the application. The same events are also written to
        <code class="font-mono">storage/logs/booking-*.log</code>, and every payment event to
        <code class="font-mono">storage/logs/payments-*.log</code> &mdash; useful when the
        database is the thing that failed. Card details never appear in either: they never
        reach this server.
    </p>
</x-admin-layout>
