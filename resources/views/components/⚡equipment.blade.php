<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Models\Equipment;

new #[Layout('layouts.app', ['title' => 'Equipment'])] class extends Component
{
    use \App\Support\ShowsMore;

    public string $search = '';
    public string $statusFilter = 'All';

    public function mount(): void
    {
        $this->authorize('viewAny', Equipment::class);
    }

    public function filter(): void
    {
        // Livewire syncs $search and $statusFilter on this round trip
        // regardless; this method just gives the "Filter" button something
        // to call.
    }

    public function with(): array
    {
        $query = Equipment::with(['customer', 'site']);

        if ($this->search !== '') {
            $term = '%'.$this->search.'%';
            $query->where(function ($q) use ($term) {
                $q->where('serial_number', 'like', $term)
                    ->orWhere('model', 'like', $term)
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', $term))
                    ->orWhereHas('site', fn ($s) => $s->where('name', 'like', $term));
            });
        }

        if ($this->statusFilter !== 'All') {
            // Visit status is worked out from dates in PHP, so filter first, then page.
            $all = $query->orderBy('model')->get()->filter(fn ($e) => $e->visitStatus() === $this->statusFilter)->values();
            $matching = $all->count();
            $equipment = $all->take($this->limit);
        } else {
            $matching = (clone $query)->count();
            $equipment = $query->orderBy('model')->limit($this->limit)->get();
        }

        return [
            'matching' => $matching,
            'equipment' => $equipment->values(),
            'totalEquipment' => Equipment::count(),
        ];
    }
};
?>

<div>
    <div class="mb-6 flex items-center gap-4">
        <h1 class="shrink-0 text-xl font-semibold text-neutral-900">Equipment register</h1>
        <div class="h-px flex-1 border-t border-dashed border-neutral-300"></div>
        @can('create', \App\Models\Equipment::class)
            <a href="/equipment/create" wire:navigate class="btn-primary shrink-0">Register machine</a>
        @endcan
    </div>

    <div class="mb-6 flex flex-wrap items-center gap-3">
        <input wire:model="search" type="text" placeholder="Search serial, model, customer or site" class="input min-w-[260px] flex-1">
        <select wire:model="statusFilter" class="input w-auto">
            <option value="All">All</option>
            <option value="Active">Active</option>
            <option value="Due soon">Due soon</option>
            <option value="Overdue">Overdue</option>
        </select>
        <button type="button" wire:click="filter" class="btn-primary shrink-0">Filter</button>
        <span class="shrink-0 text-sm text-neutral-400">{{ $equipment->count() }} of {{ $totalEquipment }} shown</span>
    </div>

    <div class="space-y-3">
        @forelse ($equipment as $item)
            @php
                $status = $item->visitStatus();
                $pillClass = match ($status) {
                    'Active' => 'pill-success',
                    'Due soon' => 'pill-amber',
                    default => 'pill-danger',
                };
            @endphp
            <div class="card flex items-center gap-4">
                <div class="icon-badge icon-badge-primary">
                    <x-icon name="nut" class="h-5 w-5" />
                </div>
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="rounded-full bg-neutral-900 px-2.5 py-1 font-mono text-xs text-white">{{ $item->serial_number }}</span>
                        <p class="truncate text-sm font-semibold text-neutral-900">{{ $item->model }}</p>
                    </div>
                    <p class="mt-1 truncate text-xs text-neutral-500">
                        {{ $item->customer->name }}
                        @if ($item->site)
                            &middot; {{ $item->site->name }}
                        @endif
                    </p>
                </div>
                <div class="hidden shrink-0 text-right sm:block">
                    <p class="text-xs text-neutral-500">Warranty</p>
                    <p class="text-sm text-neutral-800">
                        {{ $item->warranty_expires_at ? ($item->warranty_expires_at->isPast() ? 'Expired' : 'To '.$item->warranty_expires_at->format('M Y')) : '—' }}
                    </p>
                </div>
                <div class="hidden shrink-0 text-right md:block">
                    <p class="text-xs text-neutral-500">Next visit</p>
                    <p class="text-sm text-neutral-800">{{ $item->next_visit_due_at?->format('d M Y') ?? 'Not set' }}</p>
                </div>
                <span class="{{ $pillClass }} shrink-0">{{ $status }}</span>
                <div class="flex shrink-0 gap-2">
                    <a href="/equipment/{{ $item->id }}" wire:navigate class="btn-outline">View</a>
                    @can('update', $item)
                        <a href="/equipment/{{ $item->id }}/edit" wire:navigate class="btn-outline">Edit</a>
                    @endcan
                </div>
            </div>
        @empty
            <div class="card text-center text-sm text-neutral-500">No equipment registered yet.</div>
        @endforelse
    </div>
    <x-show-more :shown="$equipment->count()" :total="$matching" />
</div>
