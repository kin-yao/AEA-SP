<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'AEA Service Operations Hub' }}</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script src="{{ asset('js/location-picker.js') }}"></script>
    @livewireStyles
</head>
<body class="min-h-screen antialiased">
    <div class="flex min-h-screen items-center justify-center px-4 py-8">
        <div class="w-full max-w-sm">
            {{ $slot }}
        </div>
    </div>

    @livewireScripts
</body>
</html>
