<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'AEA Service Portal' }}</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-screen bg-[#f7f7f8] antialiased">

    <div class="md:flex md:min-h-screen">
        {{-- Desktop sidebar --}}
        <aside class="hidden md:flex md:w-64 md:flex-col md:border-r md:border-neutral-200 md:bg-white">
            <div class="flex h-16 items-center gap-2.5 border-b border-neutral-200 px-5">
                <div class="flex h-9 w-9 items-center justify-center rounded-[var(--radius-sm)] bg-primary-500 text-xs font-bold text-white">AEA</div>
                <span class="text-sm font-semibold text-neutral-900">Service Portal</span>
            </div>

            <nav class="flex-1 space-y-0.5 overflow-y-auto px-3 py-5">
                <a href="/dashboard" wire:navigate
                   class="flex items-center gap-3 rounded-[var(--radius-md)] px-3 py-2.5 text-sm font-medium {{ request()->is('dashboard') ? 'bg-primary-50 text-primary-700' : 'text-neutral-600 hover:bg-neutral-50' }}">
                    <x-icon name="house" class="h-4.5 w-4.5" />
                    Overview
                </a>

                @canany([['viewAny', \App\Models\ServiceRequest::class], ['viewAny', \App\Models\WorkOrder::class], ['viewAny', \App\Models\Document::class]])
                    <p class="mb-1 mt-6 px-3 text-[11px] font-semibold uppercase tracking-wider text-neutral-400">Operations</p>
                @endcanany
                @can('viewAny', \App\Models\ServiceRequest::class)
                    <a href="/requests" wire:navigate
                       class="flex items-center gap-3 rounded-[var(--radius-md)] px-3 py-2.5 text-sm font-medium {{ request()->is('requests*') ? 'bg-primary-50 text-primary-700' : 'text-neutral-600 hover:bg-neutral-50' }}">
                        <x-icon name="envelope" class="h-4.5 w-4.5" />
                        Requests
                    </a>
                @endcan
                @can('viewAny', \App\Models\WorkOrder::class)
                    <a href="/jobs" wire:navigate
                       class="flex items-center gap-3 rounded-[var(--radius-md)] px-3 py-2.5 text-sm font-medium {{ request()->is('jobs*') ? 'bg-primary-50 text-primary-700' : 'text-neutral-600 hover:bg-neutral-50' }}">
                        <x-icon name="tools" class="h-4.5 w-4.5" />
                        Jobs
                    </a>
                @endcan
                @can('viewAny', \App\Models\Document::class)
                    <a href="/documents" wire:navigate
                       class="flex items-center gap-3 rounded-[var(--radius-md)] px-3 py-2.5 text-sm font-medium {{ request()->is('documents*') ? 'bg-primary-50 text-primary-700' : 'text-neutral-600 hover:bg-neutral-50' }}">
                        <x-icon name="folder" class="h-4.5 w-4.5" />
                        Documents
                    </a>
                @endcan

                @canany([['viewAny', \App\Models\Quotation::class], ['viewAny', \App\Models\Invoice::class]])
                    <p class="mb-1 mt-6 px-3 text-[11px] font-semibold uppercase tracking-wider text-neutral-400">Sales</p>
                @endcanany
                @can('viewAny', \App\Models\Quotation::class)
                    <a href="/quotations" wire:navigate
                       class="flex items-center gap-3 rounded-[var(--radius-md)] px-3 py-2.5 text-sm font-medium {{ request()->is('quotations*') ? 'bg-primary-50 text-primary-700' : 'text-neutral-600 hover:bg-neutral-50' }}">
                        <x-icon name="journal-text" class="h-4.5 w-4.5" />
                        Quotations
                    </a>
                @endcan
                @can('viewAny', \App\Models\Invoice::class)
                    <a href="/invoices" wire:navigate
                       class="flex items-center gap-3 rounded-[var(--radius-md)] px-3 py-2.5 text-sm font-medium {{ request()->is('invoices*') ? 'bg-primary-50 text-primary-700' : 'text-neutral-600 hover:bg-neutral-50' }}">
                        <x-icon name="receipt" class="h-4.5 w-4.5" />
                        Invoices
                    </a>
                @endcan
            </nav>

            <div class="border-t border-neutral-200 p-3">
                <div class="mb-1 flex items-center gap-2.5 rounded-[var(--radius-md)] px-2 py-2">
                    <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-neutral-900 text-xs font-semibold text-white">
                        {{ collect(explode(' ', auth()->user()->name))->map(fn ($p) => $p[0] ?? '')->take(2)->implode('') }}
                    </div>
                    <div class="min-w-0">
                        <p class="truncate text-sm font-medium text-neutral-900">{{ auth()->user()->name }}</p>
                        <p class="truncate text-xs text-neutral-500">{{ auth()->user()->getRoleNames()->first() }}</p>
                    </div>
                </div>
                <form action="/logout" method="POST">
                    @csrf
                    <button type="submit" class="flex w-full items-center gap-2 rounded-[var(--radius-md)] px-3 py-2 text-sm text-neutral-500 hover:bg-neutral-50 hover:text-neutral-700">
                        <x-icon name="box-arrow-right" class="h-4 w-4" />
                        Sign out
                    </button>
                </form>
            </div>
        </aside>

        <div class="flex min-h-screen flex-1 flex-col">
            {{-- Mobile header --}}
            <header class="flex h-14 items-center justify-between border-b border-neutral-200 bg-white px-4 md:hidden">
                <div class="flex items-center gap-2">
                    <div class="flex h-7 w-7 items-center justify-center rounded-[var(--radius-sm)] bg-primary-500 text-[10px] font-bold text-white">AEA</div>
                    <span class="text-sm font-semibold text-neutral-900">{{ $title ?? 'Service Portal' }}</span>
                </div>
                <form action="/logout" method="POST">
                    @csrf
                    <button type="submit" class="text-neutral-500">
                        <x-icon name="box-arrow-right" class="h-5 w-5" />
                    </button>
                </form>
            </header>

            {{-- Desktop topbar --}}
            <header class="hidden h-16 shrink-0 items-center justify-between border-b border-neutral-200 bg-white px-8 md:flex">
                <h1 class="text-xl font-semibold text-neutral-900">{{ $title ?? 'Overview' }}</h1>
                <div class="flex items-center gap-4">
                    <div class="relative hidden lg:block">
                        <x-icon name="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
                        <input type="text" placeholder="Search" class="w-64 rounded-[var(--radius-md)] border border-neutral-200 bg-neutral-50 py-2 pl-9 pr-3 text-sm text-neutral-700 placeholder:text-neutral-400 focus:border-primary-500 focus:bg-white focus:outline-none focus:ring-1 focus:ring-primary-500">
                    </div>
                    <button type="button" class="flex h-9 w-9 items-center justify-center rounded-full bg-neutral-100 text-neutral-500 hover:bg-neutral-200">
                        <x-icon name="bell" class="h-4.5 w-4.5" />
                    </button>
                    <div class="flex h-9 w-9 items-center justify-center rounded-full bg-neutral-900 text-xs font-semibold text-white">
                        {{ collect(explode(' ', auth()->user()->name))->map(fn ($p) => $p[0] ?? '')->take(2)->implode('') }}
                    </div>
                </div>
            </header>

            <main class="flex-1 px-4 py-6 pb-20 md:px-8 md:py-8 md:pb-8">
                {{ $slot }}
            </main>
        </div>
    </div>

    {{-- Mobile bottom nav --}}
    <nav class="fixed inset-x-0 bottom-0 z-10 flex border-t border-neutral-200 bg-white" style="padding-bottom: env(safe-area-inset-bottom, 0px)">
        <div class="flex w-full md:hidden">
            <a href="/dashboard" wire:navigate
               class="flex flex-1 flex-col items-center gap-0.5 py-2.5 text-xs {{ request()->is('dashboard') ? 'text-primary-600' : 'text-neutral-400' }}">
                <x-icon name="house" class="h-5 w-5" />
                Overview
            </a>
            @can('viewAny', \App\Models\ServiceRequest::class)
                <a href="/requests" wire:navigate
                   class="flex flex-1 flex-col items-center gap-0.5 py-2.5 text-xs {{ request()->is('requests*') ? 'text-primary-600' : 'text-neutral-400' }}">
                    <x-icon name="envelope" class="h-5 w-5" />
                    Requests
                </a>
            @endcan
            @can('viewAny', \App\Models\Quotation::class)
                <a href="/quotations" wire:navigate
                   class="flex flex-1 flex-col items-center gap-0.5 py-2.5 text-xs {{ request()->is('quotations*') ? 'text-primary-600' : 'text-neutral-400' }}">
                    <x-icon name="journal-text" class="h-5 w-5" />
                    Quotes
                </a>
            @endcan
            @can('viewAny', \App\Models\WorkOrder::class)
                <a href="/jobs" wire:navigate
                   class="flex flex-1 flex-col items-center gap-0.5 py-2.5 text-xs {{ request()->is('jobs*') ? 'text-primary-600' : 'text-neutral-400' }}">
                    <x-icon name="tools" class="h-5 w-5" />
                    Jobs
                </a>
            @endcan
            @can('viewAny', \App\Models\Document::class)
                <a href="/documents" wire:navigate
                   class="flex flex-1 flex-col items-center gap-0.5 py-2.5 text-xs {{ request()->is('documents*') ? 'text-primary-600' : 'text-neutral-400' }}">
                    <x-icon name="folder" class="h-5 w-5" />
                    Docs
                </a>
            @endcan
            @can('viewAny', \App\Models\Invoice::class)
                <a href="/invoices" wire:navigate
                   class="flex flex-1 flex-col items-center gap-0.5 py-2.5 text-xs {{ request()->is('invoices*') ? 'text-primary-600' : 'text-neutral-400' }}">
                    <x-icon name="receipt" class="h-5 w-5" />
                    Invoices
                </a>
            @endcan
        </div>
    </nav>

    @livewireScripts
</body>
</html>
