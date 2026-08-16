<x-auth-layout
    title="Reset password"
    heading="Forgot your password?"
    subheading="Give us the email on your account and we'll send a link to set a new password.">

    <form method="POST" action="{{ route('password.email') }}" class="space-y-5">
        @csrf

        <div>
            <label for="email" class="block font-mono text-[10px] uppercase tracking-wide text-tide-500">Email</label>
            <input id="email" name="email" type="email" required autofocus value="{{ old('email') }}"
                   class="mt-1.5 w-full rounded-xl border-0 bg-white px-3.5 py-3 text-sm ring-1 ring-fog-300 placeholder:text-tide-400 focus:ring-2 focus:ring-brand-500 @error('email') ring-red-400 @enderror">
            @error('email')
                <p class="mt-1.5 text-xs text-red-700">{{ $message }}</p>
            @enderror
        </div>

        <button type="submit"
                class="w-full rounded-full bg-brand-600 py-3 text-sm font-semibold text-white transition hover:bg-brand-700">
            Email me a reset link
        </button>

        <p class="text-center text-sm text-tide-600">
            <a href="{{ route('login') }}" class="font-medium text-brand-600 hover:underline">Back to sign in</a>
        </p>
    </form>
</x-auth-layout>