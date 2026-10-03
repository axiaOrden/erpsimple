<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#123d39">
    <meta name="description" content="Simple multi-company field sales ERP">

    <title>{{ config('app.name', 'Simple ERP') }}</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=manrope:400,500,600,700,800&display=swap" rel="stylesheet">
    <link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
    <link rel="icon" href="{{ asset('icons/icon-192.png') }}" type="image/png">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="font-sans text-on-surface antialiased">
<div class="min-h-dvh flex flex-col sm:justify-center items-center pt-6 sm:pt-0 bg-surface px-4"
     style="background-image: radial-gradient(circle at 18% 8%, rgba(0,106,100,.28) 0%, transparent 42%), radial-gradient(circle at 88% 90%, rgba(74,99,95,.2) 0%, transparent 44%), linear-gradient(145deg, #d5e9e3, #edf7f4 55%, #c9e1da);">
    <div class="w-full sm:max-w-md py-6">
        {{ $slot }}
    </div>
</div>
</body>
</html>
