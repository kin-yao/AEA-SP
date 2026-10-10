<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use Livewire\WithPagination;

new #[Layout('layouts.app', ['title' => 'Notifications'])] class extends Component
{
    use WithPagination;

    public function open(int $id): void
    {
        $n = auth()->user()->inbox()->find($id);

        if (! $n) {
            return;
        }

        $n->update(['read_at' => $n->read_at ?? now()]);

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
        return [
            'items' => auth()->user()->inbox()->paginate(20),
            'unread' => auth()->user()->inbox()->whereNull('read_at')->count(),
        ];
    }
};
?>

<div style="max-width: 44rem">
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-xl font-semibold text-neutral-900">Notifications</h1>
        @if ($unread > 0)
            <button type="button" wire:click="markAll" class="btn-outline">Mark all as read ({{ $unread }})</button>
        @endif
    </div>

    <div class="card" style="padding: 0; overflow: hidden">
        @forelse ($items as $n)
            <button type="button" wire:click="open({{ $n->id }})" wire:key="n-{{ $n->id }}"
                    style="display: block; width: 100%; text-align: left; padding: 0.9rem 1.1rem; border: 0; border-bottom: 1px solid #f4f4f5; cursor: pointer; background: {{ $n->read_at ? '#fff' : '#fff7f7' }}">
                <span style="display: flex; justify-content: space-between; gap: 1rem">
                    <span style="font-size: 0.875rem; font-weight: {{ $n->read_at ? '500' : '700' }}; color: #18181b">{{ $n->title }}</span>
                    <span style="flex-shrink: 0; font-size: 0.75rem; color: #a1a1aa">{{ $n->created_at->diffForHumans() }}</span>
                </span>
                @foreach ((array) $n->lines as $line)
                    <span style="display: block; margin-top: 0.2rem; font-size: 0.8125rem; color: #52525b">{{ $line }}</span>
                @endforeach
                @if ($n->url && $n->label)
                    <span style="display: inline-block; margin-top: 0.4rem; font-size: 0.75rem; font-weight: 600; color: var(--color-primary-600, #cc1a20)">{{ $n->label }}</span>
                @endif
            </button>
        @empty
            <p style="padding: 2rem 1rem; text-align: center; font-size: 0.875rem; color: #71717a">You have no notifications yet. Updates about your work will appear here.</p>
        @endforelse
    </div>

    <div class="mt-4">{{ $items->links() }}</div>
</div>
