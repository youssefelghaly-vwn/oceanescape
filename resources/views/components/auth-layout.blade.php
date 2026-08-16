{{-- resources/views/components/auth-layout.blade.php --}}
@props(['title' => 'Sign in', 'heading' => null, 'subheading' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title }} · Ocean Escape Cottages</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-fog-50 font-sans text-ink-900 antialiased">

<div class="flex min-h-full flex-col lg:flex-row">

    {{-- brand panel: decorative, so it steps aside on small screens --}}
    <div class="relative hidden overflow-hidden bg-ink-900 lg:flex lg:w-2/5 lg:flex-col lg:justify-between">
        <img src="{{ asset('assets/images/hero.png') }}" alt=""
             onerror="this.style.display='none'"
             class="absolute inset-0 h-full w-full object-cover opacity-40">
        <div class="absolute inset-0 bg-gradient-to-t from-ink-900 via-ink-900/60 to-ink-900/20"></div>

        <div class="relative z-10 p-10">
            <a href="{{ route('home') }}" class="font-display text-xl font-medium text-white">
                Ocean Escape <span class="italic text-brand-300">Cottages</span>
            </a>
        </div>

        <div class="relative z-10 p-10">
            <p class="font-mono text-[11px] uppercase tracking-[0.2em] text-brand-300">
                Lockeport, Nova Scotia
            </p>
            <p class="mt-3 max-w-sm font-display text-2xl leading-snug text-white">
                Six oceanfront cottages, managed from one place.
            </p>
        </div>
    </div>

    {{-- form --}}
    <div class="flex flex-1 items-center justify-center px-6 py-16">
        <div class="w-full max-w-sm">
            <a href="{{ route('home') }}" class="mb-10 block font-display text-lg font-medium lg:hidden">
                Ocean Escape <span class="italic text-brand-700">Cottages</span>
            </a>

            <h1 class="font-display text-2xl font-medium text-ink-900">
                {{ $heading ?? $title }}
            </h1>
            @if ($subheading)
                <p class="mt-2 text-sm leading-relaxed text-tide-600">{{ $subheading }}</p>
            @endif

            @if (session('status'))
                <div class="mt-6 flex items-start gap-2.5 rounded-2xl bg-emerald-50 px-4 py-3 text-sm text-emerald-900 ring-1 ring-emerald-200">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" class="mt-0.5 shrink-0">
                        <path d="M20 6 9 17l-5-5"/>
                    </svg>
                    {{ session('status') }}
                </div>
            @endif

            <div class="mt-7">
                {{ $slot }}
            </div>

            <p class="mt-10 text-center font-mono text-[10px] uppercase tracking-wider text-tide-400">
                <a href="{{ route('home') }}" class="hover:text-tide-700">&larr; Back to site</a>
            </p>
        </div>
    </div>
</div>

</body>
</html>
