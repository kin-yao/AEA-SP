<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Models\Equipment;
use App\Models\WorkOrder;

new #[Layout('layouts.app', ['title' => 'Equipment'])] class extends Component
{
    use \App\Support\ShowsMore;

    public string $search = '';
    public string $statusFilter = 'All';
    public string $scope = 'all';   // all machines, or only those the technician has a job on

    public function mount(): void
    {
        abort_unless(auth()->user()->hasRole('Technician'), 403);
    }

    public function updatedScope(): void
    {
        $this->scope = $this->scope === 'mine' ? 'mine' : 'all';
    }

    /** Narrow a query to one visit status, using the same rule as Equipment::visitStatus(). */
    protected function byStatus($query, string $status): void
    {
        $soon = today()->addDays((int) setting('visit_due_days'))->toDateString();
        $today = today()->toDateString();

        match ($status) {
            'Overdue' => $query->where(fn ($q) => $q->whereNull('next_visit_due_at')->orWhereDate('next_visit_due_at', '<', $today)),
            'Due soon' => $query->whereDate('next_visit_due_at', '>=', $today)->whereDate('next_visit_due_at', '<=', $soon),
            'Active' => $query->whereDate('next_visit_due_at', '>', $soon),
            default => null,
        };
    }

    public function with(): array
    {
        $mineIds = WorkOrder::where('assigned_technician_id', auth()->id())->whereNotNull('equipment_id')->distinct()->pluck('equipment_id');

        $base = Equipment::query();

        if ($this->scope === 'mine') {
            $base->whereIn('id', $mineIds);
        }

        if ($this->search !== '') {
            $term = '%'.$this->search.'%';
            $base->where(function ($q) use ($term) {
                $q->where('serial_number', 'like', $term)
                    ->orWhere('model', 'like', $term)
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', $term))
                    ->orWhereHas('site', fn ($s) => $s->where('name', 'like', $term));
            });
        }

        $kpi = [
            'machines' => (clone $base)->count(),
            'dueSoon' => tap(clone $base, fn ($q) => $this->byStatus($q, 'Due soon'))->count(),
            'overdue' => tap(clone $base, fn ($q) => $this->byStatus($q, 'Overdue'))->count(),
            'mine' => $mineIds->count(),
        ];

        $list = clone $base;
        $this->byStatus($list, $this->statusFilter);
        $total = (clone $list)->count();

        $machines = $list->with(['customer', 'site'])->orderBy('model')->orderBy('id')->take($this->limit)->get();

        $visits = WorkOrder::where('assigned_technician_id', auth()->id())
            ->whereIn('equipment_id', $machines->pluck('id'))
            ->get(['equipment_id', 'due_date'])
            ->groupBy('equipment_id');

        $rows = $machines->map(function (Equipment $e) use ($visits) {
            $mine = $visits[$e->id] ?? collect();
            $past = $mine->filter(fn ($j) => $j->due_date->lte(today()))->sortByDesc('due_date')->first();

            return ['equipment' => $e, 'status' => $e->visitStatus(), 'visits' => $mine->count(), 'last' => $past?->due_date];
        });

        return [
            'machineTotal' => $total,
            'machines' => $rows,
            'kpi' => $kpi,
        ];
    }
};
?>

<div>
    <div class="mb-5">
        <h1 class="text-xl font-semibold text-neutral-900">Equipment</h1>
        <p class="mt-1 text-sm text-neutral-500">Every machine, with its contracts, visits, reports, parts and certificates.</p>
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
            <p class="text-xs text-neutral-500">Machines you have worked on</p>
            <p class="mt-1 font-mono text-2xl font-bold text-neutral-900">{{ $kpi['mine'] }}</p>
        </div>
    </div>

    <div class="mb-5 flex flex-wrap items-center gap-3">
        <input wire:model="search" type="text" placeholder="Search serial, model, customer or site" class="input" style="flex: 1 1 240px; font-size: 16px; min-height: 46px">
        <select wire:model.live="scope" class="input" style="width: auto; font-size: 16px; min-height: 46px">
            <option value="all">All machines</option>
            <option value="mine">Only machines I worked on</option>
        </select>
        <select wire:model="statusFilter" class="input" style="width: auto; font-size: 16px; min-height: 46px">
            <option value="All">All</option>
            <option value="Active">Active</option>
            <option value="Due soon">Due soon</option>
            <option value="Overdue">Overdue</option>
        </select>
        <button type="button" wire:click="$refresh" class="btn-primary" style="min-height: 46px">Filter</button>
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
                    No machines have been registered yet.
                @endif
            </div>
        @endforelse
    </div>
    <x-show-more :shown="$machines->count()" :total="$machineTotal" />
</div>
