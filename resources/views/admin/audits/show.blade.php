{{-- resources/views/admin/audits/show.blade.php

     One booking's whole trail, OLDEST FIRST — the order it happened in, which is the order
     it has to be read in to explain anything to a guest.

     The index answers "what has been happening?"; this page answers "what happened to this
     booking?", which is the question that actually arrives by phone. Read-only: the rows are
     immutable, and so is the reservation as far as this screen is concerned — Lodgify owns
     that. --}}
<x-admin-layout :title="'Audit · ' . $booking->reference">
    <x-slot:heading>
        <a href="{{ route('admin.audits.index') }}"
           class="inline-flex items-center gap-1.5 font-mono text-[10px] uppercase tracking-wide text-tide-500 transition hover:text-ink-900">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M15 18l-6-6 6-6"/></svg>
            Audit trail
        </a>

        <h1 class="mt-2 font-display text-2xl font-medium">{{ $booking->reference }}</h1>
        <p class="mt-0.5 text-sm text-tide-600">
            {{ $booking->cottage_name }} · {{ $booking->stay_label }} · {{ $booking->party_label }}
        </p>
    </x-slot:heading>

    {{-- ------------------------------------------------------------- summary --}}
    <div class="mb-6 grid gap-4 lg:grid-cols-[1fr_20rem] lg:items-start">
        <div class="rounded-3xl bg-white p-6 ring-1 ring-black/5">
            <div class="flex flex-wrap items-center gap-3">
                <span class="inline-flex rounded-full px-2.5 py-1 font-mono text-[10px] font-semibold uppercase tracking-wide ring-1 {{ $booking->status->classes() }}">
                    {{ $booking->status->label() }}
                </span>

                @if ($booking->status->holdsDates())
                    <span class="font-mono text-[10px] uppercase tracking-wide text-emerald-700">holds the dates</span>
                @else
                    {{-- Said plainly because it is the single most surprising fact in this
                         feature: an `Open` reservation does NOT block the Lodgify calendar. --}}
                    <span class="font-mono text-[10px] uppercase tracking-wide text-amber-700">dates not held</span>
                @endif
            </div>

            <dl class="mt-5 grid gap-4 border-t border-fog-200 pt-5 text-sm sm:grid-cols-2">
                <div>
                    <dt class="font-mono text-[10px] uppercase tracking-wide text-tide-500">Guest</dt>
                    <dd class="mt-1 text-ink-900">{{ $booking->guest_name }}</dd>
                    <dd class="text-xs text-tide-600">{{ $booking->guest_email }}</dd>
                    @if ($booking->guest_phone)
                        <dd class="text-xs text-tide-600">{{ $booking->guest_phone }}</dd>
                    @endif
                </div>

                <div>
                    <dt class="font-mono text-[10px] uppercase tracking-wide text-tide-500">Account</dt>
                    <dd class="mt-1 text-ink-900">
                        {{-- Whether the guest was signed in matters here: it is what decided
                             between paying immediately and waiting for the emailed link. --}}
                        {{ $booking->user?->email ?? 'Not signed in' }}
                    </dd>
                </div>

                <div>
                    <dt class="font-mono text-[10px] uppercase tracking-wide text-tide-500">Lodgify</dt>
                    <dd class="mt-1 font-mono text-xs text-ink-900">
                        {{ $booking->lodgify_booking_id ?? '—' }}
                        @if ($booking->lodgify_status)
                            <span class="text-tide-500">({{ $booking->lodgify_status }})</span>
                        @endif
                    </dd>
                    @if ($booking->lodgify_sync_error)
                        <dd class="mt-1 text-xs text-rose-700">{{ Str::limit($booking->lodgify_sync_error, 140) }}</dd>
                    @endif
                </div>

                <div>
                    <dt class="font-mono text-[10px] uppercase tracking-wide text-tide-500">Booked from</dt>
                    <dd class="mt-1 font-mono text-xs text-tide-700">{{ $booking->ip_address ?? '—' }}</dd>
                    <dd class="text-xs text-tide-500">{{ $booking->created_at->format('M j, Y H:i') }}</dd>
                </div>
            </dl>
        </div>

        {{-- money --}}
        <div class="rounded-3xl bg-white p-6 ring-1 ring-black/5">
            <p class="font-mono text-[10px] uppercase tracking-wide text-tide-500">Money</p>

            <dl class="mt-3 space-y-2 text-sm">
                <div class="flex justify-between gap-4">
                    <dt class="text-tide-600">Total</dt>
                    <dd class="font-medium text-ink-900">{{ $booking->total()->format() }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-tide-600">Paid</dt>
                    <dd class="font-medium text-emerald-700">{{ $booking->amountPaid()->format() }}</dd>
                </div>
                <div class="flex justify-between gap-4 border-t border-fog-200 pt-2">
                    <dt class="text-tide-600">Outstanding</dt>
                    <dd class="font-medium text-ink-900">{{ $booking->amountOutstanding()->format() }}</dd>
                </div>
            </dl>

            <div class="mt-5 space-y-3 border-t border-fog-200 pt-5">
                @forelse ($booking->payments as $payment)
                    <div>
                        <div class="flex items-center justify-between gap-3">
                            <span class="font-mono text-[11px] text-ink-900">{{ $payment->reference }}</span>
                            <span class="inline-flex rounded-full px-2 py-0.5 font-mono text-[9px] font-semibold uppercase tracking-wide ring-1 {{ $payment->status->classes() }}">
                                {{ $payment->status->label() }}
                            </span>
                        </div>
                        <p class="mt-0.5 text-xs text-tide-600">
                            {{ ucfirst($payment->type->value) }} · {{ $payment->amount()->format() }}
                            @if ($payment->amountReceived() && ! $payment->amountMatches())
                                {{-- Never hidden: a mismatch is why a paid booking may not be
                                     confirmed, and it is the first thing to check. --}}
                                <span class="block text-rose-700">
                                    captured {{ $payment->amountReceived()->format() }} — does not match
                                </span>
                            @endif
                        </p>
                        <p class="mt-0.5 font-mono text-[10px] text-tide-400">
                            {{ $payment->link_sent_at ? 'link sent '.$payment->link_send_count.'×' : 'no link emailed (paid direct)' }}
                            @if ($payment->paid_at)
                                · paid {{ $payment->paid_at->format('M j H:i') }}
                            @endif
                        </p>
                    </div>
                @empty
                    <p class="text-xs text-tide-500">No payment rows.</p>
                @endforelse
            </div>
        </div>
    </div>

    {{-- ---------------------------------------------------------- the trail --}}
    <div class="overflow-hidden rounded-3xl bg-white ring-1 ring-black/5">
        <div class="border-b border-fog-200 bg-fog-50 px-5 py-3">
            <p class="font-mono text-[10px] uppercase tracking-wide text-tide-500">
                {{ $entries->count() }} {{ Str::plural('event', $entries->count()) }}, oldest first
            </p>
        </div>

        @if ($entries->isEmpty())
            <p class="px-6 py-12 text-center text-sm text-tide-600">
                No audit rows for this booking. That is unusual — creation alone writes one.
            </p>
        @else
            <ol class="divide-y divide-fog-200">
                @foreach ($entries as $entry)
                    <li class="flex flex-wrap items-start gap-x-4 gap-y-2 px-5 py-4">
                        <span class="w-32 shrink-0 whitespace-nowrap font-mono text-[11px] text-tide-500">
                            {{ $entry->created_at->format('M j H:i:s') }}
                        </span>

                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="inline-flex rounded-full px-2.5 py-1 font-mono text-[10px] font-semibold uppercase tracking-wide ring-1 {{ $entry->badgeClasses() }}">
                                    {{ $entry->event }}
                                </span>

                                @if ($entry->from_status || $entry->to_status)
                                    <span class="font-mono text-[11px] text-tide-600">
                                        {{ $entry->from_status ?? '—' }}
                                        <span class="text-tide-400">&rarr;</span>
                                        {{ $entry->to_status ?? '—' }}
                                    </span>
                                @endif

                                <span class="font-mono text-[10px] uppercase tracking-wide text-tide-400">
                                    {{ $entry->actor_type }}@if ($entry->actor) · {{ $entry->actor->email }} @endif
                                </span>

                                @if ($entry->bookingPayment)
                                    <span class="font-mono text-[10px] text-tide-400">{{ $entry->bookingPayment->reference }}</span>
                                @endif
                            </div>

                            @if ($json = $entry->contextJson())
                                <pre class="mt-2 overflow-x-auto rounded-xl bg-fog-50 p-3 font-mono text-[10px] leading-relaxed text-tide-700">{{ $json }}</pre>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ol>
        @endif
    </div>

    <p class="mt-6 text-xs leading-relaxed text-tide-500">
        Lodgify remains the system of record for the reservation itself; this page is our
        record of the money and of every decision the application made. Nothing here can be
        edited &mdash; audit rows reject updates and deletes at the model.
    </p>
</x-admin-layout>
