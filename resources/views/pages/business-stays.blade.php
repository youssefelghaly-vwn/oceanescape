{{-- resources/views/pages/business-stays.blade.php --}}
<x-website-layout title="Business & Group Stays | Ocean Escape Cottages">

    <section class="border-b border-fog-200 bg-fog-50 pb-10 pt-32">
        <div class="mx-auto max-w-3xl px-6">
            <p class="font-mono text-[11px] uppercase tracking-[0.2em] text-brand-600">
                For teams &amp; groups
            </p>
            <h1 class="mt-3 font-display text-3xl font-medium leading-tight text-ink-900 sm:text-4xl">
                Business &amp; group stays
            </h1>
            <p class="mt-4 max-w-xl text-base leading-relaxed text-tide-700">
                Booking several cottages for a retreat, a working crew, or a family gathering?
                Tell us what you need and we&rsquo;ll put together a quote &mdash; usually within one
                business day.
            </p>

            <ul class="mt-6 grid gap-2 sm:grid-cols-3">
                @foreach ([
                    'Up to six cottages, ~36 guests',
                    'Invoicing and PO numbers',
                    'Flexible dates welcome',
                ] as $point)
                    <li class="flex items-start gap-2 text-sm text-tide-700">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="mt-1 shrink-0 text-brand-500">
                            <path d="M20 6 9 17l-5-5"/>
                        </svg>
                        {{ $point }}
                    </li>
                @endforeach
            </ul>
        </div>
    </section>

    <section class="bg-white py-12">
        <div class="mx-auto max-w-3xl px-6">

            @if ($errors->any())
                <div class="mb-6 rounded-2xl bg-red-50 px-5 py-4 text-sm text-red-900 ring-1 ring-red-200">
                    <p class="font-medium">Please check the highlighted fields.</p>
                    <ul class="mt-2 list-disc space-y-1 pl-5 text-[13px]">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ route('business-stays.store') }}" x-data="{ flexible: {{ old('dates_flexible') ? 'true' : 'false' }} }">
                @csrf

                {{-- Honeypot: hidden from people, irresistible to bots. --}}
                <div class="absolute left-[-9999px]" aria-hidden="true">
                    <label>Company website URL
                        <input type="text" name="company_website_url" tabindex="-1" autocomplete="off">
                    </label>
                </div>

                @php
                    $field = 'w-full rounded-xl border-0 bg-fog-50 px-3.5 py-3 text-sm text-ink-900 ring-1 ring-fog-300 placeholder:text-tide-400 focus:ring-2 focus:ring-brand-500';
                    $label = 'block font-mono text-[10px] uppercase tracking-wide text-tide-500';
                @endphp

                {{-- ------------------------------------------- company --}}
                <fieldset class="rounded-3xl bg-fog-50/60 p-6 ring-1 ring-fog-200">
                    <legend class="px-2 font-display text-lg text-ink-900">Your organisation</legend>

                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <label for="company_name" class="{{ $label }}">Company name *</label>
                            <input id="company_name" name="company_name" required value="{{ old('company_name') }}"
                                   class="mt-1.5 {{ $field }} @error('company_name') ring-red-400 @enderror">
                            @error('company_name') <p class="mt-1 text-xs text-red-700">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="industry" class="{{ $label }}">Industry</label>
                            <input id="industry" name="industry" value="{{ old('industry') }}"
                                   placeholder="Film production, tech, education…" class="mt-1.5 {{ $field }}">
                        </div>

                        <div>
                            <label for="website" class="{{ $label }}">Website</label>
                            <input id="website" name="website" value="{{ old('website') }}"
                                   placeholder="example.com" class="mt-1.5 {{ $field }}">
                        </div>

                        <div class="sm:col-span-2">
                            <label for="tax_number" class="{{ $label }}">Tax / VAT number</label>
                            <input id="tax_number" name="tax_number" value="{{ old('tax_number') }}"
                                   class="mt-1.5 {{ $field }}">
                            <p class="mt-1 text-[11px] text-tide-500">Only if you need it on the invoice.</p>
                        </div>
                    </div>
                </fieldset>

                {{-- ------------------------------------------- contact --}}
                <fieldset class="mt-5 rounded-3xl bg-fog-50/60 p-6 ring-1 ring-fog-200">
                    <legend class="px-2 font-display text-lg text-ink-900">Who should we reply to?</legend>

                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="contact_name" class="{{ $label }}">Your name *</label>
                            <input id="contact_name" name="contact_name" required value="{{ old('contact_name') }}"
                                   class="mt-1.5 {{ $field }} @error('contact_name') ring-red-400 @enderror">
                            @error('contact_name') <p class="mt-1 text-xs text-red-700">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="job_title" class="{{ $label }}">Job title</label>
                            <input id="job_title" name="job_title" value="{{ old('job_title') }}" class="mt-1.5 {{ $field }}">
                        </div>

                        <div>
                            <label for="email" class="{{ $label }}">Email *</label>
                            <input id="email" name="email" type="email" required value="{{ old('email') }}"
                                   class="mt-1.5 {{ $field }} @error('email') ring-red-400 @enderror">
                            @error('email') <p class="mt-1 text-xs text-red-700">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="phone" class="{{ $label }}">Phone</label>
                            <input id="phone" name="phone" type="tel" value="{{ old('phone') }}" class="mt-1.5 {{ $field }}">
                        </div>
                    </div>
                </fieldset>

                {{-- ---------------------------------------------- stay --}}
                <fieldset class="mt-5 rounded-3xl bg-fog-50/60 p-6 ring-1 ring-fog-200">
                    <legend class="px-2 font-display text-lg text-ink-900">The stay</legend>

                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="check_in" class="{{ $label }}">Check-in</label>
                            <input id="check_in" name="check_in" type="date" value="{{ old('check_in') }}"
                                   min="{{ now()->toDateString() }}"
                                   class="mt-1.5 {{ $field }} @error('check_in') ring-red-400 @enderror">
                            @error('check_in') <p class="mt-1 text-xs text-red-700">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="check_out" class="{{ $label }}">Check-out</label>
                            <input id="check_out" name="check_out" type="date" value="{{ old('check_out') }}"
                                   min="{{ now()->addDay()->toDateString() }}"
                                   class="mt-1.5 {{ $field }} @error('check_out') ring-red-400 @enderror">
                            @error('check_out') <p class="mt-1 text-xs text-red-700">{{ $message }}</p> @enderror
                        </div>

                        <div class="sm:col-span-2">
                            <label class="inline-flex items-center gap-2.5 text-sm text-tide-700">
                                <input type="checkbox" name="dates_flexible" value="1" x-model="flexible"
                                       class="rounded border-fog-300 text-brand-600 focus:ring-brand-400">
                                Our dates are flexible
                            </label>

                            <div x-show="flexible" x-cloak class="mt-3">
                                <label for="flexible_note" class="{{ $label }}">Roughly when?</label>
                                <input id="flexible_note" name="flexible_note" value="{{ old('flexible_note') }}"
                                       placeholder="Any week in October, ideally midweek"
                                       class="mt-1.5 {{ $field }}">
                            </div>
                        </div>

                        <div>
                            <label for="guests_count" class="{{ $label }}">Number of people *</label>
                            <input id="guests_count" name="guests_count" type="number" min="1" max="200" required
                                   value="{{ old('guests_count') }}"
                                   class="mt-1.5 {{ $field }} @error('guests_count') ring-red-400 @enderror">
                            @error('guests_count') <p class="mt-1 text-xs text-red-700">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="cottages_count" class="{{ $label }}">Cottages needed *</label>
                            <input id="cottages_count" name="cottages_count" type="number" min="1" max="20" required
                                   value="{{ old('cottages_count') }}"
                                   class="mt-1.5 {{ $field }} @error('cottages_count') ring-red-400 @enderror">
                            @error('cottages_count')
                                <p class="mt-1 text-xs text-red-700">{{ $message }}</p>
                            @else
                                <p class="mt-1 text-[11px] text-tide-500">We have six; each sleeps up to six.</p>
                            @enderror
                        </div>

                        <div>
                            <label for="purpose" class="{{ $label }}">Purpose of stay</label>
                            <input id="purpose" name="purpose" list="purposes" value="{{ old('purpose') }}"
                                   placeholder="Team retreat" class="mt-1.5 {{ $field }}">
                            <datalist id="purposes">
                                @foreach (['Team retreat', 'Conference', 'Film or production crew', 'Relocation', 'Training', 'Family gathering'] as $p)
                                    <option value="{{ $p }}"></option>
                                @endforeach
                            </datalist>
                        </div>

                        <div>
                            <label for="budget_per_night" class="{{ $label }}">Budget per night</label>
                            <div class="mt-1.5 flex gap-2">
                                <input id="budget_per_night" name="budget_per_night" type="number" step="0.01" min="0"
                                       value="{{ old('budget_per_night') }}" placeholder="Optional"
                                       class="{{ $field }}">
                                <select name="currency" class="rounded-xl border-0 bg-fog-50 py-3 pl-3 pr-8 text-sm ring-1 ring-fog-300 focus:ring-2 focus:ring-brand-500">
                                    @foreach (['CAD', 'USD', 'EUR', 'GBP'] as $c)
                                        <option value="{{ $c }}" @selected(old('currency', 'CAD') === $c)>{{ $c }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div class="sm:col-span-2">
                            <p class="{{ $label }}">Anything else we should set up?</p>
                            <div class="mt-2 flex flex-wrap gap-x-6 gap-y-2.5">
                                @foreach ([
                                    ['needs_invoice', 'Invoice / PO required'],
                                    ['needs_meeting_space', 'Somewhere to meet or work'],
                                    ['pets', 'Bringing pets'],
                                ] as [$name, $text])
                                    <label class="inline-flex items-center gap-2.5 text-sm text-tide-700">
                                        <input type="checkbox" name="{{ $name }}" value="1" @checked(old($name))
                                               class="rounded border-fog-300 text-brand-600 focus:ring-brand-400">
                                        {{ $text }}
                                    </label>
                                @endforeach
                            </div>
                        </div>

                        <div class="sm:col-span-2">
                            <label for="message" class="{{ $label }}">Anything else</label>
                            <textarea id="message" name="message" rows="4"
                                      placeholder="Arrival times, accessibility needs, catering…"
                                      class="mt-1.5 {{ $field }}">{{ old('message') }}</textarea>
                        </div>
                    </div>
                </fieldset>

                <div class="mt-6 flex flex-wrap items-center gap-4">
                    <button type="submit"
                            class="rounded-full bg-brand-600 px-8 py-3.5 text-sm font-semibold text-white transition hover:bg-brand-700">
                        Send enquiry
                    </button>
                    <p class="text-xs text-tide-500">
                        No payment now &mdash; we&rsquo;ll reply with a quote first.
                    </p>
                </div>
            </form>
        </div>
    </section>
</x-website-layout>