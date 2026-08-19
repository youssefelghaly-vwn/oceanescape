{{-- resources/views/auth/verify-email.blade.php --}}
<x-auth-layout title="Confirm your email" heading="One more step">

    <p class="text-sm leading-relaxed text-tide-700">
        We&rsquo;ve sent a confirmation link to
        <span class="font-medium text-ink-900">{{ auth()->user()->email }}</span>.
        Click it and your stays will appear.
    </p>

    <div class="mt-5 rounded-2xl bg-fog-100 p-4 text-[12px] leading-relaxed text-tide-600">
        Why we ask: your reservations are matched to your email address. Confirming the address
        proves the inbox is yours, so nobody else can sign up and see your bookings.
    </div>

    <form method="POST" action="{{ route('verification.send') }}" class="mt-6">
        @csrf
        <button type="submit"
                class="w-full rounded-full bg-brand-600 py-3 text-sm font-semibold text-white transition hover:bg-brand-700">
            Resend the link
        </button>
    </form>

    <form method="POST" action="{{ route('logout') }}" class="mt-3">
        @csrf
        <button type="submit" class="w-full py-2 text-sm text-tide-500 transition hover:text-ink-900">
            Sign out
        </button>
    </form>
</x-auth-layout>
