<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'AEA Service Portal' }}</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        /* rf: service report form */
        .rf { max-width: 42rem; margin: 0 auto; }
        .rf .input { font-size: 16px; line-height: 1.4; padding: 0.75rem 0.875rem; border-radius: 0.75rem; background-color: #fff; }
        .rf input.input, .rf select.input { min-height: 48px; }
        .rf .input:disabled { background-color: #f4f4f5; color: #52525b; }
        .rf .label { font-size: 0.8125rem; font-weight: 600; color: #3f3f46; margin-bottom: 0.375rem; }
        .rf-opt { font-weight: 500; color: #a1a1aa; margin-left: 0.25rem; }
        .rf-grid { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); }
        .rf-grid2 { display: grid; gap: 0.75rem; grid-template-columns: 1fr 1fr; }

        .rf-hero { position: relative; overflow: hidden; background: #18181b; color: #fff; border-radius: 1.25rem; padding: 1.25rem 1.125rem 1.125rem; }
        .rf-hero::before { content: ''; position: absolute; left: 0; right: 0; top: 0; height: 4px; background: #f5a623; }
        .rf-hero-label { font-size: 0.6875rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.07em; color: #a1a1aa; }
        .rf-hero-no { font-size: 2rem; font-weight: 800; line-height: 1.1; color: #f5a623; margin-top: 0.125rem; }
        .rf-hero-grid { display: grid; gap: 0.875rem 1rem; grid-template-columns: 1fr 1fr; margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #3f3f46; }
        .rf-hero-val { font-size: 0.9375rem; font-weight: 700; margin-top: 0.125rem; word-break: break-word; }
        .rf-status { flex-shrink: 0; background: rgba(245, 166, 35, 0.18); color: #f5a623; border-radius: 999px; padding: 0.3125rem 0.75rem; font-size: 0.75rem; font-weight: 700; }

        .rf-sec { background: #fff; border: 1px solid rgba(228, 228, 231, 0.9); border-radius: 1.25rem; box-shadow: var(--shadow-card); overflow: hidden; }
        .rf-sec-head { display: flex; align-items: center; gap: 0.75rem; padding: 0.875rem 1.125rem; background: #fafafa; border-bottom: 1px solid #f0f0f2; }
        .rf-badge { display: flex; align-items: center; justify-content: center; flex-shrink: 0; width: 2.125rem; height: 2.125rem; border-radius: 0.75rem; background: #fef2f2; color: #a8151a; font-size: 0.875rem; font-weight: 800; }
        .rf-sec-title { font-size: 0.9375rem; font-weight: 700; color: #18181b; line-height: 1.2; }
        .rf-sec-sub { font-size: 0.75rem; color: #71717a; margin-top: 0.125rem; }
        .rf-body { display: flex; flex-direction: column; gap: 1rem; padding: 1.125rem; }

        .rf-chips { display: grid; grid-template-columns: 1fr 1fr; gap: 0.625rem; }
        .rf-chip { display: flex; align-items: center; gap: 0.625rem; min-height: 56px; padding: 0.75rem 0.875rem; border: 1.5px solid #e4e4e7; border-radius: 0.875rem; background: #fff; font-size: 0.9375rem; font-weight: 600; line-height: 1.2; color: #3f3f46; cursor: pointer; }
        .rf-chip input { flex-shrink: 0; width: 1.25rem; height: 1.25rem; accent-color: #e31e24; }
        .rf-chip-on { border-color: #e31e24; background: #fef2f2; color: #a8151a; }

        .rf-part { border: 1px solid #e4e4e7; border-radius: 1rem; background: #fafafa; padding: 0.875rem; }
        .rf-part-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.75rem; font-size: 0.75rem; font-weight: 700; letter-spacing: 0.05em; text-transform: uppercase; color: #71717a; }
        .rf-add { min-height: 48px; border: 1.5px dashed #d4d4d8; border-radius: 0.875rem; background: #fff; font-size: 0.9375rem; font-weight: 700; color: #a8151a; cursor: pointer; }
        .rf-add:hover { background: #fef2f2; border-color: #e31e24; }
        .rf-remove { font-size: 0.8125rem; font-weight: 700; color: #71717a; padding: 0.375rem 0.625rem; border-radius: 0.5rem; cursor: pointer; text-transform: none; letter-spacing: 0; }
        .rf-remove:hover { color: #a71d2a; background: #fbe9eb; }

        .rf-drop { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 0.25rem; min-height: 124px; padding: 1.25rem 1rem; text-align: center; border: 2px dashed #d4d4d8; border-radius: 1rem; background: #fafafa; cursor: pointer; }
        .rf-drop:hover { background: #fef2f2; border-color: #e31e24; }
        .rf-drop-title { font-size: 0.9375rem; font-weight: 700; color: #18181b; }
        .rf-drop-sub { font-size: 0.8125rem; color: #71717a; }
        .rf-file { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; padding: 0.75rem 0.875rem; border: 1px solid #aef5a3; border-radius: 0.875rem; background: #eefdec; min-width: 0; }
        .rf-note { padding: 0.875rem 1rem; border-radius: 0.875rem; background: #f4f4f5; font-size: 0.875rem; color: #52525b; }

        .rf-sign { position: relative; overflow: hidden; border: 2px dashed #d4d4d8; border-radius: 1rem; background: #fff; }
        .rf-sign-line { position: absolute; left: 1.25rem; right: 1.25rem; bottom: 2.25rem; height: 1px; background: #d4d4d8; z-index: 0; }
        .rf-sign-hint { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; text-align: center; padding: 0 1rem; font-size: 0.9375rem; color: #a1a1aa; pointer-events: none; z-index: 2; }
        .rf-sign-sub { font-size: 0.75rem; color: #a1a1aa; }
        .rf-signed { font-size: 0.8125rem; font-weight: 700; color: #189913; }

        .rf-err { margin-top: 0.375rem; font-size: 0.8125rem; font-weight: 600; color: #a71d2a; }

        .rf-bar { position: sticky; bottom: 0; z-index: 20; margin: 1rem -1rem 0; padding: 0.75rem 1rem calc(0.75rem + env(safe-area-inset-bottom, 0px)); background: rgba(255, 255, 255, 0.96); backdrop-filter: blur(8px); border-top: 1px solid #e4e4e7; }
        .rf-bar-hint { margin-bottom: 0.5rem; text-align: center; font-size: 0.75rem; color: #71717a; }
        .rf-bar-row { display: flex; gap: 0.625rem; }
        .rf-bar .btn-outline, .rf-bar .btn-primary { min-height: 50px; font-size: 0.9375rem; font-weight: 700; }
        @media (min-width: 768px) {
            .rf-bar { margin: 1rem 0 0; border: 1px solid #e4e4e7; border-radius: 1rem; }
        }
        @media (max-width: 380px) {
            .rf-chips { grid-template-columns: 1fr; }
        }
    </style>
    @livewireStyles
</head>
<body class="min-h-screen bg-[#f7f7f8] antialiased">

    <div class="md:flex md:min-h-screen">
        {{-- Desktop sidebar --}}
        <aside class="hidden md:sticky md:top-0 md:flex md:h-screen md:w-64 md:flex-col md:border-r md:border-neutral-200 md:bg-white">
            <div class="flex h-16 items-center gap-2.5 border-b border-neutral-200 px-5">
                <div class="flex h-9 w-9 items-center justify-center rounded-[var(--radius-sm)] bg-primary-500 text-xs font-bold text-white">AEA</div>
                <span class="text-sm font-semibold text-neutral-900">Service Portal</span>
            </div>

            <nav class="flex-1 space-y-0.5 overflow-y-auto px-3 py-5">
                @if (auth()->user()->hasRole('Technician'))
                    <a href="/dashboard" wire:navigate
                       class="flex items-center gap-3 rounded-[var(--radius-md)] px-3 py-2.5 text-sm font-medium {{ request()->is('dashboard') ? 'bg-primary-50 text-primary-700' : 'text-neutral-600 hover:bg-neutral-50' }}">
                        <x-icon name="house" class="h-4.5 w-4.5" />
                        My day
                    </a>
                    <a href="/jobs" wire:navigate
                       class="flex items-center gap-3 rounded-[var(--radius-md)] px-3 py-2.5 text-sm font-medium {{ request()->is('jobs*') ? 'bg-primary-50 text-primary-700' : 'text-neutral-600 hover:bg-neutral-50' }}">
                        <x-icon name="tools" class="h-4.5 w-4.5" />
                        My jobs
                    </a>
                    <a href="/service-report" wire:navigate
                       class="flex items-center gap-3 rounded-[var(--radius-md)] px-3 py-2.5 text-sm font-medium {{ request()->is('service-report*') ? 'bg-primary-50 text-primary-700' : 'text-neutral-600 hover:bg-neutral-50' }}">
                        <x-icon name="file-earmark-text" class="h-4.5 w-4.5" />
                        Service report
                    </a>
                    <a href="/schedule" wire:navigate
                       class="flex items-center gap-3 rounded-[var(--radius-md)] px-3 py-2.5 text-sm font-medium {{ request()->is('schedule*') ? 'bg-primary-50 text-primary-700' : 'text-neutral-600 hover:bg-neutral-50' }}">
                        <x-icon name="calendar-week" class="h-4.5 w-4.5" />
                        My schedule
                    </a>
                    <a href="/my-reports" wire:navigate
                       class="flex items-center gap-3 rounded-[var(--radius-md)] px-3 py-2.5 text-sm font-medium {{ request()->is('my-reports*') ? 'bg-primary-50 text-primary-700' : 'text-neutral-600 hover:bg-neutral-50' }}">
                        <x-icon name="journal-text" class="h-4.5 w-4.5" />
                        My reports
                    </a>
                    <a href="/my-equipment" wire:navigate
                       class="flex items-center gap-3 rounded-[var(--radius-md)] px-3 py-2.5 text-sm font-medium {{ request()->is('my-equipment*') ? 'bg-primary-50 text-primary-700' : 'text-neutral-600 hover:bg-neutral-50' }}">
                        <x-icon name="nut" class="h-4.5 w-4.5" />
                        Equipment
                    </a>
                    <a href="/inventory" wire:navigate
                       class="flex items-center gap-3 rounded-[var(--radius-md)] px-3 py-2.5 text-sm font-medium {{ request()->is('inventory*') ? 'bg-primary-50 text-primary-700' : 'text-neutral-600 hover:bg-neutral-50' }}">
                        <x-icon name="box-seam" class="h-4.5 w-4.5" />
                        Parts
                    </a>
                @else
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
                @if (auth()->user()->hasAnyRole(['Manager', 'Supervisor', 'Service Admin']))
                    <a href="/dispatch" wire:navigate
                       class="flex items-center gap-3 rounded-[var(--radius-md)] px-3 py-2.5 text-sm font-medium {{ request()->is('dispatch*') ? 'bg-primary-50 text-primary-700' : 'text-neutral-600 hover:bg-neutral-50' }}">
                        <x-icon name="truck" class="h-4.5 w-4.5" />
                        Dispatch
                    </a>
                    <a href="/technicians" wire:navigate
                       class="flex items-center gap-3 rounded-[var(--radius-md)] px-3 py-2.5 text-sm font-medium {{ request()->is('technicians*') ? 'bg-primary-50 text-primary-700' : 'text-neutral-600 hover:bg-neutral-50' }}">
                        <x-icon name="person-standing" class="h-4.5 w-4.5" />
                        Technicians
                    </a>
                @endif
                @can('viewAny', \App\Models\Customer::class)
                    <a href="/customers" wire:navigate
                       class="flex items-center gap-3 rounded-[var(--radius-md)] px-3 py-2.5 text-sm font-medium {{ request()->is('customers*') ? 'bg-primary-50 text-primary-700' : 'text-neutral-600 hover:bg-neutral-50' }}">
                        <x-icon name="people" class="h-4.5 w-4.5" />
                        Customers
                    </a>
                @endcan
                @can('viewAny', \App\Models\Contract::class)
                    <a href="/contracts" wire:navigate
                       class="flex items-center gap-3 rounded-[var(--radius-md)] px-3 py-2.5 text-sm font-medium {{ request()->is('contracts*') ? 'bg-primary-50 text-primary-700' : 'text-neutral-600 hover:bg-neutral-50' }}">
                        <x-icon name="chevron-bar-contract" class="h-4.5 w-4.5" />
                        {{ auth()->user()->hasRole('Customer') ? 'My contract' : 'Contracts' }}
                    </a>
                @endcan
                @can('viewAny', \App\Models\Equipment::class)
                    <a href="/equipment" wire:navigate
                       class="flex items-center gap-3 rounded-[var(--radius-md)] px-3 py-2.5 text-sm font-medium {{ request()->is('equipment*') ? 'bg-primary-50 text-primary-700' : 'text-neutral-600 hover:bg-neutral-50' }}">
                        <x-icon name="nut" class="h-4.5 w-4.5" />
                        Equipment
                    </a>
                @endcan
                @can('viewAny', \App\Models\InventoryItem::class)
                    <a href="/inventory" wire:navigate
                       class="flex items-center gap-3 rounded-[var(--radius-md)] px-3 py-2.5 text-sm font-medium {{ request()->is('inventory*') ? 'bg-primary-50 text-primary-700' : 'text-neutral-600 hover:bg-neutral-50' }}">
                        <x-icon name="cart-check" class="h-4.5 w-4.5" />
                        Inventory
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

                @can('viewAny', \App\Models\User::class)
                    <p class="mb-1 mt-6 px-3 text-[11px] font-semibold uppercase tracking-wider text-neutral-400">Admin</p>
                    <a href="/users" wire:navigate
                       class="flex items-center gap-3 rounded-[var(--radius-md)] px-3 py-2.5 text-sm font-medium {{ request()->is('users*') ? 'bg-primary-50 text-primary-700' : 'text-neutral-600 hover:bg-neutral-50' }}">
                        <x-icon name="people" class="h-4.5 w-4.5" />
                        Accounts
                    </a>
                @endcan
            
                @endif
            </nav>

            <div class="relative border-t border-neutral-200 p-3" x-data="{ open: false }" @click.outside="open = false">
                <button type="button" @click="open = ! open"
                        class="flex w-full items-center gap-2.5 rounded-[var(--radius-md)] px-2 py-2 text-left hover:bg-neutral-50">
                    <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-neutral-900 text-xs font-semibold text-white">
                        {{ collect(explode(' ', auth()->user()->name))->map(fn ($p) => $p[0] ?? '')->take(2)->implode('') }}
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium text-neutral-900">{{ auth()->user()->name }}</p>
                        <p class="truncate text-xs text-neutral-500">{{ auth()->user()->getRoleNames()->first() }}</p>
                    </div>
                    <span class="shrink-0 text-xs text-neutral-400" x-text="open ? '▲' : '▼'"></span>
                </button>

                <div x-show="open" x-transition style="display: none"
                     class="absolute bottom-full left-3 right-3 mb-2 overflow-hidden rounded-[var(--radius-md)] border border-neutral-200 bg-white py-1 shadow-[var(--shadow-card-hover)]">
                    <p class="truncate px-3 py-2 text-xs text-neutral-400">{{ auth()->user()->email }}</p>
                    <a href="/change-password" wire:navigate @click="open = false"
                       class="flex items-center gap-2 px-3 py-2 text-sm text-neutral-600 hover:bg-neutral-50">
                        <x-icon name="lock" class="h-4 w-4" />
                        Change password
                    </a>
                    <form action="/logout" method="POST">
                        @csrf
                        <button type="submit" class="flex w-full items-center gap-2 px-3 py-2 text-sm text-neutral-500 hover:bg-neutral-50 hover:text-neutral-700">
                            <x-icon name="box-arrow-right" class="h-4 w-4" />
                            Sign out
                        </button>
                    </form>
                </div>
            </div>
        </aside>

        <div class="flex min-h-screen flex-1 flex-col">
            {{-- Mobile menu --}}
            @php
                $mu = auth()->user();

                if ($mu->hasRole('Technician')) {
                    $mobileLinks = [
                        ['/dashboard', 'dashboard', 'house', 'My day'],
                        ['/jobs', 'jobs*', 'tools', 'My jobs'],
                        ['/service-report', 'service-report*', 'file-earmark-text', 'Service report'],
                        ['/schedule', 'schedule*', 'calendar-week', 'My schedule'],
                        ['/my-reports', 'my-reports*', 'journal-text', 'My reports'],
                ['/my-equipment', 'my-equipment*', 'nut', 'Equipment'],
                        ['/inventory', 'inventory*', 'box-seam', 'Parts'],
                    ];
                } else {
                    $mobileLinks = [['/dashboard', 'dashboard', 'house', 'Overview']];

                    if ($mu->can('viewAny', \App\Models\ServiceRequest::class)) { $mobileLinks[] = ['/requests', 'requests*', 'envelope', 'Requests']; }
                    if ($mu->can('viewAny', \App\Models\WorkOrder::class)) { $mobileLinks[] = ['/jobs', 'jobs*', 'tools', 'Jobs']; }
                    if ($mu->can('viewAny', \App\Models\Document::class)) { $mobileLinks[] = ['/documents', 'documents*', 'folder', 'Documents']; }
                    if ($mu->hasAnyRole(['Manager', 'Supervisor', 'Service Admin'])) {
                        $mobileLinks[] = ['/dispatch', 'dispatch*', 'truck', 'Dispatch'];
                        $mobileLinks[] = ['/technicians', 'technicians*', 'person-standing', 'Technicians'];
                    }
                    if ($mu->can('viewAny', \App\Models\Customer::class)) { $mobileLinks[] = ['/customers', 'customers*', 'people', 'Customers']; }
                    if ($mu->can('viewAny', \App\Models\Contract::class)) { $mobileLinks[] = ['/contracts', 'contracts*', 'chevron-bar-contract', $mu->hasRole('Customer') ? 'My contract' : 'Contracts']; }
                    if ($mu->can('viewAny', \App\Models\Equipment::class)) { $mobileLinks[] = ['/equipment', 'equipment*', 'nut', 'Equipment']; }
                    if ($mu->can('viewAny', \App\Models\InventoryItem::class)) { $mobileLinks[] = ['/inventory', 'inventory*', 'cart-check', 'Inventory']; }
                    if ($mu->can('viewAny', \App\Models\Quotation::class)) { $mobileLinks[] = ['/quotations', 'quotations*', 'journal-text', 'Quotations']; }
                    if ($mu->can('viewAny', \App\Models\Invoice::class)) { $mobileLinks[] = ['/invoices', 'invoices*', 'receipt', 'Invoices']; }
                    if ($mu->can('viewAny', \App\Models\User::class)) { $mobileLinks[] = ['/users', 'users*', 'people', 'Accounts']; }
                }

                $mobileInitials = collect(explode(' ', $mu->name))->map(fn ($p) => $p[0] ?? '')->take(2)->implode('');
            @endphp
            <header class="sticky top-0 z-30 flex h-14 items-center justify-between border-b border-neutral-200 bg-white px-4 md:hidden"
                    x-data="{ open: false }" @keydown.escape.window="open = false" @click.outside="open = false">
                <a href="/dashboard" wire:navigate class="flex items-center gap-2">
                    <div class="flex h-8 w-8 items-center justify-center rounded-[var(--radius-sm)] bg-primary-500 text-[10px] font-bold text-white">AEA</div>
                    <span class="text-sm font-semibold text-neutral-900">Service Portal</span>
                </a>

                <button type="button" @click="open = ! open" aria-label="Open menu" :aria-expanded="open"
                        class="flex items-center gap-2 rounded-full border border-neutral-200 bg-white hover:bg-neutral-50"
                        style="height: 40px; padding: 0 0.75rem 0 0.25rem">
                    <span class="flex h-8 w-8 items-center justify-center rounded-full bg-neutral-900 text-xs font-semibold text-white">{{ $mobileInitials }}</span>
                    <svg x-show="! open" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" class="text-neutral-700"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
                    <svg x-show="open" style="display: none" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" class="text-neutral-700"><path d="M6 6l12 12M18 6L6 18"/></svg>
                </button>

                <div x-show="open" x-transition.opacity.duration.150ms style="display: none; position: absolute; top: calc(100% + 0.5rem); right: 0.75rem; width: min(19rem, calc(100vw - 1.5rem)); max-height: calc(100vh - 5rem); overflow-y: auto; background: #fff; border: 1px solid #e4e4e7; border-radius: 1rem; box-shadow: 0 12px 32px rgba(15, 15, 15, 0.16); z-index: 40;">
                    <div class="border-b border-neutral-100 px-4 py-3">
                        <p class="truncate text-sm font-semibold text-neutral-900">{{ $mu->name }}</p>
                        <p class="truncate text-xs text-neutral-500">{{ $mu->getRoleNames()->first() }} &middot; {{ $mu->email }}</p>
                    </div>

                    <nav class="p-2">
                        @foreach ($mobileLinks as [$href, $pattern, $icon, $label])
                            <a href="{{ $href }}" wire:navigate @click="open = false"
                               class="flex items-center gap-3 rounded-[var(--radius-md)] px-3 text-sm font-medium {{ request()->is($pattern) ? 'bg-primary-50 text-primary-700' : 'text-neutral-700 hover:bg-neutral-50' }}"
                               style="min-height: 48px">
                                <x-icon :name="$icon" class="h-5 w-5" />
                                {{ $label }}
                            </a>
                        @endforeach
                    </nav>

                    <div class="border-t border-neutral-100 p-2">
                        <a href="/change-password" wire:navigate @click="open = false"
                           class="flex items-center gap-3 rounded-[var(--radius-md)] px-3 text-sm font-medium text-neutral-700 hover:bg-neutral-50"
                           style="min-height: 48px">
                            <x-icon name="lock" class="h-5 w-5" />
                            Change password
                        </a>
                        <form action="/logout" method="POST">
                            @csrf
                            <button type="submit"
                                    class="flex w-full items-center gap-3 rounded-[var(--radius-md)] px-3 text-sm font-medium text-neutral-700 hover:bg-neutral-50"
                                    style="min-height: 48px">
                                <x-icon name="box-arrow-right" class="h-5 w-5" />
                                Sign out
                            </button>
                        </form>
                    </div>
                </div>
            </header>

            {{-- Desktop topbar --}}
            <header class="hidden h-16 shrink-0 items-center justify-end border-b border-neutral-200 bg-white px-8 md:flex">
                <div class="flex items-center gap-4">
                    <button type="button" class="flex h-9 w-9 items-center justify-center rounded-full bg-neutral-100 text-neutral-500 hover:bg-neutral-200">
                        <x-icon name="bell" class="h-4.5 w-4.5" />
                    </button>
                </div>
            </header>

            <main class="flex-1 px-4 py-6 pb-8 md:px-8 md:py-8 md:pb-8">
                @if (auth()->user()->hasRole('Customer') && ! auth()->user()->hasVerifiedEmail())
                    <div class="mb-4 flex items-center justify-between rounded-[var(--radius-md)] border border-amber-200 bg-amber-50 px-4 py-2.5 text-sm text-amber-800">
                        <span>Please verify your email address ({{ auth()->user()->email }}).</span>
                        <a href="/email/verify" wire:navigate class="font-semibold underline hover:no-underline">Verify now</a>
                    </div>
                @endif

                {{ $slot }}
            </main>
        </div>
    </div>

    @livewireScripts
</body>
</html>
