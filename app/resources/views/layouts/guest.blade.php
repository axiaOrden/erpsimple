<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#0b57d0">
    <meta name="description" content="Simple multi-company field sales ERP">

    <title>{{ config('app.name', 'Simple ERP') }}</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet">
    <link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
    <link rel="icon" href="{{ asset('icons/icon-192.png') }}" type="image/png">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="font-sans text-on-surface antialiased">
<div class="min-h-dvh flex flex-col sm:justify-center items-center pt-6 sm:pt-0 bg-surface px-4"
     style="background-image: radial-gradient(circle at 20% 10%, #d3e3fd 0%, transparent 45%), radial-gradient(circle at 85% 85%, #cdebff 0%, transparent 40%);">
    <div class="w-full sm:max-w-md py-6">
        {{ $slot }}
    </div>
</div>
</body>
</html>
