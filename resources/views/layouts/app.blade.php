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
        <aside class="hidden md:flex md:w-56 md:flex-col md:bg-primary-500">
            <div class="flex h-16 items-center gap-2 border-b border-white/15 px-5">
                <div class="flex h-8 w-8 items-center justify-center bg-white text-xs font-bold text-primary-600">AEA</div>
                <span class="text-sm font-semibold text-white">Service Portal</span>
            </div>

            <nav class="flex-1 space-y-1 px-3 py-4">
                <a href="/dashboard" wire:navigate
                   class="flex items-center gap-3 px-3 py-2 text-sm font-medium {{ request()->is('dashboard') ? 'bg-info-500 text-white' : 'text-white/85 hover:bg-white/10' }}">
                    <x-icon name="house" class="h-4 w-4" />
                    Overview
                </a>
                @can('viewAny', \App\Models\ServiceRequest::class)
                    <a href="/requests" wire:navigate
                       class="flex items-center gap-3 px-3 py-2 text-sm font-medium {{ request()->is('requests*') ? 'bg-info-500 text-white' : 'text-white/85 hover:bg-white/10' }}">
                        <x-icon name="envelope" class="h-4 w-4" />
                        Requests
                    </a>
                @endcan
                @can('viewAny', \App\Models\Quotation::class)
                    <a href="/quotations" wire:navigate
                       class="flex items-center gap-3 px-3 py-2 text-sm font-medium {{ request()->is('quotations*') ? 'bg-info-500 text-white' : 'text-white/85 hover:bg-white/10' }}">
                        <x-icon name="journal-text" class="h-4 w-4" />
                        Quotations
                    </a>
                @endcan
                @can('viewAny', \App\Models\WorkOrder::class)
                    <a href="/jobs" wire:navigate
                       class="flex items-center gap-3 px-3 py-2 text-sm font-medium {{ request()->is('jobs*') ? 'bg-info-500 text-white' : 'text-white/85 hover:bg-white/10' }}">
                        <x-icon name="tools" class="h-4 w-4" />
                        Jobs
                    </a>
                @endcan
                @can('viewAny', \App\Models\Document::class)
                    <a href="/documents" wire:navigate
                       class="flex items-center gap-3 px-3 py-2 text-sm font-medium {{ request()->is('documents*') ? 'bg-info-500 text-white' : 'text-white/85 hover:bg-white/10' }}">
                        <x-icon name="folder" class="h-4 w-4" />
                        Documents
                    </a>
                @endcan
                @can('viewAny', \App\Models\Invoice::class)
                    <a href="/invoices" wire:navigate
                       class="flex items-center gap-3 px-3 py-2 text-sm font-medium {{ request()->is('invoices*') ? 'bg-info-500 text-white' : 'text-white/85 hover:bg-white/10' }}">
                        <x-icon name="receipt" class="h-4 w-4" />
                        Invoices
                    </a>
                @endcan
            </nav>

            <div class="border-t border-white/15 p-3">
                <div class="mb-2 px-2">
                    <p class="truncate text-sm font-medium text-white">{{ auth()->user()->name }}</p>
                    <p class="truncate text-xs text-white/70">{{ auth()->user()->getRoleNames()->first() }}</p>
                </div>
                <form action="/logout" method="POST">
                    @csrf
                    <button type="submit" class="flex w-full items-center gap-2 px-3 py-2 text-sm text-white/85 hover:bg-white/10">
                        <x-icon name="box-arrow-right" class="h-4 w-4" />
                        Sign out
                    </button>
                </form>
            </div>
        </aside>

        <div class="flex min-h-screen flex-1 flex-col">
            <header class="flex h-14 items-center justify-between bg-primary-500 px-4 md:hidden">
                <div class="flex items-center gap-2">
                    <div class="flex h-7 w-7 items-center justify-center bg-white text-[10px] font-bold text-primary-600">AEA</div>
                    <span class="text-sm font-semibold text-white">{{ $title ?? 'Service Portal' }}</span>
                </div>
                <form action="/logout" method="POST">
                    @csrf
                    <button type="submit" class="text-white/85">
                        <x-icon name="box-arrow-right" class="h-5 w-5" />
                    </button>
                </form>
            </header>

            <main class="flex-1 px-4 py-6 pb-20 md:px-8 md:py-8 md:pb-8">
                {{ $slot }}
            </main>
        </div>
    </div>

    <nav class="fixed inset-x-0 bottom-0 z-10 flex bg-primary-500" style="padding-bottom: env(safe-area-inset-bottom, 0px)">
        <div class="flex w-full md:hidden">
            <a href="/dashboard" wire:navigate
               class="flex flex-1 flex-col items-center gap-0.5 py-2.5 text-xs {{ request()->is('dashboard') ? 'bg-info-500 text-white' : 'text-white/70' }}">
                <x-icon name="house" class="h-5 w-5" />
                Overview
            </a>
            @can('viewAny', \App\Models\ServiceRequest::class)
                <a href="/requests" wire:navigate
                   class="flex flex-1 flex-col items-center gap-0.5 py-2.5 text-xs {{ request()->is('requests*') ? 'bg-info-500 text-white' : 'text-white/70' }}">
                    <x-icon name="envelope" class="h-5 w-5" />
                    Requests
                </a>
            @endcan
            @can('viewAny', \App\Models\Quotation::class)
                <a href="/quotations" wire:navigate
                   class="flex flex-1 flex-col items-center gap-0.5 py-2.5 text-xs {{ request()->is('quotations*') ? 'bg-info-500 text-white' : 'text-white/70' }}">
                    <x-icon name="journal-text" class="h-5 w-5" />
                    Quotes
                </a>
            @endcan
            @can('viewAny', \App\Models\WorkOrder::class)
                <a href="/jobs" wire:navigate
                   class="flex flex-1 flex-col items-center gap-0.5 py-2.5 text-xs {{ request()->is('jobs*') ? 'bg-info-500 text-white' : 'text-white/70' }}">
                    <x-icon name="tools" class="h-5 w-5" />
                    Jobs
                </a>
            @endcan
            @can('viewAny', \App\Models\Document::class)
                <a href="/documents" wire:navigate
                   class="flex flex-1 flex-col items-center gap-0.5 py-2.5 text-xs {{ request()->is('documents*') ? 'bg-info-500 text-white' : 'text-white/70' }}">
                    <x-icon name="folder" class="h-5 w-5" />
                    Docs
                </a>
            @endcan
            @can('viewAny', \App\Models\Invoice::class)
                <a href="/invoices" wire:navigate
                   class="flex flex-1 flex-col items-center gap-0.5 py-2.5 text-xs {{ request()->is('invoices*') ? 'bg-info-500 text-white' : 'text-white/70' }}">
                    <x-icon name="receipt" class="h-5 w-5" />
                    Invoices
                </a>
            @endcan
        </div>
    </nav>

    @livewireScripts
</body>
</html>
