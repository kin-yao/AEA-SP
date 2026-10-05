<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Models\User;
use App\Models\WorkOrder;

new #[Layout('layouts.app', ['title' => 'Jobs'])] class extends Component
{
    public string $statusFilter = 'All';
    public string $search = '';
    public string $technicianFilter = '';

    public function mount(): void
    {
        $this->authorize('viewAny', WorkOrder::class);
    }

    public function setFilter(string $status): void
    {
        $this->statusFilter = $status;
    }

    public function with(): array
    {
        $user = auth()->user();
        $isStaff = $user->hasAnyRole(['Manager', 'Supervisor', 'Service Admin']);

        $base = WorkOrder::query();

        if ($user->hasRole('Technician')) {
            $base->where('assigned_technician_id', $user->id);
        } elseif ($user->hasRole('Customer')) {
            $base->where('customer_id', $user->customer_id);
        }

        // Counts for the status tabs, taken before the tab itself is applied
        // so every tab shows how many jobs it would list.
        $scoped = (clone $base);
        if ($isStaff) {
            if ($this->search !== '') {
                $term = '%'.$this->search.'%';
                $scoped->where(function ($q) use ($term) {
                    $q->where('reference', 'like', $term)
                        ->orWhere('nature_of_visit', 'like', $term)
                        ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', $term))
                        ->orWhereHas('technician', fn ($t) => $t->where('name', 'like', $term));
                });
            }
            if ($this->technicianFilter !== '') {
                $scoped->where('assigned_technician_id', $this->technicianFilter);
            }
        }

        $counts = [
            'All' => (clone $scoped)->count(),
            'Overdue' => (clone $scoped)->where('due_date', '<', today())->where('status', '!=', 'Closed')->count(),
        ];
        foreach (['Assigned', 'On site', 'Awaiting review', 'Approved', 'Closed'] as $s) {
            $counts[$s] = (clone $scoped)->where('status', $s)->count();
        }

        $query = (clone $scoped)->with(['customer', 'technician', 'site'])->latest('due_date')->latest('id');

        if ($this->statusFilter === 'Overdue') {
            $query->where('due_date', '<', today())->where('status', '!=', 'Closed');
        } elseif ($this->statusFilter !== 'All') {
            $query->where('status', $this->statusFilter);
        }

        return [
            'jobs' => $query->get(),
            'counts' => $counts,
            'isStaff' => $isStaff,
            'technicians' => $isStaff ? User::role('Technician')->orderBy('name')->get(['id', 'name']) : collect(),
        ];
    }
};
?>

<div>
    <div class="mb-5">
        <h1 class="text-xl font-semibold text-neutral-900">
            {{ auth()->user()->hasRole('Technician') ? 'My jobs' : ($isStaff ? 'All jobs' : 'Jobs') }}
        </h1>
        <p class="text-sm text-neutral-500">{{ $jobs->count() }} {{ $statusFilter === 'All' && $search === '' && $technicianFilter === '' ? 'total' : 'matching' }}</p>
    </div>

    @if ($isStaff)
        <div class="mb-4 flex flex-wrap items-center gap-3">
            <input wire:model.live.debounce.300ms="search" type="text" placeholder="Search job, customer, technician or visit" class="input" style="flex: 1 1 260px; font-size: 16px; min-height: 46px">
            <select wire:model.live="technicianFilter" class="input" style="width: auto; font-size: 16px; min-height: 46px">
                <option value="">All technicians</option>
                @foreach ($technicians as $t)
                    <option value="{{ $t->id }}">{{ $t->name }}</option>
                @endforeach
            </select>
        </div>
    @endif

    <div class="mb-5 flex flex-wrap gap-1 border-b border-neutral-200">
        @foreach (['All', 'Assigned', 'On site', 'Awaiting review', 'Approved', 'Closed', 'Overdue'] as $status)
            <button
                wire:click="setFilter('{{ $status }}')"
                @class([
                    'border-b-2 px-3 py-2 text-sm font-medium transition-colors',
                    'border-primary-500 text-primary-600' => $statusFilter === $status,
                    'border-transparent text-neutral-500 hover:text-neutral-900' => $statusFilter !== $status,
                ])
            >
                {{ $status }}@if ($isStaff) <span class="ml-1 text-xs {{ $statusFilter === $status ? 'text-primary-400' : 'text-neutral-400' }}">{{ $counts[$status] }}</span>@endif
            </button>
        @endforeach
    </div>

    @if ($isStaff)
        <div class="card" style="padding: 0">
            <div class="overflow-x-auto">
                <table class="table-clean" style="min-width: 820px">
                    <thead>
                        <tr>
                            <th>Job</th><th>Customer</th><th>Nature of visit</th><th>Technician</th><th>Due</th><th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($jobs as $job)
                            @php
                                $isOverdue = $job->due_date->lt(today()) && $job->status !== 'Closed';
                                $pill = match (true) {
                                    $job->status === 'Assigned' => 'pill-neutral',
                                    in_array($job->status, ['On site', 'Awaiting review']) => 'pill-info',
                                    default => 'pill-success',
                                };
                            @endphp
                            <tr wire:key="job-{{ $job->id }}">
                                <td>
                                    <a href="/jobs/{{ $job->id }}" wire:navigate class="whitespace-nowrap font-mono text-xs font-bold text-neutral-900 hover:text-primary-600">{{ $job->reference }}</a>
                                </td>
                                <td>
                                    <p class="font-medium text-neutral-900">{{ $job->customer->name }}</p>
                                    @if ($job->site)
                                        <p class="text-xs text-neutral-400">{{ $job->site->name }}</p>
                                    @endif
                                </td>
                                <td class="text-neutral-600">{{ $job->nature_of_visit }}</td>
                                <td>{{ $job->technician?->name ?? 'Unassigned' }}</td>
                                <td class="whitespace-nowrap">
                                    {{ $job->due_date->format('d M Y') }}
                                    @if ($isOverdue)
                                        <span class="ml-1 text-xs font-semibold text-critical-700">{{ $job->due_date->diffInDays(today()) }}d late</span>
                                    @endif
                                </td>
                                <td><span class="{{ $isOverdue ? 'pill-danger' : $pill }}">{{ $isOverdue ? 'Overdue' : $job->status }}</span></td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="py-8 text-center text-neutral-500">No jobs match this filter.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @else
        <div class="space-y-3">
            @forelse ($jobs as $job)
                @php
                    $isOverdue = $job->due_date->isPast() && $job->status !== 'Closed';
                @endphp
                <a href="/jobs/{{ $job->id }}" wire:navigate class="card flex items-start justify-between gap-3 hover:shadow-[var(--shadow-card-hover)]">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <p class="text-sm font-semibold text-neutral-900">{{ $job->reference }}</p>
                            @if ($isOverdue)
                                <span class="pill-danger">Overdue</span>
                            @endif
                        </div>
                        <p class="truncate text-sm text-neutral-600">{{ $job->customer->name }}</p>
                        <p class="mt-1 truncate text-xs text-neutral-500">{{ $job->nature_of_visit }} &middot; due {{ $job->due_date->format('d M') }}</p>
                        @if ($job->technician)
                            <p class="mt-1 text-xs text-neutral-400">{{ $job->technician->name }}</p>
                        @endif
                    </div>
                    <span @class([
                        'shrink-0',
                        'pill-neutral' => $job->status === 'Assigned',
                        'pill-info' => in_array($job->status, ['On site', 'Awaiting review']),
                        'pill-success' => in_array($job->status, ['Approved', 'Closed']),
                    ])>
                        {{ $job->status }}
                    </span>
                </a>
            @empty
                <div class="card border-dashed text-center text-sm text-neutral-500">
                    No jobs match this filter.
                </div>
            @endforelse
        </div>
    @endif
</div>
