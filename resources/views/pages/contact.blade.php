{{-- resources/views/pages/contact.blade.php --}}
<x-website-layout title="Contact | Ocean Escape Cottages">

    <section class="border-b border-fog-200 bg-fog-50 pb-10 pt-32">
        <div class="mx-auto max-w-5xl px-6 lg:px-8">
            <p class="font-mono text-[11px] uppercase tracking-[0.2em] text-brand-600">Get in touch</p>
            <h1 class="mt-3 font-display text-3xl font-medium leading-tight text-ink-900 sm:text-4xl">
                We&rsquo;d love to hear from you
            </h1>
            <p class="mt-4 max-w-xl text-base leading-relaxed text-tide-700">
                Questions about a cottage, your dates, or the area? Send us a note and we&rsquo;ll
                reply within one business day.
            </p>
        </div>
    </section>

    <section class="bg-white py-12">
        <div class="mx-auto grid max-w-5xl gap-10 px-6 lg:grid-cols-[1fr_320px] lg:gap-14 lg:px-8">

            {{-- form --}}
            <div>
                @if (session('contact_sent'))
                    <div class="mb-8 rounded-3xl bg-brand-50 p-6 ring-1 ring-brand-200">
                        <div class="flex items-start gap-3">
                            <span class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-brand-600 text-white">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M20 6 9 17l-5-5"/></svg>
                            </span>
                            <div>
                                <p class="font-display text-lg text-ink-900">Message sent</p>
                                <p class="mt-1 text-sm text-tide-700">
                                    Your reference is
                                    <span class="font-mono font-semibold">{{ session('contact_sent') }}</span>.
                                    We&rsquo;ll reply within one business day.
                                </p>
                            </div>
                        </div>
                    </div>
                @endif

                @if ($errors->any())
                    <div class="mb-6 rounded-2xl bg-red-50 px-5 py-4 text-sm text-red-900 ring-1 ring-red-200">
                        <p class="font-medium">Please check the highlighted fields.</p>
                        <ul class="mt-2 list-disc space-y-1 pl-5 text-[13px]">
                            @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                        </ul>
                    </div>
                @endif

                @php
                    $field = 'w-full rounded-xl border-0 bg-fog-50 px-3.5 py-3 text-sm text-ink-900 ring-1 ring-fog-300 placeholder:text-tide-400 focus:ring-2 focus:ring-brand-500';
                    $label = 'block font-mono text-[10px] uppercase tracking-wide text-tide-500';
                @endphp

                <form method="POST" action="{{ route('contact.store') }}" class="space-y-5">
                    @csrf

                    <div class="absolute left-[-9999px]" aria-hidden="true">
                        <label>Website<input type="text" name="website_url" tabindex="-1" autocomplete="off"></label>
                    </div>

                    <div class="grid gap-5 sm:grid-cols-2">
                        <div>
                            <label for="name" class="{{ $label }}">Your name *</label>
                            <input id="name" name="name" required value="{{ old('name') }}"
                                   class="mt-1.5 {{ $field }} @error('name') ring-red-400 @enderror">
                            @error('name')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="email" class="{{ $label }}">Email *</label>
                            <input id="email" name="email" type="email" required value="{{ old('email') }}"
                                   class="mt-1.5 {{ $field }} @error('email') ring-red-400 @enderror">
                            @error('email')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="phone" class="{{ $label }}">Phone</label>
                            <input id="phone" name="phone" type="tel" value="{{ old('phone') }}" class="mt-1.5 {{ $field }}">
                        </div>
                        <div>
                            <label for="subject" class="{{ $label }}">Subject</label>
                            <input id="subject" name="subject" value="{{ old('subject') }}"
                                   placeholder="Availability, directions, a question…" class="mt-1.5 {{ $field }}">
                        </div>
                    </div>

                    <div>
                        <label for="message" class="{{ $label }}">Message *</label>
                        <textarea id="message" name="message" rows="6" required
                                  class="mt-1.5 {{ $field }} @error('message') ring-red-400 @enderror">{{ old('message') }}</textarea>
                        @error('message')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
                    </div>

                    <button type="submit"
                            class="rounded-full bg-brand-600 px-8 py-3.5 text-sm font-semibold text-white transition hover:bg-brand-700">
                        Send message
                    </button>
                </form>
            </div>

            {{-- aside --}}
            <aside class="space-y-5">
                <div class="rounded-3xl bg-fog-50 p-6 ring-1 ring-fog-200">
                    <h2 class="font-mono text-[10px] uppercase tracking-wide text-tide-500">Reach us directly</h2>
                    <ul class="mt-4 space-y-3 text-sm">
                        <li>
                            <a href="tel:19023981020" class="flex items-center gap-2.5 text-brand-700 hover:underline">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                    <path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.5 2.1L8.1 9.9a16 16 0 0 0 6 6l1.3-1.2a2 2 0 0 1 2.1-.5c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.7 2Z"/>
                                </svg>
                                902-398-1020
                            </a>
                        </li>
                        <li>
                            <a href="mailto:info@oceanescapecottages.ca" class="flex items-center gap-2.5 break-all text-brand-700 hover:underline">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="shrink-0">
                                    <rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/>
                                </svg>
                                info@oceanescapecottages.ca
                            </a>
                        </li>
                    </ul>

                    <address class="mt-5 border-t border-fog-200 pt-5 not-italic text-sm leading-relaxed text-tide-700">
                        1 Gull Rock Road<br>
                        Lockeport, Nova Scotia<br>
                        Canada B0T 1L0
                    </address>
                </div>

                <div class="rounded-3xl bg-brand-50 p-6 ring-1 ring-brand-100">
                    <h2 class="font-display text-lg text-ink-900">Booking for a group?</h2>
                    <p class="mt-2 text-sm leading-relaxed text-tide-700">
                        Several cottages for a retreat or a crew? There&rsquo;s a form built for that.
                    </p>
                    <a href="{{ route('business-stays.create') }}"
                       class="mt-4 inline-flex items-center gap-1.5 text-sm font-semibold text-brand-700 transition hover:gap-2.5">
                        Business &amp; group stays
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                    </a>
                </div>
            </aside>
        </div>
    </section>
</x-website-layout>