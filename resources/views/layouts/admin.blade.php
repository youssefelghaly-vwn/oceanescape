{{-- resources/views/components/admin-layout.blade.php

     Chrome for the admin area. Deliberately plainer than the public site:
     dense, legible, and quick to scan. Sidebar collapses to a top bar on
     mobile so the tables get the full width. --}}
@props(['title' => 'Admin', 'heading' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title }} · Ocean Escape Admin</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="h-full bg-fog-50 font-sans text-ink-900 antialiased">

    <div x-data="{ nav: false }" class="min-h-full lg:flex">

        {{-- ------------------------------------------------------ sidebar --}}
        <aside class="lg:fixed lg:inset-y-0 lg:z-30 lg:w-60 lg:border-r lg:border-fog-200 lg:bg-white">
            <div class="flex items-center justify-between px-5 py-4 lg:block">
                <a href="{{ route('admin.business-stays.index') }}" class="flex items-center gap-2.5">
                    <span
                        class="grid h-8 w-8 place-items-center rounded-lg bg-brand-600 font-display text-sm text-white">OE</span>
                    <span>
                        <span class="block font-display text-sm font-medium leading-tight">Ocean Escape</span>
                        <span class="block font-mono text-[9px] uppercase tracking-wider text-tide-500">Admin</span>
                    </span>
                </a>

                <button type="button" @click="nav = !nav" class="lg:hidden" aria-label="Toggle navigation">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2">
                        <path x-show="!nav" d="M3 6h18M3 12h18M3 18h18" />
                        <path x-show="nav" x-cloak d="M18 6 6 18M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <nav class="border-t border-fog-200 px-3 py-4 lg:border-t-0" :class="nav ? 'block' : 'hidden lg:block'">
                @php
                    $links = [
                        ['label' => 'Business stays', 'route' => 'admin.business-stays.index'],
                        ['label' => 'Messages', 'route' => 'admin.messages.index'],
                        ['label' => 'Guest photos', 'route' => 'admin.photos.index'],
                    ];
                @endphp

                @foreach ($links as $link)
                    @php $active = request()->routeIs(str_replace('.index', '.*', $link['route'])); @endphp
                    <a href="{{ route($link['route']) }}"
                        class="flex items-center gap-2.5 rounded-xl px-3 py-2.5 text-sm transition
                          {{ $active ? 'bg-brand-50 font-medium text-brand-800' : 'text-tide-700 hover:bg-fog-100' }}">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="1.8">
                            <rect x="2" y="7" width="20" height="14" rx="2" />
                            <path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2" />
                        </svg>
                        {{ $link['label'] }}
                    </a>
                @endforeach

                <div class="mt-4 border-t border-fog-200 pt-4">
                    <a href="{{ route('home') }}" target="_blank"
                        class="flex items-center gap-2.5 rounded-xl px-3 py-2.5 text-sm text-tide-600 transition hover:bg-fog-100">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="1.8">
                            <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6M15 3h6v6M10 14 21 3" />
                        </svg>
                        View site
                    </a>

                    @auth
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit"
                                class="flex w-full items-center gap-2.5 rounded-xl px-3 py-2.5 text-left text-sm text-tide-600 transition hover:bg-fog-100">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                    stroke-width="1.8">
                                    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9" />
                                </svg>
                                Sign out
                            </button>
                        </form>
                        <p class="px-3 pt-2 font-mono text-[10px] text-tide-400">{{ auth()->user()->email }}</p>
                    @endauth
                </div>
            </nav>
        </aside>

        {{-- ------------------------------------------------------ content --}}
        <div class="min-w-0 flex-1 lg:pl-60">
            <header class="border-b border-fog-200 bg-white px-5 py-5 sm:px-8">
                <div class="mx-auto max-w-6xl">
                    {{ $heading ?? '' }}
                    @unless ($heading)
                        <h1 class="font-display text-2xl font-medium">{{ $title }}</h1>
                    @endunless
                </div>
            </header>

            @if (session('status'))
                <div class="px-5 pt-5 sm:px-8">
                    <div
                        class="mx-auto flex max-w-6xl items-center gap-2.5 rounded-2xl bg-emerald-50 px-4 py-3 text-sm text-emerald-900 ring-1 ring-emerald-200">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2.4" class="shrink-0">
                            <path d="M20 6 9 17l-5-5" />
                        </svg>
                        {{ session('status') }}
                    </div>
                </div>
            @endif

            <main class="px-5 py-6 sm:px-8">
                <div class="mx-auto max-w-6xl">
                    {{ $slot }}
                </div>
            </main>
        </div>
    </div>

</body>

</html>
