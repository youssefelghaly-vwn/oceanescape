{{-- resources/views/admin/reservations/index.blade.php --}}
<x-admin-layout title="Reservations">
    <x-slot:heading>
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="font-display text-2xl font-medium">Reservations</h1>
                <p class="mt-0.5 text-sm text-tide-600">
                    Live from Lodgify &mdash; {{ number_format($stats['total']) }} total,
                    {{ number_format($stats['upcoming']) }} upcoming
                </p>
            </div>
            <form method="POST" action="{{ route('admin.reservations.refresh') }}">
                @csrf
                <button class="inline-flex items-center gap-2 rounded-full px-4 py-2 text-sm font-medium text-brand-700 ring-1 ring-brand-200 transition hover:bg-brand-50">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M23 4v6h-6M1 20v-6h6"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>
                    </svg>
                    Refresh
                </button>
            </form>
        </div>
    </x-slot:heading>

    {{-- timeframe tabs --}}
    <div class="mb-5 flex flex-wrap gap-2">
        @foreach ([
            'all'       => 'All ' . $stats['total'],
            'upcoming'  => 'Upcoming ' . $stats['upcoming'],
            'current'   => 'In house ' . $stats['current'],
            'past'      => 'Past ' . $stats['past'],
            'cancelled' => 'Cancelled',
        ] as $value => $label)
            <a href="{{ request()->fullUrlWithQuery(['timeframe' => $value, 'page' => null]) }}"
               class="rounded-full px-4 py-2 font-mono text-[10px] uppercase tracking-wide transition
                      {{ ($filters['timeframe'] ?? 'all') === $value ? 'bg-ink-900 text-white' : 'bg-white text-tide-600 ring-1 ring-fog-300 hover:ring-brand-300' }}">
                {{ $label }}
            </a>
        @endforeach
    </div>

    {{-- filters --}}
    <form method="GET" class="mb-5 rounded-3xl bg-white p-5 ring-1 ring-black/5">
        <input type="hidden" name="timeframe" value="{{ $filters['timeframe'] ?? 'all' }}">

        @php
            $field = 'w-full rounded-xl border-0 bg-fog-50 px-3 py-2.5 text-sm ring-1 ring-fog-300 placeholder:text-tide-400 focus:ring-2 focus:ring-brand-400';
            $label = 'block font-mono text-[10px] uppercase tracking-wide text-tide-500';
        @endphp

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="lg:col-span-2">
                <label for="q" class="{{ $label }}">Search</label>
                <input id="q" type="search" name="q" value="{{ $filters['q'] }}"
                       placeholder="Booking id, reference, guest, phone, cottage…"
                       class="mt-1.5 {{ $field }}">
            </div>

            {{-- Called out on its own because it is the most common lookup: a
                 guest phones and gives their email and nothing else. --}}
            <div class="lg:col-span-2">
                <label for="email" class="{{ $label }}">Guest email</label>
                <input id="email" type="search" name="email" value="{{ $filters['email'] }}"
                       placeholder="name@example.com" class="mt-1.5 {{ $field }}">
            </div>

            <div>
                <label for="status" class="{{ $label }}">Status</label>
                <select id="status" name="status" class="mt-1.5 {{ $field }}">
                    <option value="">Any</option>
                    @foreach ($options['statuses'] as $s)
                        <option value="{{ $s }}" @selected($filters['status'] === $s)>{{ $s }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="property_id" class="{{ $label }}">Cottage</label>
                <select id="property_id" name="property_id" class="mt-1.5 {{ $field }}">
                    <option value="">Any</option>
                    @foreach ($options['properties'] as $id => $name)
                        <option value="{{ $id }}" @selected((string) $filters['property_id'] === (string) $id)>
                            {{ Str::limit($name, 30) }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="source" class="{{ $label }}">Source</label>
                <select id="source" name="source" class="mt-1.5 {{ $field }}">
                    <option value="">Any</option>
                    @foreach ($options['sources'] as $s)
                        <option value="{{ $s }}" @selected($filters['source'] === $s)>{{ $s }}</option>
                    @endforeach
                </select>
            </div>

            <div class="grid grid-cols-2 gap-2">
                <div>
                    <label for="from" class="{{ $label }}">Arrives from</label>
                    <input id="from" type="date" name="from" value="{{ $filters['from'] }}" class="mt-1.5 {{ $field }}">
                </div>
                <div>
                    <label for="to" class="{{ $label }}">to</label>
                    <input id="to" type="date" name="to" value="{{ $filters['to'] }}" class="mt-1.5 {{ $field }}">
                </div>
            </div>
        </div>

        <div class="mt-4 flex flex-wrap items-center gap-3">
            <label class="inline-flex items-center gap-2 text-sm text-tide-700">
                <input type="checkbox" name="unpaid" value="1" @checked($filters['unpaid'])
                       class="rounded border-fog-300 text-brand-600 focus:ring-brand-400">
                Balance outstanding
            </label>

            <button type="submit"
                    class="rounded-full bg-ink-900 px-6 py-2.5 text-sm font-medium text-white transition hover:bg-ink-800">
                Apply filters
            </button>

            <a href="{{ route('admin.reservations.index') }}"
               class="text-sm text-tide-500 hover:text-ink-900">Reset</a>

            <span class="ml-auto font-mono text-[11px] uppercase tracking-wide text-tide-500">
                {{ number_format($matched) }} {{ Str::plural('match', $matched) }}
            </span>
        </div>
    </form>

    @if ($reservations->isEmpty())
        <div class="rounded-3xl bg-white px-6 py-16 text-center ring-1 ring-black/5">
            <p class="font-display text-lg text-ink-900">No reservations match</p>
            <p class="mt-1 text-sm text-tide-600">
                Try clearing the filters, or refresh to pull the latest from Lodgify.
            </p>
        </div>
    @else
        <div class="overflow-hidden rounded-3xl bg-white ring-1 ring-black/5">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-fog-200 bg-fog-50">
                        <tr class="font-mono text-[10px] uppercase tracking-wide text-tide-500">
                            @foreach ([
                                'guest'    => 'Guest',
                                'property' => 'Cottage',
                                'arrival'  => 'Stay',
                                'total'    => 'Total',
                            ] as $key => $heading)
                                @php
                                    $isActive = ($filters['sort'] ?? 'arrival') === $key;
                                    $nextDir  = ($isActive && ($filters['dir'] ?? 'desc') === 'desc') ? 'asc' : 'desc';
                                @endphp
                                <th class="px-5 py-3 font-medium">
                                    <a href="{{ request()->fullUrlWithQuery(['sort' => $key, 'dir' => $nextDir]) }}"
                                       class="inline-flex items-center gap-1 hover:text-ink-900">
                                        {{ $heading }}
                                        @if ($isActive)
                                            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"
                                                 class="{{ ($filters['dir'] ?? 'desc') === 'asc' ? '' : 'rotate-180' }}">
                                                <path d="m6 15 6-6 6 6"/>
                                            </svg>
                                        @endif
                                    </a>
                                </th>
                            @endforeach
                            <th class="px-5 py-3 font-medium">Status</th>
                            <th class="px-5 py-3"></th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-fog-200">
                        @foreach ($reservations as $r)
                            <tr class="transition hover:bg-fog-50">
                                <td class="px-5 py-4">
                                    <a href="{{ route('admin.reservations.show', $r->id) }}"
                                       class="block font-medium text-ink-900 hover:text-brand-700">
                                        {{ $r->guestName ?: 'Unnamed guest' }}
                                    </a>
                                    @if ($r->guestEmail)
                                        <span class="mt-0.5 block truncate text-xs text-tide-500">{{ $r->guestEmail }}</span>
                                    @else
                                        {{-- Worth flagging: with no email this booking can never
                                             appear in a guest's account. --}}
                                        <span class="mt-0.5 inline-flex items-center gap-1 text-xs text-amber-700">
                                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                                                <circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/>
                                            </svg>
                                            No email on file
                                        </span>
                                    @endif
                                    <span class="mt-0.5 block font-mono text-[10px] text-tide-400">{{ $r->reference() }}</span>
                                </td>

                                <td class="px-5 py-4 text-tide-700">
                                    {{ Str::limit($r->propertyName ?: ('#' . $r->propertyId), 28) }}
                                    @if ($r->source)
                                        <span class="mt-0.5 block font-mono text-[10px] uppercase text-tide-400">{{ $r->source }}</span>
                                    @endif
                                </td>

                                <td class="whitespace-nowrap px-5 py-4 text-tide-700">
                                    {{ $r->stayLabel() }}
                                    <span class="block text-xs text-tide-500">
                                        {{ $r->nights ? $r->nights . ' nights · ' : '' }}{{ $r->guestCount() }} guests
                                    </span>
                                </td>

                                <td class="whitespace-nowrap px-5 py-4">
                                    <span class="font-medium text-ink-900">{{ $r->money($r->total) }}</span>
                                    @if (($r->amountDue ?? 0) > 0)
                                        <span class="mt-0.5 block text-xs text-amber-700">
                                            {{ $r->money($r->amountDue) }} due
                                        </span>
                                    @endif
                                </td>

                                <td class="px-5 py-4">
                                    <span class="inline-flex rounded-full px-2.5 py-1 font-mono text-[10px] font-semibold uppercase tracking-wide ring-1 {{ $r->statusClasses() }}">
                                        {{ $r->status ?: 'unknown' }}
                                    </span>
                                    <span class="mt-1 block font-mono text-[9px] uppercase tracking-wide text-tide-400">
                                        {{ $r->timeframe() }}
                                    </span>
                                </td>

                                <td class="px-5 py-4 text-right">
                                    <a href="{{ route('admin.reservations.show', $r->id) }}"
                                       class="font-mono text-[10px] uppercase tracking-wide text-brand-600 hover:text-brand-800">
                                        Open
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-5">{{ $reservations->links() }}</div>
    @endif

    <p class="mt-6 text-xs leading-relaxed text-tide-500">
        Read directly from Lodgify and cached for a few minutes &mdash; nothing about reservations
        is stored here, so this is always what Lodgify holds. Edits must be made in Lodgify.
    </p>
</x-admin-layout>