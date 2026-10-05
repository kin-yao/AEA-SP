<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Models\Equipment;
use App\Models\WorkOrder;

new #[Layout('layouts.app', ['title' => 'My equipment'])] class extends Component
{
    public string $search = '';
    public string $statusFilter = 'All';

    public function mount(): void
    {
        abort_unless(auth()->user()->hasRole('Technician'), 403);
    }

    public function filter(): void
    {
        // Livewire syncs $search and $statusFilter on this round trip
        // regardless; this method just gives the "Filter" button something
        // to call.
    }

    public function with(): array
    {
        // Every machine that appears on a job assigned to this technician.
        $jobs = WorkOrder::where('assigned_technician_id', auth()->id())
            ->whereNotNull('equipment_id')
            ->get(['id', 'equipment_id', 'status', 'due_date']);

        $byMachine = $jobs->groupBy('equipment_id');

        $query = Equipment::with(['customer', 'site'])->whereIn('id', $byMachine->keys());

        if ($this->search !== '') {
            $term = '%'.$this->search.'%';
            $query->where(function ($q) use ($term) {
                $q->where('serial_number', 'like', $term)
                    ->orWhere('model', 'like', $term)
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', $term))
                    ->orWhereHas('site', fn ($s) => $s->where('name', 'like', $term));
            });
        }

        $machines = $query->orderBy('model')->get()->map(function (Equipment $e) use ($byMachine) {
            $mine = $byMachine[$e->id];
            $past = $mine->filter(fn ($j) => $j->due_date->lte(today()))->sortByDesc('due_date')->first();

            return [
                'equipment' => $e,
                'status' => $e->visitStatus(),
                'visits' => $mine->count(),
                'last' => $past?->due_date,
            ];
        });

        $kpi = [
            'machines' => $machines->count(),
            'dueSoon' => $machines->where('status', 'Due soon')->count(),
            'overdue' => $machines->where('status', 'Overdue')->count(),
            'visits' => $machines->sum('visits'),
        ];

        if ($this->statusFilter !== 'All') {
            $machines = $machines->where('status', $this->statusFilter);
        }

        return [
            'machines' => $machines->values(),
            'kpi' => $kpi,
        ];
    }
};
?>

<div>
    <div class="mb-5">
        <h1 class="text-xl font-semibold text-neutral-900">My equipment</h1>
        <p class="text-sm text-neutral-500">Machines on jobs assigned to you</p>
    </div>

    <div class="mb-5 grid gap-3" style="grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));">
        <div class="card">
            <p class="text-xs text-neutral-500">Machines</p>
            <p class="mt-1 font-mono text-2xl font-bold text-neutral-900">{{ $kpi['machines'] }}</p>
        </div>
        <div class="card">
            <p class="text-xs text-neutral-500">Due soon</p>
            <p class="mt-1 font-mono text-2xl font-bold text-amber-700">{{ $kpi['dueSoon'] }}</p>
        </div>
        <div class="card">
            <p class="text-xs text-neutral-500">Overdue</p>
            <p @class(['mt-1 font-mono text-2xl font-bold', 'text-critical-700' => $kpi['overdue'] > 0, 'text-neutral-900' => $kpi['overdue'] === 0])>{{ $kpi['overdue'] }}</p>
        </div>
        <div class="card">
            <p class="text-xs text-neutral-500">Your visits</p>
            <p class="mt-1 font-mono text-2xl font-bold text-neutral-900">{{ $kpi['visits'] }}</p>
        </div>
    </div>

    <div class="mb-5 flex flex-wrap items-center gap-3">
        <input wire:model="search" type="text" placeholder="Search serial, model, customer or site" class="input" style="flex: 1 1 240px; font-size: 16px; min-height: 46px">
        <select wire:model="statusFilter" class="input" style="width: auto; font-size: 16px; min-height: 46px">
            <option value="All">All</option>
            <option value="Active">Active</option>
            <option value="Due soon">Due soon</option>
            <option value="Overdue">Overdue</option>
        </select>
        <button type="button" wire:click="filter" class="btn-primary" style="min-height: 46px">Filter</button>
    </div>

    <div class="space-y-3">
        @forelse ($machines as $row)
            @php
                $item = $row['equipment'];
                $pillClass = match ($row['status']) {
                    'Active' => 'pill-success',
                    'Due soon' => 'pill-amber',
                    default => 'pill-danger',
                };
            @endphp
            <div class="card flex flex-wrap items-center gap-x-5 gap-y-3" wire:key="eq-{{ $item->id }}">
                <div class="icon-badge icon-badge-primary">
                    <x-icon name="nut" class="h-5 w-5" />
                </div>
                <div class="min-w-0" style="flex: 1 1 220px">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="rounded-full bg-neutral-900 px-2.5 py-1 font-mono text-xs text-white">{{ $item->serial_number }}</span>
                        <p class="text-sm font-semibold text-neutral-900">{{ $item->model }}</p>
                    </div>
                    <p class="mt-1 text-xs text-neutral-500">
                        {{ $item->customer->name }}@if ($item->site) &middot; {{ $item->site->name }}@endif
                    </p>
                </div>
                <div class="flex flex-wrap gap-x-6 gap-y-2">
                    <div>
                        <p class="text-xs text-neutral-500">Warranty</p>
                        <p class="text-sm font-medium text-neutral-800">
                            {{ $item->warranty_expires_at ? ($item->warranty_expires_at->isPast() ? 'Expired' : 'To '.$item->warranty_expires_at->format('M Y')) : '-' }}
                        </p>
                    </div>
                    <div>
                        <p class="text-xs text-neutral-500">Next visit</p>
                        <p class="text-sm font-medium text-neutral-800">{{ $item->next_visit_due_at?->format('d M Y') ?? 'Not set' }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-neutral-500">Your visits</p>
                        <p class="text-sm font-medium text-neutral-800">
                            {{ $row['visits'] }}@if ($row['last']) <span class="text-neutral-400">&middot; last {{ $row['last']->format('d M') }}</span>@endif
                        </p>
                    </div>
                </div>
                <span class="{{ $pillClass }}">{{ $row['status'] }}</span>
                <a href="/my-equipment/{{ $item->id }}" wire:navigate class="btn-outline" style="min-height: 44px">View</a>
            </div>
        @empty
            <div class="card border-dashed text-center text-sm text-neutral-500">
                @if ($search !== '' || $statusFilter !== 'All')
                    No machines match this filter.
                @else
                    No machines yet. They appear here once a job with a registered machine is assigned to you.
                @endif
            </div>
        @endforelse
    </div>
</div>
