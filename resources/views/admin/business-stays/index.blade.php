{{-- resources/views/admin/business-stays/index.blade.php --}}
<x-admin-layout title="Business stays">
    <x-slot:heading>
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="font-display text-2xl font-medium">Business stays</h1>
                <p class="mt-0.5 text-sm text-tide-600">
                    {{ $counts['all'] }} {{ Str::plural('enquiry', $counts['all']) }} ·
                    {{ $counts['new'] }} awaiting first contact
                </p>
            </div>
            <a href="{{ route('business-stays.create') }}" target="_blank"
               class="rounded-full px-4 py-2 text-sm font-medium text-brand-700 ring-1 ring-brand-200 transition hover:bg-brand-50">
                View public form
            </a>
        </div>
    </x-slot:heading>

    {{-- filters --}}
    <form method="GET" class="mb-5 flex flex-wrap items-center gap-2">
        <div class="relative min-w-0 flex-1 sm:max-w-xs">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                 class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-tide-400">
                <circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
            </svg>
            <input type="search" name="q" value="{{ $filters['q'] }}"
                   placeholder="Reference, company, contact, email…"
                   class="w-full rounded-full border-0 bg-white py-2.5 pl-9 pr-4 text-sm ring-1 ring-fog-300 placeholder:text-tide-400 focus:ring-2 focus:ring-brand-400">
        </div>

        <select name="status"
                class="rounded-full border-0 bg-white py-2.5 pl-4 pr-9 text-sm ring-1 ring-fog-300 focus:ring-2 focus:ring-brand-400">
            <option value="">All statuses</option>
            @foreach (\App\Enums\BusinessStayStatus::options() as $value => $label)
                <option value="{{ $value }}" @selected($filters['status'] === $value)>
                    {{ $label }} ({{ $counts[$value] ?? 0 }})
                </option>
            @endforeach
        </select>

        <label class="inline-flex items-center gap-2 rounded-full bg-white px-4 py-2.5 text-sm ring-1 ring-fog-300">
            <input type="checkbox" name="view" value="open" @checked($filters['view'] === 'open')
                   class="rounded border-fog-300 text-brand-600 focus:ring-brand-400">
            Open only
        </label>

        <button type="submit"
                class="rounded-full bg-ink-900 px-5 py-2.5 text-sm font-medium text-white transition hover:bg-ink-800">
            Filter
        </button>

        @if ($filters['q'] || $filters['status'] || $filters['view'])
            <a href="{{ route('admin.business-stays.index') }}"
               class="px-2 text-sm text-tide-500 hover:text-ink-900">Reset</a>
        @endif
    </form>

    {{-- table --}}
    @if ($requests->isEmpty())
        <div class="rounded-3xl bg-white px-6 py-16 text-center ring-1 ring-black/5">
            <div class="mx-auto grid h-12 w-12 place-items-center rounded-full bg-fog-100 text-tide-400">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
                    <rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/>
                </svg>
            </div>
            <p class="mt-4 font-display text-lg text-ink-900">No enquiries here</p>
            <p class="mt-1 text-sm text-tide-600">
                {{ ($filters['q'] || $filters['status']) ? 'Try clearing the filters.' : 'New business stay requests will appear here.' }}
            </p>
        </div>
    @else
        <div class="overflow-hidden rounded-3xl bg-white ring-1 ring-black/5">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-fog-200 bg-fog-50">
                        <tr class="font-mono text-[10px] uppercase tracking-wide text-tide-500">
                            @php
                                $col = function (string $key, string $label) use ($filters) {
                                    $isActive = $filters['sort'] === $key;
                                    $nextDir  = ($isActive && $filters['dir'] === 'desc') ? 'asc' : 'desc';
                                    $url = request()->fullUrlWithQuery(['sort' => $key, 'dir' => $nextDir]);
                                    return [$url, $isActive, $filters['dir']];
                                };
                            @endphp

                            @foreach ([
                                ['company_name', 'Company'],
                                ['guests_count', 'Party'],
                                ['check_in', 'Stay'],
                                ['created_at', 'Received'],
                            ] as [$key, $label])
                                @php [$url, $isActive, $dir] = $col($key, $label); @endphp
                                <th class="px-5 py-3 font-medium">
                                    <a href="{{ $url }}" class="inline-flex items-center gap-1 hover:text-ink-900">
                                        {{ $label }}
                                        @if ($isActive)
                                            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"
                                                 class="{{ $dir === 'asc' ? '' : 'rotate-180' }}">
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
                        @foreach ($requests as $stay)
                            <tr class="transition hover:bg-fog-50">
                                <td class="px-5 py-4">
                                    <a href="{{ route('admin.business-stays.show', $stay) }}"
                                       class="block font-medium text-ink-900 hover:text-brand-700">
                                        {{ $stay->company_name }}
                                    </a>
                                    <span class="mt-0.5 block text-xs text-tide-500">
                                        {{ $stay->contact_name }} · <span class="font-mono">{{ $stay->reference }}</span>
                                    </span>
                                </td>

                                <td class="whitespace-nowrap px-5 py-4 text-tide-700">
                                    {{ $stay->guests_count }} guests
                                    <span class="block text-xs text-tide-500">
                                        {{ $stay->cottages_count }} {{ Str::plural('cottage', $stay->cottages_count) }}
                                    </span>
                                </td>

                                <td class="whitespace-nowrap px-5 py-4 text-tide-700">
                                    {{ $stay->stay_label }}
                                    @if ($stay->nights)
                                        <span class="block text-xs text-tide-500">{{ $stay->nights }} nights</span>
                                    @endif
                                </td>

                                <td class="whitespace-nowrap px-5 py-4 text-tide-600">
                                    {{ $stay->created_at->diffForHumans(short: true) }}
                                    <span class="block text-xs text-tide-400">{{ $stay->created_at->format('M j, Y') }}</span>
                                </td>

                                <td class="px-5 py-4">
                                    <span class="inline-flex rounded-full px-2.5 py-1 font-mono text-[10px] font-semibold uppercase tracking-wide ring-1 {{ $stay->status->classes() }}">
                                        {{ $stay->status->label() }}
                                    </span>
                                </td>

                                <td class="px-5 py-4 text-right">
                                    <a href="{{ route('admin.business-stays.show', $stay) }}"
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

        <div class="mt-5">{{ $requests->links() }}</div>
    @endif
</x-admin-layout>