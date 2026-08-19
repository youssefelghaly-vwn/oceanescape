{{-- resources/views/admin/checkouts/index.blade.php --}}
<x-admin-layout title="Checkout handoffs">
    <x-slot:heading>
        <h1 class="font-display text-2xl font-medium">Checkout handoffs</h1>
        <p class="mt-0.5 text-sm text-tide-600">
            Guests sent from this site to Lodgify&rsquo;s checkout.
        </p>
    </x-slot:heading>

    {{-- stats --}}
    <div class="mb-6 grid gap-4 sm:grid-cols-4">
        @foreach ([
            ['Redirects', number_format($stats['total']), 'text-ink-900'],
            ['Converted', number_format($stats['converted']), 'text-emerald-700'],
            ['In flight', number_format($stats['in_flight']), 'text-amber-700'],
            ['Abandoned', number_format($stats['abandoned']), 'text-tide-500'],
        ] as [$label, $value, $colour])
            <div class="rounded-3xl bg-white p-5 ring-1 ring-black/5">
                <p class="font-mono text-[10px] uppercase tracking-wide text-tide-500">{{ $label }}</p>
                <p class="mt-1 font-display text-2xl font-medium {{ $colour }}">{{ $value }}</p>
            </div>
        @endforeach
    </div>

    @if ($stats['rate'] !== null)
        <p class="mb-6 text-sm text-tide-600">
            Conversion rate <span class="font-semibold text-ink-900">{{ $stats['rate'] }}%</span>
            <span class="text-tide-400">
                &mdash; based on bookings we could match back to a redirect, so treat it as a floor.
            </span>
        </p>
    @endif

    {{-- filter --}}
    <div class="mb-5 flex flex-wrap gap-2">
        @foreach (['all' => 'All', 'redirected' => 'Redirected', 'converted' => 'Converted'] as $value => $label)
            <a href="{{ route('admin.checkouts.index', ['status' => $value]) }}"
               class="rounded-full px-4 py-2 font-mono text-[10px] uppercase tracking-wide transition
                      {{ $status === $value ? 'bg-ink-900 text-white' : 'bg-white text-tide-600 ring-1 ring-fog-300 hover:ring-brand-300' }}">
                {{ $label }}
            </a>
        @endforeach
    </div>

    @if ($intents->isEmpty())
        <div class="rounded-3xl bg-white px-6 py-16 text-center ring-1 ring-black/5">
            <p class="font-display text-lg text-ink-900">No handoffs yet</p>
            <p class="mt-1 text-sm text-tide-600">
                Every &ldquo;Book now&rdquo; click will be recorded here before the guest reaches Lodgify.
            </p>
        </div>
    @else
        <div class="overflow-hidden rounded-3xl bg-white ring-1 ring-black/5">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-fog-200 bg-fog-50">
                        <tr class="font-mono text-[10px] uppercase tracking-wide text-tide-500">
                            <th class="px-5 py-3 font-medium">Cottage</th>
                            <th class="px-5 py-3 font-medium">Stay</th>
                            <th class="px-5 py-3 font-medium">Party</th>
                            <th class="px-5 py-3 font-medium">Quoted</th>
                            <th class="px-5 py-3 font-medium">When</th>
                            <th class="px-5 py-3 font-medium">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-fog-200">
                        @foreach ($intents as $intent)
                            <tr class="transition hover:bg-fog-50">
                                <td class="px-5 py-4">
                                    <span class="block font-medium text-ink-900">
                                        {{ Str::limit($intent->cottage_name, 34) }}
                                    </span>
                                    <span class="mt-0.5 block font-mono text-[10px] text-tide-400">
                                        {{ $intent->reference }}
                                        @if ($intent->addon_count)
                                            · {{ $intent->addon_count }} {{ Str::plural('extra', $intent->addon_count) }}
                                        @endif
                                    </span>
                                </td>
                                <td class="whitespace-nowrap px-5 py-4 text-tide-700">
                                    {{ $intent->stay_label }}
                                    <span class="block text-xs text-tide-500">{{ $intent->nights }} nights</span>
                                </td>
                                <td class="whitespace-nowrap px-5 py-4 text-tide-700">
                                    {{ $intent->adults + $intent->children }} guests
                                    @if ($intent->pets)
                                        <span class="block text-xs text-tide-500">{{ $intent->pets }} pets</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-5 py-4 text-tide-700">
                                    @if ($intent->quoted_total)
                                        {{ $intent->currency === 'CAD' ? 'CA$' : '' }}{{ number_format((float) $intent->quoted_total, 2) }}
                                    @else
                                        <span class="text-tide-400">&mdash;</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-5 py-4 text-tide-600">
                                    {{ $intent->created_at->diffForHumans(short: true) }}
                                    <span class="block text-xs text-tide-400">
                                        {{ $intent->created_at->format('M j, g:ia') }}
                                    </span>
                                </td>
                                <td class="px-5 py-4">
                                    @php
                                        $grace = (int) config('lodgify.checkout_grace_minutes', 90);
                                        $isStale = $intent->status === 'redirected'
                                                   && $intent->created_at->lt(now()->subMinutes($grace));
                                        [$label, $classes] = $intent->status === 'converted'
                                            ? ['Converted', 'bg-emerald-50 text-emerald-800 ring-emerald-200']
                                            : ($isStale
                                                ? ['Abandoned', 'bg-fog-100 text-tide-600 ring-fog-300']
                                                : ['In flight', 'bg-amber-50 text-amber-800 ring-amber-200']);
                                    @endphp
                                    <span class="inline-flex rounded-full px-2.5 py-1 font-mono text-[10px] font-semibold uppercase tracking-wide ring-1 {{ $classes }}">
                                        {{ $label }}
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-5">{{ $intents->links() }}</div>
    @endif

    <p class="mt-6 text-xs leading-relaxed text-tide-500">
        Lodgify owns the reservation itself &mdash; this table only records that a guest was
        handed off, what we quoted, and which extras they chose. Conversions are matched by
        property and dates, so a booking made through another channel for the same dates could
        be attributed here by mistake.
    </p>
</x-admin-layout>
