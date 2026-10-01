<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#0b57d0">
    <meta name="description" content="Simple multi-company field sales ERP">

    <title>{{ config('app.name', 'Simple ERP') }} @yield('title')</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet">
    <link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
    <link rel="icon" href="{{ asset('icons/icon-192.png') }}" type="image/png">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="font-sans antialiased">
<div id="app" class="min-h-screen flex flex-col">

    {{-- Top app bar --}}
    <header class="sticky top-0 z-30 bg-surface-container-high/95 backdrop-blur border-b border-outline-variant">
        <div class="px-4 h-16 flex items-center gap-3">
            <button type="button" data-drawer-toggle
                    class="hidden md:inline-flex items-center justify-center w-10 h-10 rounded-full hover:bg-surface-container-high lg:hidden"
                    aria-label="Open menu">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" d="M4 6h16M4 12h16M4 18h16"/>
                </svg>
            </button>

            <a href="{{ route('dashboard') }}" class="flex items-center gap-2 mr-auto">
                <span class="w-9 h-9 rounded-full bg-primary text-on-primary grid place-items-center font-bold">E</span>
                <span class="font-semibold">{{ config('app.name', 'Simple ERP') }}</span>
            </a>

            <span id="net-status" x-data x-text="$store.net.label()" :class="$store.net.pillClass()"
                  class="m-chip" data-net-status title="Network / sync state">…</span>

            <x-app-user-menu />
            <form method="POST" action="{{ route('logout') }}" class="contents">
                @csrf
                <button type="submit" class="w-10 h-10 rounded-full grid place-items-center hover:bg-surface-container-high"
                        aria-label="Log out" title="Log out ({{ auth()->user()->email }})">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15m3 0l3-3m0 0l-3-3m3 3H9"/>
                    </svg>
                </button>
            </form>
        </div>
    </header>

    <div class="flex flex-1">
        {{-- Navigation drawer (tablet/desktop) --}}
        <aside id="drawer"
               class="fixed inset-y-16 left-0 z-20 w-64 bg-surface-container transform -translate-x-full transition-transform duration-200 lg:static lg:translate-x-0 lg:shrink-0">
            <nav class="p-3 space-y-1 overflow-y-auto h-[calc(100%-4rem)]">
                <x-nav-links variant="sidebar" />
            </nav>
        </aside>

        {{-- Page content --}}
        <main class="flex-1 w-full max-w-6xl mx-auto px-4 pt-4 pb-28 md:pb-10">
            @isset($header)
                <div class="mb-4">{{ $header }}</div>
            @endisset

            {{ $slot }}
        </main>
    </div>

    {{-- Bottom navigation (mobile) --}}
    <nav class="fixed bottom-0 inset-x-0 z-30 bg-surface-container-high border-t border-outline-variant pb-[env(safe-area-inset-bottom)] md:hidden">
        <div class="grid grid-cols-5">
            <x-nav-links variant="bottom" />
        </div>
        <div class="h-1 flex justify-center">
            <span class="w-16 h-1 rounded-full bg-outline-variant mb-1"></span>
        </div>
    </nav>
</div>

{{-- Network/sync state banner (hidden by default; Alpine toggles it) --}}
<div x-data x-cloak x-show="$store.net.banner" x-transition class="fixed bottom-16 md:bottom-4 inset-x-4 z-40">
    <div class="m-card px-4 py-3 text-sm shadow-m3" :class="$store.net.bannerClass" x-text="$store.net.bannerText"></div>
</div>

@stack('modals')
@stack('scripts')
</body>
</html>
