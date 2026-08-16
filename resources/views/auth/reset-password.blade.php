{{-- resources/views/auth/reset-password.blade.php --}}
<x-auth-layout title="Set a new password" heading="Set a new password">

    <form method="POST" action="{{ route('password.update') }}" class="space-y-5">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">

        @php
            $field = 'w-full rounded-xl border-0 bg-white px-3.5 py-3 text-sm ring-1 ring-fog-300 focus:ring-2 focus:ring-brand-500';
            $label = 'block font-mono text-[10px] uppercase tracking-wide text-tide-500';
        @endphp

        <div>
            <label for="email" class="{{ $label }}">Email</label>
            <input id="email" name="email" type="email" required autocomplete="username"
                   value="{{ old('email', $email) }}"
                   class="mt-1.5 {{ $field }} @error('email') ring-red-400 @enderror">
            @error('email')
                <p class="mt-1.5 text-xs text-red-700">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="password" class="{{ $label }}">New password</label>
            <input id="password" name="password" type="password" required autofocus autocomplete="new-password"
                   class="mt-1.5 {{ $field }} @error('password') ring-red-400 @enderror">
            @error('password')
                <p class="mt-1.5 text-xs text-red-700">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="password_confirmation" class="{{ $label }}">Confirm new password</label>
            <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password"
                   class="mt-1.5 {{ $field }}">
        </div>

        <button type="submit"
                class="w-full rounded-full bg-brand-600 py-3 text-sm font-semibold text-white transition hover:bg-brand-700">
            Reset password
        </button>
    </form>
</x-auth-layout>
