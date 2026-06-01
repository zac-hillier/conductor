<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? 'Conductor' }}</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @fluxAppearance
</head>
<body class="min-h-full bg-zinc-50 text-zinc-800 dark:bg-zinc-900 dark:text-zinc-200">
    <main class="w-full px-6 py-10">
        {{ $slot }}
    </main>

    @fluxScripts
</body>
</html>
