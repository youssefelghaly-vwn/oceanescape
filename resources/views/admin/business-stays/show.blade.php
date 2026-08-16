{{-- resources/views/admin/business-stays/show.blade.php --}}
<x-admin-layout :title="$stay->company_name">
    <x-slot:heading>
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <a href="{{ route('admin.business-stays.index') }}"
                   class="inline-flex items-center gap-1.5 font-mono text-[10px] uppercase tracking-wide text-tide-500 hover:text-ink-900">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M15 18l-6-6 6-6"/></svg>
                    All enquiries
                </a>
                <h1 class="mt-1.5 font-display text-2xl font-medium">{{ $stay->company_name }}</h1>
                <p class="mt-0.5 text-sm text-tide-600">
                    <span class="font-mono">{{ $stay->reference }}</span> ·
                    received {{ $stay->created_at->format('M j, Y \a\t g:ia') }}
                </p>
            </div>
            <span class="inline-flex rounded-full px-3 py-1.5 font-mono text-[10px] font-semibold uppercase tracking-wide ring-1 {{ $stay->status->classes() }}">
                {{ $stay->status->label() }}
            </span>
        </div>
    </x-slot:heading>

    <div class="grid gap-5 lg:grid-cols-[1fr_320px]">

        {{-- ------------------------------------------------ details --}}
        <div class="space-y-5">

            <section class="rounded-3xl bg-white p-6 ring-1 ring-black/5">
                <h2 class="font-mono text-[10px] uppercase tracking-wide text-tide-500">The stay</h2>
                <dl class="mt-4 grid gap-4 sm:grid-cols-2">
                    @foreach ([
                        ['Dates',    $stay->stay_label],
                        ['Nights',   $stay->nights ? $stay->nights . ' nights' : '—'],
                        ['Guests',   $stay->guests_count],
                        ['Cottages', $stay->cottages_count],
                        ['Purpose',  $stay->purpose ?: '—'],
                        ['Budget',   $stay->budget_per_night
                                        ? $stay->currency . ' ' . number_format((float) $stay->budget_per_night, 2) . ' / night'
                                        : '—'],
                    ] as [$label, $value])
                        <div>
                            <dt class="font-mono text-[10px] uppercase tracking-wide text-tide-500">{{ $label }}</dt>
                            <dd class="mt-0.5 text-sm text-ink-900">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>

                @if ($stay->estimated_value)
                    <p class="mt-4 border-t border-fog-200 pt-4 text-sm text-tide-600">
                        Indicative value
                        <span class="font-display text-lg text-ink-900">
                            {{ $stay->currency }} {{ number_format($stay->estimated_value, 2) }}
                        </span>
                        <span class="text-xs text-tide-400">(budget × nights × cottages)</span>
                    </p>
                @endif

                <div class="mt-4 flex flex-wrap gap-2">
                    @foreach ([
                        ['Invoice required', $stay->needs_invoice],
                        ['Meeting space',    $stay->needs_meeting_space],
                        ['Bringing pets',    $stay->pets],
                        ['Flexible dates',   $stay->dates_flexible],
                    ] as [$label, $on])
                        @if ($on)
                            <span class="rounded-full bg-brand-50 px-3 py-1 font-mono text-[10px] uppercase tracking-wide text-brand-700 ring-1 ring-brand-100">
                                {{ $label }}
                            </span>
                        @endif
                    @endforeach
                </div>
            </section>

            @if ($stay->message)
                <section class="rounded-3xl bg-white p-6 ring-1 ring-black/5">
                    <h2 class="font-mono text-[10px] uppercase tracking-wide text-tide-500">Their message</h2>
                    <p class="mt-3 whitespace-pre-line text-sm leading-relaxed text-tide-700">{{ $stay->message }}</p>
                </section>
            @endif

            <section class="rounded-3xl bg-white p-6 ring-1 ring-black/5">
                <h2 class="font-mono text-[10px] uppercase tracking-wide text-tide-500">Company</h2>
                <dl class="mt-4 grid gap-4 sm:grid-cols-2">
                    @foreach ([
                        ['Company',  $stay->company_name],
                        ['Industry', $stay->industry ?: '—'],
                        ['Website',  $stay->website ?: '—'],
                        ['Tax / VAT number', $stay->tax_number ?: '—'],
                    ] as [$label, $value])
                        <div>
                            <dt class="font-mono text-[10px] uppercase tracking-wide text-tide-500">{{ $label }}</dt>
                            <dd class="mt-0.5 break-words text-sm text-ink-900">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            </section>
        </div>

        {{-- ------------------------------------------------ sidebar --}}
        <div class="space-y-5">

            <section class="rounded-3xl bg-white p-6 ring-1 ring-black/5">
                <h2 class="font-mono text-[10px] uppercase tracking-wide text-tide-500">Contact</h2>
                <p class="mt-3 font-display text-lg text-ink-900">{{ $stay->contact_name }}</p>
                @if ($stay->job_title)
                    <p class="text-xs text-tide-500">{{ $stay->job_title }}</p>
                @endif

                <div class="mt-4 space-y-2 text-sm">
                    <a href="mailto:{{ $stay->email }}?subject=Your%20enquiry%20{{ $stay->reference }}"
                       class="flex items-center gap-2 text-brand-700 hover:underline">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                            <rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/>
                        </svg>
                        {{ $stay->email }}
                    </a>
                    @if ($stay->phone)
                        <a href="tel:{{ preg_replace('/[^0-9+]/', '', $stay->phone) }}"
                           class="flex items-center gap-2 text-brand-700 hover:underline">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                <path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.5 2.1L8.1 9.9a16 16 0 0 0 6 6l1.3-1.2a2 2 0 0 1 2.1-.5c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.7 2Z"/>
                            </svg>
                            {{ $stay->phone }}
                        </a>
                    @endif
                </div>
            </section>

            <section class="rounded-3xl bg-white p-6 ring-1 ring-black/5">
                <h2 class="font-mono text-[10px] uppercase tracking-wide text-tide-500">Status &amp; notes</h2>

                <form method="POST" action="{{ route('admin.business-stays.update', $stay) }}" class="mt-4">
                    @csrf
                    @method('PATCH')

                    <label class="block font-mono text-[10px] uppercase tracking-wide text-tide-500">Status</label>
                    <select name="status"
                            class="mt-1.5 w-full rounded-xl border-0 bg-fog-50 py-2.5 pl-3 pr-9 text-sm ring-1 ring-fog-300 focus:ring-2 focus:ring-brand-400">
                        @foreach (\App\Enums\BusinessStayStatus::options() as $value => $label)
                            <option value="{{ $value }}" @selected($stay->status->value === $value)>{{ $label }}</option>
                        @endforeach
                    </select>

                    <label class="mt-4 block font-mono text-[10px] uppercase tracking-wide text-tide-500">
                        Internal notes
                    </label>
                    <textarea name="internal_notes" rows="5"
                              placeholder="Quote sent, follow up Monday…"
                              class="mt-1.5 w-full rounded-xl border-0 bg-fog-50 p-3 text-sm ring-1 ring-fog-300 placeholder:text-tide-400 focus:ring-2 focus:ring-brand-400">{{ old('internal_notes', $stay->internal_notes) }}</textarea>
                    <p class="mt-1 text-[10px] text-tide-400">Never shown to the customer.</p>

                    <button type="submit"
                            class="mt-4 w-full rounded-full bg-brand-600 py-2.5 text-sm font-semibold text-white transition hover:bg-brand-700">
                        Save
                    </button>
                </form>
            </section>

            <section class="rounded-3xl bg-white p-6 ring-1 ring-black/5">
                <h2 class="font-mono text-[10px] uppercase tracking-wide text-tide-500">Timeline</h2>
                <ul class="mt-3 space-y-2.5 text-xs">
                    @foreach ([
                        ['Received',  $stay->created_at],
                        ['Contacted', $stay->contacted_at],
                        ['Quoted',    $stay->quoted_at],
                        ['Closed',    $stay->closed_at],
                    ] as [$label, $when])
                        <li class="flex items-center justify-between gap-3">
                            <span class="{{ $when ? 'text-ink-900' : 'text-tide-400' }}">{{ $label }}</span>
                            <span class="{{ $when ? 'text-tide-600' : 'text-tide-300' }}">
                                {{ $when?->format('M j, g:ia') ?? '—' }}
                            </span>
                        </li>
                    @endforeach
                </ul>

                @if ($stay->handler)
                    <p class="mt-4 border-t border-fog-200 pt-3 text-xs text-tide-500">
                        Handled by {{ $stay->handler->name }}
                    </p>
                @endif
            </section>

            <form method="POST" action="{{ route('admin.business-stays.destroy', $stay) }}"
                  onsubmit="return confirm('Archive {{ $stay->reference }}? It stays recoverable in the database.')">
                @csrf
                @method('DELETE')
                <button type="submit"
                        class="w-full rounded-full px-4 py-2.5 text-sm text-tide-500 transition hover:bg-white hover:text-red-700">
                    Archive enquiry
                </button>
            </form>
        </div>
    </div>
</x-admin-layout>