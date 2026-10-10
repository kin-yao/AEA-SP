<?php

use Livewire\Component;
use App\Models\InboxNotification;

new class extends Component
{
    public bool $mobile = false;

    public function mark(int $id): void
    {
        auth()->user()->inbox()->whereKey($id)->whereNull('read_at')->update(['read_at' => now()]);
    }

    /** Open one notification: mark it read, then go to what it points at. */
    public function open(int $id): void
    {
        $n = auth()->user()->inbox()->find($id);

        if (! $n) {
            return;
        }

        $this->mark($id);

        if ($n->url) {
            $this->redirect($n->url, navigate: true);
        }
    }

    public function markAll(): void
    {
        auth()->user()->inbox()->whereNull('read_at')->update(['read_at' => now()]);
    }

    public function with(): array
    {
        $user = auth()->user();

        return [
            'unread' => $user->inbox()->whereNull('read_at')->count(),
            'items' => $user->inbox()->limit(8)->get(),
        ];
    }
};
?>

<div class="relative" wire:poll.30s x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="open = false">
    <button type="button" @click="open = ! open" aria-label="Notifications" :aria-expanded="open"
            class="relative flex h-9 w-9 items-center justify-center rounded-full"
            style="background: {{ $mobile ? '#f4f4f5' : 'rgba(255, 255, 255, 0.16)' }}; color: {{ $mobile ? '#3f3f46' : '#fff' }}; border: 0; cursor: pointer">
        <x-icon name="bell" class="h-4.5 w-4.5" />
        @if ($unread > 0)
            <span style="position: absolute; top: -4px; right: -4px; min-width: 1.15rem; height: 1.15rem; padding: 0 0.3rem; border-radius: 999px; background: #f5a623; color: #18181b; font-size: 0.65rem; font-weight: 800; line-height: 1.15rem; text-align: center">{{ $unread > 99 ? '99+' : $unread }}</span>
        @endif
    </button>

    <div x-show="open" x-transition.opacity.duration.120ms style="display: none; position: absolute; right: 0; top: calc(100% + 0.5rem); width: min(23rem, calc(100vw - 1.5rem)); background: #fff; color: #18181b; border: 1px solid #e4e4e7; border-radius: 1rem; box-shadow: 0 12px 32px rgba(15, 15, 15, 0.18); z-index: 50; overflow: hidden">
        <div style="display: flex; align-items: center; justify-content: space-between; padding: 0.75rem 1rem; border-bottom: 1px solid #f0f0f2">
            <span style="font-size: 0.875rem; font-weight: 700">Notifications</span>
            @if ($unread > 0)
                <button type="button" wire:click="markAll" style="font-size: 0.75rem; font-weight: 600; color: var(--color-primary-600, #cc1a20); background: none; border: 0; cursor: pointer">Mark all as read</button>
            @endif
        </div>

        <div style="max-height: 24rem; overflow-y: auto">
            @forelse ($items as $n)
                <button type="button" wire:click="open({{ $n->id }})" wire:key="bell-{{ $n->id }}"
                        style="display: block; width: 100%; text-align: left; padding: 0.7rem 1rem; border: 0; border-bottom: 1px solid #f4f4f5; cursor: pointer; background: {{ $n->read_at ? '#fff' : '#fff7f7' }}">
                    <span style="display: flex; align-items: flex-start; gap: 0.5rem">
                        @unless ($n->read_at)
                            <span style="margin-top: 0.4rem; width: 0.5rem; height: 0.5rem; flex-shrink: 0; border-radius: 999px; background: var(--color-primary-500, #e31e24)"></span>
                        @endunless
                        <span style="min-width: 0">
                            <span style="display: block; font-size: 0.8125rem; font-weight: {{ $n->read_at ? '500' : '700' }}; color: #18181b">{{ $n->title }}</span>
                            @if (! empty($n->lines[0]))
                                <span style="display: block; margin-top: 0.15rem; font-size: 0.75rem; color: #52525b; overflow: hidden; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical">{{ $n->lines[0] }}</span>
                            @endif
                            <span style="display: block; margin-top: 0.2rem; font-size: 0.6875rem; color: #a1a1aa">{{ $n->created_at->diffForHumans() }}</span>
                        </span>
                    </span>
                </button>
            @empty
                <p style="padding: 1.5rem 1rem; text-align: center; font-size: 0.8125rem; color: #71717a">Nothing new. You are all caught up.</p>
            @endforelse
        </div>

        <a href="/notifications" wire:navigate @click="open = false" style="display: block; padding: 0.65rem 1rem; text-align: center; font-size: 0.8125rem; font-weight: 600; color: var(--color-primary-600, #cc1a20); border-top: 1px solid #f0f0f2; text-decoration: none">See all notifications</a>
    </div>
</div>
