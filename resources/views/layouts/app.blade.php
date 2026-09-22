<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'AEA Service Portal' }}</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-screen bg-gray-50 antialiased">

    <div class="md:flex md:min-h-screen">
        <aside class="hidden md:flex md:w-56 md:flex-col md:border-r md:border-gray-200 md:bg-white">
            <div class="flex h-16 items-center gap-2 border-b border-gray-200 px-5">
                <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary-500 text-xs font-bold text-white">AEA</div>
                <span class="text-sm font-semibold text-gray-900">Service Portal</span>
            </div>

            <nav class="flex-1 space-y-1 px-3 py-4">
                <a href="/dashboard" wire:navigate
                   class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium {{ request()->is('dashboard') ? 'bg-primary-50 text-primary-700' : 'text-gray-700 hover:bg-gray-100' }}">
                    <x-icon name="house" class="h-4 w-4" />
                    Overview
                </a>
                @can('viewAny', \App\Models\ServiceRequest::class)
                    <a href="/requests" wire:navigate
                       class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium {{ request()->is('requests') ? 'bg-primary-50 text-primary-700' : 'text-gray-700 hover:bg-gray-100' }}">
                        <x-icon name="envelope" class="h-4 w-4" />
                        Requests
                    </a>
                @endcan
            </nav>

            <div class="border-t border-gray-200 p-3">
                <div class="mb-2 px-2">
                    <p class="truncate text-sm font-medium text-gray-900">{{ auth()->user()->name }}</p>
                    <p class="truncate text-xs text-gray-500">{{ auth()->user()->getRoleNames()->first() }}</p>
                </div>
                <form action="/logout" method="POST">
                    @csrf
                    <button type="submit" class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-sm text-gray-600 hover:bg-gray-100">
                        <x-icon name="box-arrow-right" class="h-4 w-4" />
                        Sign out
                    </button>
                </form>
            </div>
        </aside>

        <div class="flex min-h-screen flex-1 flex-col">
            <header class="flex h-14 items-center justify-between border-b border-gray-200 bg-white px-4 md:hidden">
                <div class="flex items-center gap-2">
                    <div class="flex h-7 w-7 items-center justify-center rounded-md bg-primary-500 text-[10px] font-bold text-white">AEA</div>
                    <span class="text-sm font-semibold text-gray-900">{{ $title ?? 'Service Portal' }}</span>
                </div>
                <form action="/logout" method="POST">
                    @csrf
                    <button type="submit" class="text-gray-500">
                        <x-icon name="box-arrow-right" class="h-5 w-5" />
                    </button>
                </form>
            </header>

            <main class="flex-1 px-4 py-6 pb-20 md:px-8 md:py-8 md:pb-8">
                {{ $slot }}
            </main>
        </div>
    </div>

    <nav class="fixed inset-x-0 bottom-0 z-10 flex border-t border-gray-200 bg-white md:hidden" style="padding-bottom: env(safe-area-inset-bottom, 0px)">
        <a href="/dashboard" wire:navigate
           class="flex flex-1 flex-col items-center gap-0.5 py-2.5 text-xs {{ request()->is('dashboard') ? 'text-primary-600' : 'text-gray-500' }}">
            <x-icon name="house" class="h-5 w-5" />
            Overview
        </a>
        @can('viewAny', \App\Models\ServiceRequest::class)
            <a href="/requests" wire:navigate
               class="flex flex-1 flex-col items-center gap-0.5 py-2.5 text-xs {{ request()->is('requests') ? 'text-primary-600' : 'text-gray-500' }}">
                <x-icon name="envelope" class="h-5 w-5" />
                Requests
            </a>
        @endcan
    </nav>

    @livewireScripts
</body>
</html>