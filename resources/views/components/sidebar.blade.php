@php
    $u = auth()->user();
    $groups = \App\Services\Nav::groups($u);
    $logo = file_exists(public_path('images/aea_logo.svg'));
    $initials = collect(explode(' ', $u->name))->map(fn ($p) => $p[0] ?? '')->take(2)->implode('');
@endphp

<style>
    .sb { background: var(--color-primary-500, #e31e24); color: #fff; }
    .sb-link { display: flex; align-items: center; gap: 0.75rem; border-radius: 0.6rem; padding: 0.6rem 0.75rem; font-size: 0.875rem; font-weight: 500; color: rgba(255, 255, 255, 0.92); text-decoration: none; transition: background 0.12s; }
    .sb-link:hover { background: rgba(255, 255, 255, 0.14); color: #fff; }
    .sb-link.is-active { background: #fff; color: var(--color-primary-600, #cc1a20); font-weight: 700; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.18); }
    .sb-sub { margin: 0.1rem 0 0.35rem 1.1rem; padding-left: 0.5rem; border-left: 1px solid rgba(255, 255, 255, 0.28); }
    .sb-head { display: flex; width: 100%; align-items: center; gap: 0.5rem; border-radius: 0.5rem; padding: 0.55rem 0.75rem; font-size: 0.72rem; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; color: rgba(255, 255, 255, 0.78); background: none; border: 0; cursor: pointer; text-align: left; }
    .sb-head:hover { color: #fff; background: rgba(255, 255, 255, 0.08); }
    .sb-badge { margin-left: auto; min-width: 1.35rem; border-radius: 999px; padding: 0.05rem 0.45rem; text-align: center; font-size: 0.7rem; font-weight: 700; background: #fff; color: var(--color-primary-600, #cc1a20); }
    .sb-link.is-active .sb-badge { background: var(--color-primary-500, #e31e24); color: #fff; }
    .sb-chev { margin-left: auto; transition: transform 0.15s; }
    .sb-scroll::-webkit-scrollbar { width: 6px; }
    .sb-scroll::-webkit-scrollbar-thumb { background: rgba(255, 255, 255, 0.3); border-radius: 3px; }
</style>

{{-- Desktop sidebar --}}
<aside class="sb hidden md:sticky md:top-0 md:flex md:h-screen md:w-64 md:shrink-0 md:flex-col">
    <a href="/dashboard" wire:navigate style="display: flex; height: 4.5rem; align-items: center; gap: 0.75rem; padding: 0 1.25rem; background: #fff; border-bottom: 1px solid #e4e4e7; text-decoration: none; color: #18181b">
        @if ($logo)
            <img src="{{ asset('images/aea_logo.svg') }}" alt="AEA Limited" style="height: 2.5rem; width: auto; max-width: 12rem">
        @else
            <span style="display: flex; height: 2.25rem; width: 2.25rem; align-items: center; justify-content: center; border-radius: 0.5rem; background: var(--color-primary-500, #e31e24); color: #fff; font-size: 0.75rem; font-weight: 800">AEA</span>
        @endif
    </a>

    <nav class="sb-scroll flex-1 overflow-y-auto" style="padding: 1rem 0.75rem; display: flex; flex-direction: column; gap: 0.15rem">
        @foreach ($groups as $g)
            @php
                $active = collect($g['items'])->contains(fn ($i) => request()->is($i['pattern']));
                $sum = collect($g['items'])->sum('badge');
            @endphp

            @if ($g['title'] === null)
                @foreach ($g['items'] as $i)
                    <a href="{{ $i['href'] }}" wire:navigate class="sb-link {{ request()->is($i['pattern']) ? 'is-active' : '' }}">
                        <x-icon :name="$i['icon']" class="h-4.5 w-4.5" />
                        {{ $i['label'] }}
                        @if ($i['badge'] > 0)<span class="sb-badge">{{ $i['badge'] }}</span>@endif
                    </a>
                @endforeach
                <div style="height: 0.5rem"></div>
            @else
                <div x-data="{ open: {{ $active ? 'true' : 'false' }} }">
                    <button type="button" class="sb-head" @click="open = ! open" :aria-expanded="open">
                        <span>{{ $g['title'] }}</span>
                        @if ($sum > 0)
                            <span class="sb-badge" x-show="! open" style="margin-left: 0.25rem">{{ $sum }}</span>
                        @endif
                        <svg class="sb-chev" :style="open ? 'transform: rotate(180deg)' : ''" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>
                    </button>
                    <div class="sb-sub" x-show="open" x-collapse @if (! $active) style="display: none" @endif>
                        @foreach ($g['items'] as $i)
                            <a href="{{ $i['href'] }}" wire:navigate class="sb-link {{ request()->is($i['pattern']) ? 'is-active' : '' }}">
                                <x-icon :name="$i['icon']" class="h-4.5 w-4.5" />
                                {{ $i['label'] }}
                                @if ($i['badge'] > 0)<span class="sb-badge">{{ $i['badge'] }}</span>@endif
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif
        @endforeach
    </nav>

    <div class="relative p-3" style="background: #2f2f2f url('{{ asset('images/aea_pattern.png') }}') repeat-x left center / auto 100%; border-top: 3px solid var(--color-primary-500, #e31e24)" x-data="{ open: false }" @click.outside="open = false">
        <button type="button" @click="open = ! open"
                class="flex w-full items-center gap-2.5 rounded-[var(--radius-md)] px-2 py-2 text-left sb-link" style="padding: 0.5rem">
            <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-xs font-bold" style="background: #fff; color: var(--color-primary-600, #cc1a20)">{{ $initials }}</div>
            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-semibold" style="color: #fff">{{ $u->name }}</p>
                <p class="truncate text-xs" style="color: rgba(255, 255, 255, 0.78)">{{ $u->getRoleNames()->first() }}</p>
            </div>
            <svg :style="open ? 'transform: rotate(180deg)' : ''" style="transition: transform 0.15s" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18 15l-6-6-6 6"/></svg>
        </button>

        <div x-show="open" x-transition style="display: none"
             class="absolute bottom-full left-3 right-3 mb-2 overflow-hidden rounded-[var(--radius-md)] border border-neutral-200 bg-white py-1 shadow-[var(--shadow-card-hover)]">
            <p class="truncate px-3 py-2 text-xs text-neutral-400">{{ $u->email }}</p>
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
