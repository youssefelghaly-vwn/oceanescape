{{-- resources/views/pages/reservation.blade.php --}}
<x-website-layout :title="'Your stay · ' . ($reservation->propertyName ?: 'Ocean Escape Cottages')">

<section class="border-b border-fog-200 bg-fog-50 pb-10 pt-32">
    <div class="mx-auto max-w-3xl px-6">
        <a href="{{ route('profile.index') }}"
           class="inline-flex items-center gap-1.5 font-mono text-[10px] uppercase tracking-wide text-tide-500 transition hover:text-ink-900">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M15 18l-6-6 6-6"/></svg>
            All my stays
        </a>

        <h1 class="mt-3 font-display text-3xl font-medium leading-tight text-ink-900">
            {{ $reservation->propertyName ?: 'Your cottage' }}
        </h1>
        <p class="mt-2 text-base text-tide-700">{{ $reservation->stayLabel() }}</p>

        <p class="mt-1 font-mono text-[11px] uppercase tracking-wide text-tide-500">
            Booking {{ $reservation->reference() }}
        </p>
    </div>
</section>

<section class="bg-white py-12">
    <div class="mx-auto max-w-3xl space-y-5 px-6">

        <div class="overflow-hidden rounded-3xl bg-fog-50 ring-1 ring-black/5">
            <dl class="divide-y divide-fog-200 text-sm">
                @foreach ([
                    ['Check in',  ($reservation->arrival?->format('D, M j, Y') ?? '—') . ($reservation->checkInLabel() ? ' · from ' . $reservation->checkInLabel() : '')],
                    ['Check out', ($reservation->departure?->format('D, M j, Y') ?? '—') . ($reservation->checkOutLabel() ? ' · by ' . $reservation->checkOutLabel() : '')],
                    ['Nights',    $reservation->nights ?? '—'],
                    ['Guests',    $reservation->partyLabel()],
                ] as [$term, $value])
                    <div class="flex justify-between gap-4 px-6 py-3.5">
                        <dt class="text-tide-600">{{ $term }}</dt>
                        <dd class="text-right font-medium text-ink-900">{{ $value }}</dd>
                    </div>
                @endforeach

                <div class="flex items-baseline justify-between gap-4 bg-white px-6 py-4">
                    <dt class="font-display text-base text-ink-900">Total</dt>
                    <dd class="font-display text-xl font-medium text-brand-700">
                        {{ $reservation->money($reservation->total) }}
                    </dd>
                </div>

                @if (($reservation->amountDue ?? 0) > 0)
                    <div class="flex justify-between gap-4 bg-white px-6 py-3.5">
                        <dt class="text-amber-800">Outstanding balance</dt>
                        <dd class="font-medium text-amber-800">{{ $reservation->money($reservation->amountDue) }}</dd>
                    </div>
                @endif
            </dl>
        </div>

        @if (!empty($reservation->addOns))
            <div class="rounded-3xl bg-fog-50 p-6 ring-1 ring-black/5">
                <h2 class="font-mono text-[10px] uppercase tracking-wide text-tide-500">Extras</h2>
                <ul class="mt-3 divide-y divide-fog-200 text-sm">
                    @foreach ($reservation->addOns as $addon)
                        <li class="flex justify-between gap-4 py-2.5">
                            <span class="text-tide-700">{{ $addon['name'] ?? $addon['description'] ?? 'Extra' }}</span>
                            <span class="font-medium text-ink-900">
                                {{ $reservation->money((float) ($addon['amount'] ?? $addon['subtotal'] ?? 0)) }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if (collect($reservation->policy)->filter()->isNotEmpty())
            <div class="rounded-3xl bg-fog-50 p-6 ring-1 ring-black/5">
                <h2 class="font-mono text-[10px] uppercase tracking-wide text-tide-500">Your terms</h2>
                <dl class="mt-3 space-y-3 text-sm">
                    @foreach ([
                        'payments'       => 'Payments',
                        'cancellation'   => 'Cancellation',
                        'damage_deposit' => 'Security deposit',
                    ] as $key => $label)
                        @if ($reservation->policy[$key])
                            <div>
                                <dt class="font-mono text-[10px] uppercase tracking-wide text-tide-500">{{ $label }}</dt>
                                <dd class="mt-0.5 leading-relaxed text-tide-700">{{ $reservation->policy[$key] }}</dd>
                            </div>
                        @endif
                    @endforeach
                </dl>
            </div>
        @endif

        @if ($reservation->isUpcoming())
            <div class="rounded-3xl bg-brand-50 p-6 ring-1 ring-brand-100">
                <h2 class="font-display text-lg text-ink-900">Getting ready</h2>
                <ul class="mt-3 space-y-2 text-sm text-tide-700">
                    <li class="flex gap-2.5">
                        <span class="mt-1.5 h-1 w-1 shrink-0 rounded-full bg-brand-400"></span>
                        Check-in from {{ $reservation->checkInLabel() ?? '3:00 PM' }};
                        check-out by {{ $reservation->checkOutLabel() ?? '11:00 AM' }}.
                    </li>
                    <li class="flex gap-2.5">
                        <span class="mt-1.5 h-1 w-1 shrink-0 rounded-full bg-brand-400"></span>
                        We&rsquo;re at 1 Gull Rock Road, Lockeport, Nova Scotia.
                    </li>
                    <li class="flex gap-2.5">
                        <span class="mt-1.5 h-1 w-1 shrink-0 rounded-full bg-brand-400"></span>
                        Need to change something? Call
                        <a href="tel:9023981020" class="font-medium text-brand-700 underline">902-398-1020</a>.
                    </li>
                </ul>

                <a href="{{ route('things-to-do') }}"
                   class="mt-5 inline-flex items-center gap-1.5 text-sm font-semibold text-brand-700 transition hover:gap-2.5">
                    Plan what to do while you&rsquo;re here
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                </a>
            </div>
        @endif

        @if ($reservation->isPast() && !$reservation->isCancelled())
            <div class="rounded-3xl bg-fog-50 p-6 text-center ring-1 ring-black/5">
                <h2 class="font-display text-lg text-ink-900">Thanks for staying with us</h2>
                <p class="mx-auto mt-2 max-w-sm text-sm text-tide-600">
                    If you took photos we&rsquo;d love to see them &mdash; and a word on Google means a lot.
                </p>
                <div class="mt-5 flex flex-wrap items-center justify-center gap-3">
                    <a href="{{ route('photos.create') }}"
                       class="rounded-full bg-brand-600 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-brand-700">
                        Share your photos
                    </a>
                    <a href="{{ route('availability.search') }}"
                       class="rounded-full px-5 py-2.5 text-sm font-semibold text-brand-700 ring-1 ring-brand-200 transition hover:bg-brand-50">
                        Book again
                    </a>
                </div>
            </div>
        @endif

        <p class="pt-4 text-xs leading-relaxed text-tide-500">
            Questions about this booking? Email
            <a href="mailto:info@oceanescapecottages.ca?subject={{ rawurlencode('Booking ' . ($reservation->reference())) }}"
               class="font-medium text-brand-700 underline">info@oceanescapecottages.ca</a>
            quoting {{ $reservation->reference() }}.
        </p>
    </div>
</section>

</x-website-layout>