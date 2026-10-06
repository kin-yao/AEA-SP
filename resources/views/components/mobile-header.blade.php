@php
    $mu = auth()->user();
    $groups = \App\Services\Nav::groups($mu);
    $logo = file_exists(public_path('images/aea_logo.svg'));
    $mobileInitials = collect(explode(' ', $mu->name))->map(fn ($p) => $p[0] ?? '')->take(2)->implode('');
@endphp

<header class="sticky top-0 z-30 flex h-14 items-center justify-between px-4 md:hidden"
        style="background: #fff; color: #18181b; border-bottom: 3px solid var(--color-primary-500, #e31e24)"
        x-data="{ open: false }" @keydown.escape.window="open = false" @click.outside="open = false">
    <a href="/dashboard" wire:navigate class="flex items-center gap-2" style="color: #18181b; text-decoration: none">
        @if ($logo)
            <img src="{{ asset('images/aea_logo.svg') }}" alt="AEA Limited" style="height: 1.75rem; width: auto; max-width: 6.5rem">
        @else
            <span style="display: flex; height: 2rem; width: 2rem; align-items: center; justify-content: center; border-radius: 0.5rem; background: var(--color-primary-500, #e31e24); color: #fff; font-size: 0.625rem; font-weight: 800">AEA</span>
        @endif
        <span style="font-size: 0.7rem; font-weight: 700; line-height: 1.15; letter-spacing: 0.04em; color: #3f3f46">AEA Service<br>Operations Hub</span>
    </a>

    <button type="button" @click="open = ! open" aria-label="Open menu" :aria-expanded="open"
            class="flex items-center gap-2 rounded-full"
            style="height: 40px; padding: 0 0.75rem 0 0.25rem; background: var(--color-primary-500, #e31e24); color: #fff; border: 0">
        <span class="flex h-8 w-8 items-center justify-center rounded-full text-xs font-bold" style="background: #fff; color: var(--color-primary-600, #cc1a20)">{{ $mobileInitials }}</span>
        <svg x-show="! open" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
        <svg x-show="open" style="display: none" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>
    </button>

    <div x-show="open" x-transition.opacity.duration.150ms style="display: none; position: absolute; top: calc(100% + 0.5rem); right: 0.75rem; width: min(19rem, calc(100vw - 1.5rem)); max-height: calc(100vh - 5rem); overflow-y: auto; background: #fff; color: #18181b; border: 1px solid #e4e4e7; border-radius: 1rem; box-shadow: 0 12px 32px rgba(15, 15, 15, 0.16); z-index: 40;">
        <div class="border-b border-neutral-100 px-4 py-3">
            <p class="truncate text-sm font-semibold text-neutral-900">{{ $mu->name }}</p>
            <p class="truncate text-xs text-neutral-500">{{ $mu->getRoleNames()->first() }} &middot; {{ $mu->email }}</p>
        </div>

        <nav class="p-2">
            @foreach ($groups as $g)
                @if ($g['title'])
                    <p style="margin: 0.6rem 0.75rem 0.15rem; font-size: 0.68rem; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; color: var(--color-primary-600, #cc1a20)">{{ $g['title'] }}</p>
                @endif
                @foreach ($g['items'] as $i)
                    @php $on = request()->is($i['pattern']); @endphp
                    <a href="{{ $i['href'] }}" wire:navigate @click="open = false"
                       class="flex items-center gap-3 rounded-[var(--radius-md)] px-3 text-sm font-medium"
                       style="min-height: 46px; {{ $on ? 'background: var(--color-primary-500, #e31e24); color: #fff; font-weight: 700' : 'color: #3f3f46' }}">
                        <x-icon :name="$i['icon']" class="h-5 w-5" />
                        {{ $i['label'] }}
                        @if ($i['badge'] > 0)
                            <span style="margin-left: auto; border-radius: 999px; padding: 0.05rem 0.5rem; font-size: 0.7rem; font-weight: 700; {{ $on ? 'background: #fff; color: var(--color-primary-600, #cc1a20)' : 'background: var(--color-primary-500, #e31e24); color: #fff' }}">{{ $i['badge'] }}</span>
                        @endif
                    </a>
                @endforeach
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
