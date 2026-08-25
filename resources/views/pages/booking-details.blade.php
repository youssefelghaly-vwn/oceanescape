{{-- resources/views/pages/booking-details.blade.php

     The guest-details step. Nothing is charged from this page: submitting it creates the
     reservation in Lodgify as `Open` and emails a Stripe payment link.

     The prices shown here come from a LIVE server-side Lodgify quote (see
     BookingController::details), not from the calendar widget — so the figure the guest
     agrees to is the figure they will be charged. --}}
<x-website-layout :title="'Book ' . $cottage->name . ' · Ocean Escape Cottages'">
    <section class="mx-auto max-w-5xl px-6 py-14">

        <a href="{{ route('cottage.show', ['slug' => $cottage->slug, 'arrival' => $arrival, 'departure' => $departure]) }}"
           class="inline-flex items-center gap-1.5 text-sm text-tide-600 hover:text-brand-700">
            <span aria-hidden="true">&larr;</span> Back to {{ $cottage->name }}
        </a>

        <h1 class="mt-4 font-display text-3xl text-ink-900 sm:text-4xl">Almost there</h1>
        <p class="mt-2 text-tide-700">
            We just need a few details. You won't be charged on this page.
        </p>

        @if ($errors->has('booking'))
            <div role="alert" class="mt-6 rounded-2xl border border-rose-200 bg-rose-50 p-5 text-sm text-rose-900">
                {{ $errors->first('booking') }}
            </div>
        @endif

        @if ($priceChanged)
            {{-- Said out loud rather than quietly charging a different number. --}}
            <div role="alert" class="mt-6 rounded-2xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-900">
                <p class="font-medium">The price has changed since you selected these dates.</p>
                <p class="mt-1.5">
                    You were shown {{ $shownTotal->format() }}; the current price for this stay is
                    <strong>{{ $plan->total->format() }}</strong>. The amount below is what you
                    would pay.
                </p>
            </div>
        @endif

        <div class="mt-8 grid gap-8 lg:grid-cols-[1fr_22rem] lg:items-start">

            {{-- ------------------------------------------------------------ form --}}
            <form method="POST" action="{{ route('booking.store') }}" class="order-2 lg:order-1">
                @csrf

                {{-- The stay itself. Re-validated and re-priced server side on submit, so
                     tampering with these changes which stay is requested, never what it
                     costs. --}}
                <input type="hidden" name="slug" value="{{ $cottage->slug }}">
                <input type="hidden" name="arrival" value="{{ $arrival }}">
                <input type="hidden" name="departure" value="{{ $departure }}">
                <input type="hidden" name="adults" value="{{ $adults }}">
                <input type="hidden" name="children" value="{{ $children }}">
                <input type="hidden" name="pets" value="{{ $pets }}">

                {{-- Honeypot: invisible to people, filled by bots. Matches the other
                     public forms in this codebase. --}}
                <div class="hidden" aria-hidden="true">
                    <label for="website_url">Website</label>
                    <input type="text" name="website_url" id="website_url" tabindex="-1" autocomplete="off">
                </div>

                <fieldset class="rounded-2xl border border-fog-200 bg-white p-6">
                    <legend class="px-2 font-display text-lg">Who's staying?</legend>

                    <div class="mt-4 grid gap-5 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <label for="guest_name" class="block text-sm font-medium text-ink-900">
                                Full name <span class="text-rose-600" aria-hidden="true">*</span>
                            </label>
                            <input type="text" name="guest_name" id="guest_name" required
                                   autocomplete="name" maxlength="120"
                                   value="{{ old('guest_name', $user?->name) }}"
                                   @class([
                                       'mt-1.5 w-full rounded-xl border px-3.5 py-2.5 text-sm',
                                       'border-rose-300 bg-rose-50' => $errors->has('guest_name'),
                                       'border-fog-300' => ! $errors->has('guest_name'),
                                   ])>
                            @error('guest_name')
                                <p class="mt-1.5 text-sm text-rose-700">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="guest_email" class="block text-sm font-medium text-ink-900">
                                Email <span class="text-rose-600" aria-hidden="true">*</span>
                            </label>
                            <input type="email" name="guest_email" id="guest_email" required
                                   autocomplete="email" maxlength="180"
                                   value="{{ old('guest_email', $user?->email) }}"
                                   @class([
                                       'mt-1.5 w-full rounded-xl border px-3.5 py-2.5 text-sm',
                                       'border-rose-300 bg-rose-50' => $errors->has('guest_email'),
                                       'border-fog-300' => ! $errors->has('guest_email'),
                                   ])>
                            {{-- Stated because a typo here means the payment link never
                                 arrives, and the guest is the only one who can catch it. --}}
                            <p class="mt-1.5 text-xs text-tide-600">
                                Your payment link goes here — please double-check it.
                            </p>
                            @error('guest_email')
                                <p class="mt-1.5 text-sm text-rose-700">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="guest_phone" class="block text-sm font-medium text-ink-900">
                                Phone <span class="text-rose-600" aria-hidden="true">*</span>
                            </label>
                            <input type="tel" name="guest_phone" id="guest_phone" required
                                   autocomplete="tel" maxlength="40"
                                   value="{{ old('guest_phone') }}"
                                   @class([
                                       'mt-1.5 w-full rounded-xl border px-3.5 py-2.5 text-sm',
                                       'border-rose-300 bg-rose-50' => $errors->has('guest_phone'),
                                       'border-fog-300' => ! $errors->has('guest_phone'),
                                   ])>
                            @error('guest_phone')
                                <p class="mt-1.5 text-sm text-rose-700">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="sm:col-span-2">
                            <label for="guest_notes" class="block text-sm font-medium text-ink-900">
                                Anything we should know? <span class="text-tide-500">(optional)</span>
                            </label>
                            <textarea name="guest_notes" id="guest_notes" rows="3" maxlength="1000"
                                      class="mt-1.5 w-full rounded-xl border border-fog-300 px-3.5 py-2.5 text-sm"
                                      placeholder="Arrival time, accessibility needs, occasion…">{{ old('guest_notes') }}</textarea>
                        </div>
                    </div>
                </fieldset>

                <div class="mt-5 rounded-2xl border border-fog-200 bg-white p-6">
                    <label class="flex items-start gap-3 text-sm">
                        <input type="checkbox" name="terms_accepted" value="1" required
                               @checked(old('terms_accepted'))
                               class="mt-0.5 h-4 w-4 rounded border-fog-400">
                        <span class="text-tide-700">
                            I've read and accept the
                            <a href="{{ route('privacy') }}" class="underline">booking terms and privacy policy</a>,
                            and I understand my dates are confirmed once the
                            {{ $plan->singlePayment ? 'payment' : 'deposit' }} is paid.
                        </span>
                    </label>
                    @error('terms_accepted')
                        <p class="mt-2 text-sm text-rose-700">{{ $message }}</p>
                    @enderror
                </div>

                <button type="submit"
                        class="mt-6 w-full rounded-full bg-brand-600 px-6 py-3.5 text-sm font-medium text-white transition hover:bg-brand-700 sm:w-auto sm:px-10">
                    Reserve &amp; send me the payment link
                </button>

                <p class="mt-3 text-xs text-tide-600">
                    No card details are needed yet, and nothing is charged on this page.
                </p>
            </form>

            {{-- --------------------------------------------------------- summary --}}
            <aside class="order-1 lg:order-2 lg:sticky lg:top-24">
                <div class="rounded-2xl border border-fog-200 bg-white p-6">
                    <p class="font-display text-lg">{{ $cottage->name }}</p>
                    <p class="mt-0.5 text-sm text-tide-600">{{ $cottage->locationLine() }}</p>

                    <dl class="mt-5 space-y-2.5 border-t border-fog-200 pt-5 text-sm">
                        <div class="flex justify-between gap-4">
                            <dt class="text-tide-600">Check in</dt>
                            <dd>{{ \Illuminate\Support\Carbon::parse($arrival)->format('D j M Y') }}</dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-tide-600">Check out</dt>
                            <dd>{{ \Illuminate\Support\Carbon::parse($departure)->format('D j M Y') }}</dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-tide-600">Nights</dt>
                            <dd>{{ $nights }}</dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-tide-600">Guests</dt>
                            <dd>
                                {{ $adults }} {{ Str::plural('adult', $adults) }}@if ($children), {{ $children }} {{ Str::plural('child', $children) }}@endif @if ($pets), {{ $pets }} {{ Str::plural('pet', $pets) }}@endif
                            </dd>
                        </div>
                    </dl>

                    <dl class="mt-5 space-y-2.5 border-t border-fog-200 pt-5 text-sm">
                        @foreach ($quote['fees'] ?? [] as $fee)
                            <div class="flex justify-between gap-4">
                                <dt class="text-tide-600">{{ $fee['name'] ?? 'Fee' }}</dt>
                                <dd>{{ number_format((float) ($fee['value'] ?? 0), 2) }}</dd>
                            </div>
                        @endforeach
                        @foreach ($quote['taxes'] ?? [] as $tax)
                            <div class="flex justify-between gap-4">
                                <dt class="text-tide-600">{{ $tax['name'] ?? 'Tax' }}</dt>
                                <dd>{{ number_format((float) ($tax['value'] ?? 0), 2) }}</dd>
                            </div>
                        @endforeach

                        <div class="flex justify-between gap-4 border-t border-fog-200 pt-3 text-base">
                            <dt class="font-medium">Total</dt>
                            <dd class="font-medium">{{ $plan->total->format() }}</dd>
                        </div>
                    </dl>

                    {{-- The part that actually matters to the guest right now. --}}
                    <div class="mt-5 rounded-xl bg-brand-50 p-4 text-sm">
                        <div class="flex justify-between gap-4">
                            <span class="font-medium text-brand-900">
                                {{ $plan->singlePayment ? 'Due now' : 'Deposit due now' }}
                            </span>
                            <span class="font-medium text-brand-900">{{ $plan->firstPaymentAmount()->format() }}</span>
                        </div>
                        @unless ($plan->singlePayment)
                            <div class="mt-2 flex justify-between gap-4 text-brand-800">
                                <span>Balance, {{ config('booking.balance_lead_days') }} days before arrival</span>
                                <span>{{ $plan->balance->format() }}</span>
                            </div>
                        @endunless
                    </div>

                    @if (filled($quote['cancellation_policy'] ?? null))
                        <details class="mt-4 text-sm">
                            <summary class="cursor-pointer text-tide-700">Cancellation policy</summary>
                            <p class="mt-2 text-tide-600">{{ $quote['cancellation_policy'] }}</p>
                        </details>
                    @endif

                    <p class="mt-4 text-xs text-tide-600">
                        Payments are handled securely by Stripe. We never see your card details.
                    </p>
                </div>
            </aside>
        </div>
    </section>
</x-website-layout>
