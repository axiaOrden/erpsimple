<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#123d39">
    <meta name="description" content="Simple multi-company field sales ERP">

    <title>{{ config('app.name', 'Simple ERP') }} @yield('title')</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=manrope:400,500,600,700,800&display=swap" rel="stylesheet">
    <link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
    <link rel="icon" href="{{ asset('icons/icon-192.png') }}" type="image/png">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="font-sans antialiased">
<div id="app" class="min-h-screen flex flex-col">

    {{-- Top app bar --}}
    <header class="sticky top-0 z-30 border-b border-white/10 bg-[#123d39]/95 text-[#e9fffa] shadow-m1 backdrop-blur-xl">
        <div class="mx-auto flex h-[4.5rem] max-w-[1600px] items-center gap-3 px-4 md:px-6">
            <button type="button" data-drawer-toggle
                    class="hidden h-11 w-11 items-center justify-center rounded-full hover:bg-white/10 md:inline-flex lg:hidden"
                    aria-label="Open menu">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" d="M4 6h16M4 12h16M4 18h16"/>
                </svg>
            </button>

            <a href="{{ route('dashboard') }}" class="mr-auto flex items-center gap-3">
                <span class="grid h-11 w-11 place-items-center rounded-[1rem] bg-primary-container text-lg font-extrabold text-on-primary-container shadow-m1">E</span>
                <span class="hidden font-bold tracking-tight sm:inline">{{ config('app.name', 'Simple ERP') }}</span>
            </a>

            <span id="net-status" x-data x-text="$store.net.label()" :class="$store.net.pillClass()"
                  class="m-chip" data-net-status title="Network / sync state">…</span>

            <x-app-user-menu />
            <form method="POST" action="{{ route('logout') }}" class="contents">
                @csrf
                <button type="submit" class="grid h-11 w-11 place-items-center rounded-full text-[#c5dcd7] hover:bg-white/10 hover:text-white"
                        aria-label="Log out" title="Log out ({{ auth()->user()->email }})">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15m3 0l3-3m0 0l-3-3m3 3H9"/>
                    </svg>
                </button>
            </form>
        </div>
    </header>

    <div class="mx-auto flex w-full max-w-[1600px] flex-1">
        {{-- Navigation drawer (tablet/desktop) --}}
        <aside id="drawer"
               class="fixed inset-y-[4.5rem] left-0 z-20 w-72 -translate-x-full transform border-r border-white/10 bg-[#173f3b] text-[#e9fffa] shadow-m2 transition-transform duration-200 lg:static lg:translate-x-0 lg:shrink-0 lg:shadow-none">
            <nav class="h-[calc(100dvh-4.5rem)] space-y-1 overflow-y-auto p-4">
                <x-nav-links variant="sidebar" />
            </nav>
        </aside>

        {{-- Page content --}}
        <main class="mx-auto w-full max-w-7xl flex-1 px-3 pb-28 pt-4 sm:px-5 md:pb-12 md:pt-6 lg:px-8">
            @isset($header)
                <div class="m-page-header mb-5">{{ $header }}</div>
            @endisset

            {{ $slot }}
        </main>
    </div>

    {{-- Bottom navigation (mobile) --}}
    <nav class="fixed inset-x-0 bottom-0 z-30 min-h-[5rem] border-t border-white/10 bg-[#123d39] pb-[env(safe-area-inset-bottom)] text-[#e9fffa] shadow-[0_-8px_30px_rgba(0,55,50,.22)] md:hidden">
        <div class="grid min-h-[4.5rem] grid-cols-5 px-1 pt-1">
            <x-nav-links variant="bottom" />
        </div>
        <div class="h-1 flex justify-center">
            <span class="mb-1 h-1 w-16 rounded-full bg-[#7ba9a2]"></span>
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
