<x-admin-layout :title="$reservation->guestName ?: ('Reservation ' . $reservation->reference())">
    <x-slot:heading>
        <a href="{{ route('admin.reservations.index') }}"
           class="inline-flex items-center gap-1.5 font-mono text-[10px] uppercase tracking-wide text-tide-500 hover:text-ink-900">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M15 18l-6-6 6-6"/></svg>
            All reservations
        </a>

        <div class="mt-1.5 flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="font-display text-2xl font-medium">{{ $reservation->guestName ?: 'Unnamed guest' }}</h1>
                <p class="mt-0.5 text-sm text-tide-600">
                    <span class="font-mono">#{{ $reservation->id }}</span>
                    
                    @if ($reservation->createdAt) · booked {{ $reservation->createdAt->format('M j, Y') }} @endif
                </p>
            </div>
            <span class="inline-flex rounded-full px-3 py-1.5 font-mono text-[10px] font-semibold uppercase tracking-wide ring-1 {{ $reservation->statusClasses() }}">
                {{ $reservation->status ?: 'unknown' }}
            </span>
        </div>
    </x-slot:heading>

    <div class="grid gap-5 lg:grid-cols-[1fr_320px]">

        {{-- ------------------------------------------------ main --}}
        <div class="space-y-5">

            <section class="rounded-3xl bg-white p-6 ring-1 ring-black/5">
                <h2 class="font-mono text-[10px] uppercase tracking-wide text-tide-500">The stay</h2>
                <dl class="mt-4 grid gap-4 sm:grid-cols-2">
                    @foreach ([
                        ['Cottage',   $reservation->propertyName ?: ('#' . $reservation->propertyId)],
                        ['Source',    $reservation->source ?: '—'],
                        ['Check in',  ($reservation->arrival?->format('D, M j, Y') ?? '—') . ($reservation->checkInLabel() ? ' · ' . $reservation->checkInLabel() : '')],
                        ['Check out', ($reservation->departure?->format('D, M j, Y') ?? '—') . ($reservation->checkOutLabel() ? ' · ' . $reservation->checkOutLabel() : '')],
                        ['Nights',    $reservation->nights ?? '—'],
                        ['Guests',    $reservation->partyLabel()],
                    ] as [$term, $value])
                        <div>
                            <dt class="font-mono text-[10px] uppercase tracking-wide text-tide-500">{{ $term }}</dt>
                            <dd class="mt-0.5 text-sm text-ink-900">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            </section>

            @if ($reservation->notes)
                <section class="rounded-3xl bg-white p-6 ring-1 ring-black/5">
                    <h2 class="font-mono text-[10px] uppercase tracking-wide text-tide-500">Guest notes</h2>
                    <p class="mt-3 whitespace-pre-line text-sm leading-relaxed text-tide-700">{{ $reservation->notes }}</p>
                </section>
            @endif

            @if (!empty($reservation->addOns))
                <section class="rounded-3xl bg-white p-6 ring-1 ring-black/5">
                    <h2 class="font-mono text-[10px] uppercase tracking-wide text-tide-500">Extras</h2>
                    <ul class="mt-3 divide-y divide-fog-200 text-sm">
                        @foreach ($reservation->addOns as $addon)
                            <li class="flex justify-between gap-4 py-2.5">
                                <span class="text-tide-700">
                                    {{ $addon['name'] ?? $addon['description'] ?? 'Extra' }}
                                    @if (!empty($addon['quantity']))
                                        <span class="font-mono text-[10px] text-tide-400">× {{ $addon['quantity'] }}</span>
                                    @endif
                                </span>
                                <span class="font-medium text-ink-900">
                                    {{ $reservation->money((float) ($addon['amount'] ?? $addon['subtotal'] ?? 0)) }}
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if (!empty($reservation->payments))
                <section class="rounded-3xl bg-white p-6 ring-1 ring-black/5">
                    <h2 class="font-mono text-[10px] uppercase tracking-wide text-tide-500">Payments</h2>
                    <ul class="mt-3 divide-y divide-fog-200 text-sm">
                        @foreach ($reservation->payments as $payment)
                            <li class="flex items-center justify-between gap-4 py-2.5">
                                <span class="text-tide-700">
                                    {{ $payment['date_due'] ?? $payment['name'] ?? 'Payment' }}
                                    @if (!empty($payment['status']))
                                        <span class="ml-2 rounded-full bg-fog-100 px-2 py-0.5 font-mono text-[9px] uppercase tracking-wide text-tide-600">
                                            {{ $payment['status'] }}
                                        </span>
                                    @endif
                                </span>
                                <span class="font-medium text-ink-900">
                                    {{ $reservation->money((float) ($payment['amount'] ?? 0)) }}
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if ($reservation->subtotalLines())
                <section class="rounded-3xl bg-white p-6 ring-1 ring-black/5">
                    <h2 class="font-mono text-[10px] uppercase tracking-wide text-tide-500">Price breakdown</h2>
                    <ul class="mt-3 divide-y divide-fog-200 text-sm">
                        @foreach ($reservation->subtotalLines() as $line)
                            <li class="flex justify-between gap-4 py-2.5">
                                <span class="text-tide-700">{{ $line['label'] }}</span>
                                <span class="font-medium {{ $line['value'] < 0 ? 'text-emerald-700' : 'text-ink-900' }}">
                                    {{ $reservation->money($line['value']) }}
                                </span>
                            </li>
                        @endforeach
                        <li class="flex justify-between gap-4 pt-3">
                            <span class="font-display text-base text-ink-900">Total</span>
                            <span class="font-display text-base font-medium text-ink-900">
                                {{ $reservation->money($reservation->total) }}
                            </span>
                        </li>
                    </ul>
                </section>
            @endif

            @if (collect($reservation->policy)->filter()->isNotEmpty())
                <section class="rounded-3xl bg-white p-6 ring-1 ring-black/5">
                    <h2 class="font-mono text-[10px] uppercase tracking-wide text-tide-500">
                        Policy{{ $reservation->policy['name'] ? ' · ' . $reservation->policy['name'] : '' }}
                    </h2>
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
                </section>
            @endif

            {{-- ======================================================= OUR SIDE ==
                 Everything above this point is Lodgify's: the reservation as the PMS holds
                 it. What follows is ours — the money, the emails and the trail — joined on
                 bookings.lodgify_booking_id.

                 A reservation with no local row is normal: anything taken by phone, or via
                 Airbnb or Booking.com, exists only in Lodgify. The page says that plainly
                 rather than rendering an empty payments panel that looks like a fault. --}}
            @if ($booking)
                <section class="rounded-3xl bg-white p-6 ring-1 ring-black/5">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h2 class="font-mono text-[10px] uppercase tracking-wide text-tide-500">
                                Payments &middot; taken on this site
                            </h2>
                            <p class="mt-1 font-mono text-xs text-tide-600">{{ $booking->reference }}</p>
                        </div>
                        <span class="inline-flex rounded-full px-2.5 py-1 font-mono text-[10px] font-semibold uppercase tracking-wide ring-1 {{ $booking->status->classes() }}">
                            {{ $booking->status->label() }}
                        </span>
                    </div>

                    {{-- OUR figures, not Lodgify's. They should agree; when they do not,
                         seeing both side by side on one page is the whole point. --}}
                    <dl class="mt-5 grid gap-4 border-t border-fog-200 pt-5 text-sm sm:grid-cols-3">
                        <div>
                            <dt class="font-mono text-[10px] uppercase tracking-wide text-tide-500">Total</dt>
                            <dd class="mt-0.5 font-medium text-ink-900">{{ $booking->total()->format() }}</dd>
                        </div>
                        <div>
                            <dt class="font-mono text-[10px] uppercase tracking-wide text-tide-500">Paid</dt>
                            <dd class="mt-0.5 font-medium text-emerald-700">{{ $booking->amountPaid()->format() }}</dd>
                        </div>
                        <div>
                            <dt class="font-mono text-[10px] uppercase tracking-wide text-tide-500">Outstanding</dt>
                            <dd class="mt-0.5 font-medium {{ $outstanding->isPositive() ? 'text-amber-700' : 'text-tide-500' }}">
                                {{ $outstanding->format() }}
                            </dd>
                        </div>
                    </dl>

                    <ul class="mt-5 divide-y divide-fog-200 border-t border-fog-200">
                        @forelse ($booking->payments as $payment)
                            <li class="py-4">
                                <div class="flex flex-wrap items-center justify-between gap-3">
                                    <span class="text-sm font-medium text-ink-900">
                                        {{ ucfirst($payment->type->value) }} &middot; {{ $payment->amount()->format() }}
                                    </span>
                                    <span class="inline-flex rounded-full px-2.5 py-1 font-mono text-[10px] font-semibold uppercase tracking-wide ring-1 {{ $payment->status->classes() }}">
                                        {{ $payment->status->label() }}
                                    </span>
                                </div>

                                <p class="mt-1 font-mono text-[10px] text-tide-500">
                                    {{ $payment->reference }}
                                    @if ($payment->link_sent_at)
                                        &middot; emailed {{ $payment->link_send_count }}&times;, last {{ $payment->link_sent_at->diffForHumans(short: true) }}
                                    @else
                                        &middot; not emailed yet
                                    @endif
                                    @if ($payment->paid_at)
                                        &middot; paid {{ $payment->paid_at->format('M j, H:i') }}
                                    @elseif ($payment->link_expires_at)
                                        &middot; link {{ $payment->isExpired() ? 'expired' : 'expires' }} {{ $payment->link_expires_at->diffForHumans(short: true) }}
                                    @endif
                                </p>

                                @if ($payment->amountReceived() && ! $payment->amountMatches())
                                    {{-- Never folded away: a mismatch is why a paid booking
                                         may not have been confirmed. --}}
                                    <p class="mt-1 text-xs font-medium text-rose-700">
                                        Stripe captured {{ $payment->amountReceived()->format() }} — does not match what we asked for.
                                    </p>
                                @endif

                                @if ($payment->stripe_payment_intent_id || $payment->stripe_checkout_session_id)
                                    <p class="mt-1 break-all font-mono text-[10px] text-tide-400">
                                        {{ $payment->stripe_payment_intent_id ?: $payment->stripe_checkout_session_id }}
                                    </p>
                                @endif
                            </li>
                        @empty
                            <li class="py-4 text-sm text-tide-600">No payment rows on this booking.</li>
                        @endforelse
                    </ul>

                    {{-- ------------------------------------------- the one action --}}
                    <div class="mt-5 border-t border-fog-200 pt-5">
                        @if ($sendable)
                            <form method="POST" action="{{ route('admin.reservations.payment-link', $reservation->id) }}">
                                @csrf
                                <button type="submit"
                                        class="rounded-full bg-brand-600 px-6 py-2.5 text-sm font-medium text-white transition hover:bg-brand-700">
                                    Email the {{ $sendable->value }} link &mdash;
                                    {{ ($sendable === \App\Enums\PaymentType::Balance ? $booking->balanceAmount() : $booking->depositAmount())->format() }}
                                </button>

                                {{-- Says what pressing it does, because it emails a real
                                     person about real money. --}}
                                <p class="mt-2 text-xs text-tide-600">
                                    Sends {{ $booking->guest_email }} a fresh, signed payment link. Safe to press twice:
                                    the same payment is re-sent, never a second charge.
                                </p>
                            </form>
                        @else
                            <p class="text-sm text-tide-600">
                                Nothing outstanding to chase
                                @if ($booking->status->isTerminal())
                                    &mdash; this booking is {{ strtolower($booking->status->label()) }}.
                                @else
                                    &mdash; the full amount is paid.
                                @endif
                            </p>
                        @endif
                    </div>
                </section>

                {{-- ------------------------------------------------ the trail --}}
                <section class="rounded-3xl bg-white p-6 ring-1 ring-black/5">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h2 class="font-mono text-[10px] uppercase tracking-wide text-tide-500">
                            Audit trail &middot; last {{ $audits->count() }} of {{ number_format($auditTotal) }}
                        </h2>
                        <a href="{{ route('admin.audits.show', $booking) }}"
                           class="font-mono text-[10px] uppercase tracking-wide text-brand-600 hover:text-brand-800">
                            Full trail
                        </a>
                    </div>

                    <ol class="mt-4 divide-y divide-fog-200 border-t border-fog-200">
                        @foreach ($audits as $entry)
                            <li class="flex flex-wrap items-start gap-x-3 gap-y-1 py-3">
                                <span class="w-28 shrink-0 font-mono text-[10px] text-tide-500">
                                    {{ $entry->created_at->format('M j H:i') }}
                                </span>
                                <span class="inline-flex rounded-full px-2 py-0.5 font-mono text-[10px] font-semibold uppercase tracking-wide ring-1 {{ $entry->badgeClasses() }}">
                                    {{ $entry->event }}
                                </span>
                                @if ($entry->from_status || $entry->to_status)
                                    <span class="font-mono text-[10px] text-tide-600">
                                        {{ $entry->from_status ?? '—' }} &rarr; {{ $entry->to_status ?? '—' }}
                                    </span>
                                @endif
                                <span class="font-mono text-[10px] uppercase tracking-wide text-tide-400">{{ $entry->actor_type }}</span>
                            </li>
                        @endforeach
                    </ol>
                </section>
            @else
                <section class="rounded-3xl bg-white p-6 ring-1 ring-black/5">
                    <h2 class="font-mono text-[10px] uppercase tracking-wide text-tide-500">Payments</h2>
                    <p class="mt-3 text-sm text-tide-600">
                        This reservation was not taken through this website, so we hold no payment
                        record for it &mdash; it came in by phone or through another channel, and
                        Lodgify has whatever was collected. There is nothing here to email or refund.
                    </p>
                </section>
            @endif

            {{-- Everything Lodgify sent, including fields the mapper does not yet
                 understand. Better visible than silently dropped. --}}
            <details class="rounded-3xl bg-white p-6 ring-1 ring-black/5">
                <summary class="cursor-pointer font-mono text-[10px] uppercase tracking-wide text-tide-500">
                    Raw Lodgify payload
                </summary>
                <pre class="mt-4 overflow-x-auto rounded-2xl bg-ink-900 p-4 text-[11px] leading-relaxed text-brand-100">{{ json_encode($reservation->raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
            </details>
        </div>

        {{-- ------------------------------------------------ sidebar --}}
        <div class="space-y-5">

            <section class="rounded-3xl bg-white p-6 ring-1 ring-black/5">
                <h2 class="font-mono text-[10px] uppercase tracking-wide text-tide-500">Guest</h2>
                <p class="mt-3 font-display text-lg text-ink-900">{{ $reservation->guestName ?: '—' }}</p>

                <div class="mt-3 space-y-2 text-sm">
                    @if ($reservation->guestEmail)
                        <a href="mailto:{{ $reservation->guestEmail }}?subject={{ rawurlencode('Your stay at Ocean Escape Cottages (#' . $reservation->id . ')') }}"
                           class="flex items-start gap-2 break-all text-brand-700 hover:underline">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="mt-0.5 shrink-0">
                                <rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/>
                            </svg>
                            {{ $reservation->guestEmail }}
                        </a>

                        {{-- Jumping straight to this guest's other stays is the
                             thing you always want next on a phone call. --}}
                        <a href="{{ route('admin.reservations.index', ['email' => $reservation->guestEmail]) }}"
                           class="block pl-6 font-mono text-[10px] uppercase tracking-wide text-tide-500 hover:text-ink-900">
                            All stays by this guest
                        </a>
                    @endif

                    @unless ($reservation->isMatchable())
                        <p class="rounded-xl bg-amber-50 px-3 py-2 text-[11px] leading-relaxed text-amber-900 ring-1 ring-amber-200">
                            No email on this booking, so it cannot appear in a guest account.
                            Add one in Lodgify if the guest asks why they can&rsquo;t see it.
                        </p>
                    @endunless

                    @if ($reservation->guestPhone)
                        <a href="tel:{{ preg_replace('/[^0-9+]/', '', $reservation->guestPhone) }}"
                           class="flex items-center gap-2 text-brand-700 hover:underline">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                <path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.5 2.1L8.1 9.9a16 16 0 0 0 6 6l1.3-1.2a2 2 0 0 1 2.1-.5c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.7 2Z"/>
                            </svg>
                            {{ $reservation->guestPhone }}
                        </a>
                    @endif

                    @if ($reservation->guestCountry)
                        <p class="text-tide-600">{{ $reservation->guestCountry }}</p>
                    @endif
                </div>
            </section>

            <section class="rounded-3xl bg-white p-6 ring-1 ring-black/5">
                <h2 class="font-mono text-[10px] uppercase tracking-wide text-tide-500">Money</h2>
                <dl class="mt-3 space-y-2.5 text-sm">
                    <div class="flex justify-between gap-3">
                        <dt class="text-tide-600">Total</dt>
                        <dd class="font-medium text-ink-900">{{ $reservation->money($reservation->total) }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-tide-600">Paid</dt>
                        <dd class="font-medium text-ink-900">{{ $reservation->money($reservation->amountPaid) }}</dd>
                    </div>
                    <div class="flex justify-between gap-3 border-t border-fog-200 pt-2.5">
                        <dt class="{{ ($reservation->amountDue ?? 0) > 0 ? 'font-medium text-amber-800' : 'text-tide-600' }}">
                            Outstanding
                        </dt>
                        <dd class="font-display text-lg {{ ($reservation->amountDue ?? 0) > 0 ? 'text-amber-800' : 'text-ink-900' }}">
                            {{ $reservation->money($reservation->amountDue) }}
                        </dd>
                    </div>
                </dl>
            </section>

            <div class="rounded-3xl bg-fog-100 p-5 text-xs leading-relaxed text-tide-600 ring-1 ring-black/5">
                Reservations are read from Lodgify and never stored here, so this page always
                reflects Lodgify. To change a booking, edit it in Lodgify &mdash; changes appear
                here within a few minutes, or immediately after a refresh.
            </div>
        </div>
    </div>
</x-admin-layout>