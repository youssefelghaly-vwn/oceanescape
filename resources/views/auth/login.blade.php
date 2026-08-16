{{-- resources/views/auth/login.blade.php --}}
<x-auth-layout title="Sign in" heading="Sign in" subheading="Manage enquiries and bookings.">

    <form method="POST" action="{{ route('login.store') }}" class="space-y-5">
        @csrf

        @php
            $field = 'w-full rounded-xl border-0 bg-white px-3.5 py-3 text-sm ring-1 ring-fog-300 placeholder:text-tide-400 focus:ring-2 focus:ring-brand-500';
            $label = 'block font-mono text-[10px] uppercase tracking-wide text-tide-500';
        @endphp

        <div>
            <label for="email" class="{{ $label }}">Email</label>
            <input id="email" name="email" type="email" required autofocus autocomplete="username"
                   value="{{ old('email') }}"
                   class="mt-1.5 {{ $field }} @error('email') ring-red-400 @enderror">
            @error('email')
                <p class="mt-1.5 text-xs text-red-700">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <div class="flex items-baseline justify-between">
                <label for="password" class="{{ $label }}">Password</label>
                <a href="{{ route('password.request') }}"
                   class="font-mono text-[10px] uppercase tracking-wide text-brand-600 hover:text-brand-800">
                    Forgot?
                </a>
            </div>
            <input id="password" name="password" type="password" required autocomplete="current-password"
                   class="mt-1.5 {{ $field }} @error('password') ring-red-400 @enderror">
            @error('password')
                <p class="mt-1.5 text-xs text-red-700">{{ $message }}</p>
            @enderror
        </div>

        <label class="flex items-center gap-2.5 text-sm text-tide-700">
            <input type="checkbox" name="remember" value="1" @checked(old('remember'))
                   class="rounded border-fog-300 text-brand-600 focus:ring-brand-400">
            Keep me signed in
        </label>

        <button type="submit"
                class="w-full rounded-full bg-brand-600 py-3 text-sm font-semibold text-white transition hover:bg-brand-700">
            Sign in
        </button>
    </form>
</x-auth-layout>
