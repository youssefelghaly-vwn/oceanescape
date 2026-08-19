<x-auth-layout
    title="Create an account"
    heading="Create an account"
    subheading="See your past and upcoming stays in one place.">

    <form method="POST" action="{{ route('register.store') }}" class="space-y-5">
        @csrf

        <div class="absolute left-[-9999px]" aria-hidden="true">
            <label>Website<input type="text" name="website_url" tabindex="-1" autocomplete="off"></label>
        </div>

        @php
            $field = 'w-full rounded-xl border-0 bg-white px-3.5 py-3 text-sm ring-1 ring-fog-300 placeholder:text-tide-400 focus:ring-2 focus:ring-brand-500';
            $label = 'block font-mono text-[10px] uppercase tracking-wide text-tide-500';
        @endphp

        <div>
            <label for="name" class="{{ $label }}">Your name</label>
            <input id="name" name="name" required autofocus autocomplete="name" value="{{ old('name') }}"
                   class="mt-1.5 {{ $field }} @error('name') ring-red-400 @enderror">
            @error('name')<p class="mt-1.5 text-xs text-red-700">{{ $message }}</p>@enderror
        </div>

        <div>
            <label for="email" class="{{ $label }}">Email</label>
            <input id="email" name="email" type="email" required autocomplete="email" value="{{ old('email') }}"
                   class="mt-1.5 {{ $field }} @error('email') ring-red-400 @enderror">
            @error('email')
                <p class="mt-1.5 text-xs text-red-700">{{ $message }}</p>
            @else
                <p class="mt-1.5 text-[11px] leading-snug text-tide-500">
                    Use the same address you booked with and your stays will appear automatically.
                </p>
            @enderror
        </div>

        <div>
            <label for="password" class="{{ $label }}">Password</label>
            <input id="password" name="password" type="password" required autocomplete="new-password"
                   class="mt-1.5 {{ $field }} @error('password') ring-red-400 @enderror">
            @error('password')<p class="mt-1.5 text-xs text-red-700">{{ $message }}</p>@enderror
        </div>

        <div>
            <label for="password_confirmation" class="{{ $label }}">Confirm password</label>
            <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password"
                   class="mt-1.5 {{ $field }}">
        </div>

        <button type="submit"
                class="w-full rounded-full bg-brand-600 py-3 text-sm font-semibold text-white transition hover:bg-brand-700">
            Create account
        </button>

        <p class="text-center text-sm text-tide-600">
            Already have one?
            <a href="{{ route('login') }}" class="font-medium text-brand-600 hover:underline">Sign in</a>
        </p>

        <p class="text-center text-[11px] leading-relaxed text-tide-500">
            We&rsquo;ll email you a link to confirm your address. Your stays stay hidden until you do
            &mdash; that&rsquo;s what stops anyone else claiming your bookings.
        </p>
    </form>
</x-auth-layout>