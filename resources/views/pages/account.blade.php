{{-- resources/views/pages/account.blade.php --}}
<x-website-layout title="Account Settings | Ocean Escape Cottages">

@php
    $field = 'w-full rounded-xl border-0 bg-fog-50 px-3.5 py-3 text-sm text-ink-900 ring-1 ring-fog-300 placeholder:text-tide-400 focus:ring-2 focus:ring-brand-500';
    $label = 'block font-mono text-[10px] uppercase tracking-wide text-tide-500';
@endphp

<section class="border-b border-fog-200 bg-fog-50 pb-10 pt-32">
    <div class="mx-auto max-w-3xl px-6">
        <a href="{{ route('profile.index') }}"
           class="inline-flex items-center gap-1.5 font-mono text-[10px] uppercase tracking-wide text-tide-500 transition hover:text-ink-900">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M15 18l-6-6 6-6"/></svg>
            My stays
        </a>

        <h1 class="mt-3 font-display text-3xl font-medium leading-tight text-ink-900 sm:text-4xl">
            Account settings
        </h1>
        <p class="mt-3 text-base text-tide-700">
            Your bookings are matched to your email address, so keeping it current keeps your
            stays visible.
        </p>
    </div>
</section>

<section class="bg-white py-12">
    <div class="mx-auto max-w-3xl space-y-6 px-6">

        @if (session('status'))
            <div class="flex items-start gap-2.5 rounded-2xl bg-emerald-50 px-5 py-4 text-sm text-emerald-900 ring-1 ring-emerald-200">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" class="mt-0.5 shrink-0">
                    <path d="M20 6 9 17l-5-5"/>
                </svg>
                {{ session('status') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="rounded-2xl bg-red-50 px-5 py-4 text-sm text-red-900 ring-1 ring-red-200">
                <p class="font-medium">Please check the following.</p>
                <ul class="mt-2 list-disc space-y-1 pl-5 text-[13px]">
                    @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                </ul>
            </div>
        @endif

        {{-- ------------------------------------------------ your details --}}
        <div class="rounded-3xl bg-fog-50/60 p-6 ring-1 ring-fog-200">
            <h2 class="font-display text-xl font-medium text-ink-900">Your details</h2>

            <form method="POST" action="{{ route('account.update') }}" class="mt-5 space-y-5">
                @csrf
                @method('PATCH')

                <div>
                    <label for="name" class="{{ $label }}">Name</label>
                    <input id="name" name="name" required autocomplete="name"
                           value="{{ old('name', $user->name) }}"
                           class="mt-1.5 {{ $field }} @error('name') ring-red-400 @enderror">
                </div>

                <div>
                    <label for="email" class="{{ $label }}">Email</label>
                    <input id="email" name="email" type="email" required autocomplete="email"
                           value="{{ old('email', $user->email) }}"
                           class="mt-1.5 {{ $field }} @error('email') ring-red-400 @enderror">

                    {{-- Said plainly, because the consequence is not obvious:
                         changing the address changes which bookings appear, and
                         hides all of them until the new one is confirmed. --}}
                    <p class="mt-2 text-[11px] leading-relaxed text-tide-500">
                        Bookings are matched to this address. If you change it we&rsquo;ll send a new
                        confirmation link, and your stays stay hidden until you click it.
                    </p>
                </div>

                <button type="submit"
                        class="rounded-full bg-brand-600 px-7 py-3 text-sm font-semibold text-white transition hover:bg-brand-700">
                    Save changes
                </button>
            </form>
        </div>

        {{-- --------------------------------------------------- password --}}
        <div class="rounded-3xl bg-fog-50/60 p-6 ring-1 ring-fog-200">
            <h2 class="font-display text-xl font-medium text-ink-900">Change password</h2>

            <form method="POST" action="{{ route('account.password') }}" class="mt-5 space-y-5">
                @csrf
                @method('PUT')

                <div>
                    <label for="current_password" class="{{ $label }}">Current password</label>
                    <input id="current_password" name="current_password" type="password" required
                           autocomplete="current-password"
                           class="mt-1.5 {{ $field }} @error('current_password') ring-red-400 @enderror">
                    @error('current_password')
                        <p class="mt-1.5 text-xs text-red-700">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="password" class="{{ $label }}">New password</label>
                    <input id="password" name="password" type="password" required autocomplete="new-password"
                           class="mt-1.5 {{ $field }} @error('password') ring-red-400 @enderror">
                    @error('password')
                        <p class="mt-1.5 text-xs text-red-700">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="password_confirmation" class="{{ $label }}">Confirm new password</label>
                    <input id="password_confirmation" name="password_confirmation" type="password" required
                           autocomplete="new-password" class="mt-1.5 {{ $field }}">
                </div>

                <button type="submit"
                        class="rounded-full bg-brand-600 px-7 py-3 text-sm font-semibold text-white transition hover:bg-brand-700">
                    Update password
                </button>
            </form>
        </div>

        {{-- ------------------------------------------------------ status --}}
        <div class="rounded-3xl bg-white p-6 ring-1 ring-fog-200">
            <h2 class="font-mono text-[10px] uppercase tracking-wide text-tide-500">Account</h2>

            <dl class="mt-4 space-y-3 text-sm">
                <div class="flex items-center justify-between gap-4">
                    <dt class="text-tide-600">Email confirmed</dt>
                    <dd>
                        @if ($user->hasVerifiedEmail())
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-3 py-1 font-mono text-[10px] font-semibold uppercase tracking-wide text-emerald-800 ring-1 ring-emerald-200">
                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M20 6 9 17l-5-5"/></svg>
                                Confirmed
                            </span>
                        @else
                            <span class="inline-flex rounded-full bg-amber-50 px-3 py-1 font-mono text-[10px] font-semibold uppercase tracking-wide text-amber-800 ring-1 ring-amber-200">
                                Not yet
                            </span>
                        @endif
                    </dd>
                </div>

                <div class="flex items-center justify-between gap-4">
                    <dt class="text-tide-600">Member since</dt>
                    <dd class="text-ink-900">{{ $user->created_at?->format('F Y') ?? '—' }}</dd>
                </div>
            </dl>

            <form method="POST" action="{{ route('logout') }}" class="mt-5 border-t border-fog-200 pt-5">
                @csrf
                <button type="submit"
                        class="text-sm font-medium text-tide-600 transition hover:text-ink-900">
                    Sign out
                </button>
            </form>
        </div>

        <p class="text-xs leading-relaxed text-tide-500">
            Booked with a different email address? <a href="{{ route('contact') }}" class="font-medium text-brand-700 underline">Let us know</a>
            and we&rsquo;ll help you find those stays &mdash; you don&rsquo;t need a second account.
        </p>
    </div>
</section>

</x-website-layout>