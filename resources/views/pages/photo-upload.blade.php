{{-- resources/views/pages/photo-upload.blade.php --}}
<x-website-layout title="Share Your Photos | Ocean Escape Cottages">

    <section class="border-b border-fog-200 bg-fog-50 pb-10 pt-32">
        <div class="mx-auto max-w-3xl px-6">
            <p class="font-mono text-[11px] uppercase tracking-[0.2em] text-brand-600">Guest gallery</p>
            <h1 class="mt-3 font-display text-3xl font-medium leading-tight text-ink-900 sm:text-4xl">
                Share your photos
            </h1>
            <p class="mt-4 text-base leading-relaxed text-tide-700">
                Caught a sunrise over the water, or a perfect evening on the deck? We&rsquo;d love to
                show it. Every photo is reviewed by us before it appears on the site.
            </p>
        </div>
    </section>

    <section class="bg-white py-12">
        <div class="mx-auto max-w-3xl px-6">

            @if (session('photos_uploaded'))
                <div class="mb-8 rounded-3xl bg-brand-50 p-6 ring-1 ring-brand-200">
                    <div class="flex items-start gap-3">
                        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-brand-600 text-white">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M20 6 9 17l-5-5"/></svg>
                        </span>
                        <div>
                            <p class="font-display text-lg text-ink-900">
                                Thank you — {{ session('photos_uploaded') }}
                                {{ Str::plural('photo', session('photos_uploaded')) }} received
                            </p>
                            <p class="mt-1 text-sm text-tide-700">
                                We&rsquo;ll take a look and publish them to the gallery shortly.
                            </p>
                        </div>
                    </div>
                </div>
            @endif

            @if ($errors->any())
                <div class="mb-6 rounded-2xl bg-red-50 px-5 py-4 text-sm text-red-900 ring-1 ring-red-200">
                    <p class="font-medium">Please check the following.</p>
                    <ul class="mt-2 list-disc space-y-1 pl-5 text-[13px]">
                        @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                    </ul>
                </div>
            @endif

            @php
                $field = 'w-full rounded-xl border-0 bg-fog-50 px-3.5 py-3 text-sm text-ink-900 ring-1 ring-fog-300 placeholder:text-tide-400 focus:ring-2 focus:ring-brand-500';
                $label = 'block font-mono text-[10px] uppercase tracking-wide text-tide-500';
            @endphp

            <form method="POST" action="{{ route('photos.store') }}" enctype="multipart/form-data"
                  x-data="{
                      files: [],
                      pick(event) {
                          this.files = Array.from(event.target.files).map(f => ({
                              name: f.name,
                              size: (f.size / 1024 / 1024).toFixed(1) + ' MB',
                              url: URL.createObjectURL(f),
                          }));
                      }
                  }"
                  class="space-y-6">
                @csrf

                <div class="absolute left-[-9999px]" aria-hidden="true">
                    <label>Website<input type="text" name="website_url" tabindex="-1" autocomplete="off"></label>
                </div>

                {{-- dropzone --}}
                <div>
                    <label for="photos"
                           class="flex cursor-pointer flex-col items-center justify-center rounded-3xl border-2 border-dashed border-fog-300 bg-fog-50 px-6 py-12 text-center transition hover:border-brand-400 hover:bg-brand-50/40">
                        <span class="grid h-12 w-12 place-items-center rounded-full bg-white text-brand-600 ring-1 ring-fog-200">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/>
                            </svg>
                        </span>
                        <span class="mt-4 font-display text-lg text-ink-900">Choose your photos</span>
                        <span class="mt-1 text-sm text-tide-600">JPG, PNG, WEBP or HEIC · up to 12 MB each · 10 at a time</span>
                        <input id="photos" name="photos[]" type="file" multiple required
                               accept="image/jpeg,image/png,image/webp,image/heic"
                               @change="pick($event)" class="sr-only">
                    </label>

                    <div x-show="files.length" x-cloak class="mt-4 grid grid-cols-3 gap-3 sm:grid-cols-4">
                        <template x-for="file in files" :key="file.name">
                            <div class="overflow-hidden rounded-xl ring-1 ring-fog-200">
                                <img :src="file.url" :alt="file.name" class="aspect-square w-full object-cover">
                                <p class="truncate px-2 py-1.5 font-mono text-[9px] text-tide-500" x-text="file.size"></p>
                            </div>
                        </template>
                    </div>
                </div>

                <div class="grid gap-5 sm:grid-cols-2">
                    <div>
                        <label for="guest_name" class="{{ $label }}">Your name *</label>
                        <input id="guest_name" name="guest_name" required value="{{ old('guest_name') }}"
                               class="mt-1.5 {{ $field }}">
                        <p class="mt-1 text-[11px] text-tide-500">We credit photos by first name only.</p>
                    </div>
                    <div>
                        <label for="guest_email" class="{{ $label }}">Email *</label>
                        <input id="guest_email" name="guest_email" type="email" required value="{{ old('guest_email') }}"
                               class="mt-1.5 {{ $field }}">
                        <p class="mt-1 text-[11px] text-tide-500">Never published — just so we can reach you.</p>
                    </div>

                    <div>
                        <label for="cottage_id" class="{{ $label }}">Which cottage?</label>
                        <select id="cottage_id" name="cottage_id" class="mt-1.5 {{ $field }}">
                            <option value="">Not sure / general</option>
                            @foreach ($cottages as $cottage)
                                <option value="{{ $cottage->id }}" @selected(old('cottage_id') == $cottage->id)>
                                    {{ $cottage->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label for="stayed_on" class="{{ $label }}">When did you stay?</label>
                        <input id="stayed_on" name="stayed_on" type="date" max="{{ now()->toDateString() }}"
                               value="{{ old('stayed_on') }}" class="mt-1.5 {{ $field }}">
                    </div>
                </div>

                <div>
                    <label for="caption" class="{{ $label }}">Caption</label>
                    <input id="caption" name="caption" maxlength="300" value="{{ old('caption') }}"
                           placeholder="Sunrise from the deck, October morning"
                           class="mt-1.5 {{ $field }}">
                </div>

                <label class="flex items-start gap-3 rounded-2xl bg-fog-50 p-4 text-sm text-tide-700 ring-1 ring-fog-200">
                    <input type="checkbox" name="consent_given" value="1" required @checked(old('consent_given'))
                           class="mt-0.5 rounded border-fog-300 text-brand-600 focus:ring-brand-400">
                    <span>
                        I took these photos and I&rsquo;m happy for Ocean Escape Cottages to publish them
                        on the website and social media, credited by my first name. *
                    </span>
                </label>

                <div class="flex flex-wrap items-center gap-4">
                    <button type="submit"
                            class="rounded-full bg-brand-600 px-8 py-3.5 text-sm font-semibold text-white transition hover:bg-brand-700">
                        Upload photos
                    </button>
                    <p class="text-xs text-tide-500">Reviewed before publishing — usually within a day or two.</p>
                </div>
            </form>
        </div>
    </section>
</x-website-layout>
