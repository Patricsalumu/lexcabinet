<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ isset($title) ? $title.' · ' : '' }}LexCabinet</title>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=ibm-plex-sans:400,500,600|ibm-plex-mono:400,500|newsreader:500,600&display=swap" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="bg-slate-100 font-sans text-slate-900 antialiased">
        <div class="flex min-h-screen flex-col items-center px-4 pt-10 sm:justify-center sm:pt-0">
            <div class="text-center">
                <p class="font-display text-3xl font-semibold text-slate-900">LexCabinet</p>
                <p class="mt-1 text-sm text-slate-500">{{ app(\App\Services\SettingsService::class)->get('firm.name') }}</p>
            </div>

            <div class="mt-6 w-full overflow-hidden rounded-lg border border-slate-200 bg-white px-6 py-5 shadow-sm sm:max-w-md">
                {{ $slot }}
            </div>
        </div>
    </body>
</html>
