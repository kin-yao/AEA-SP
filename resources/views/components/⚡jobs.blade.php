<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Models\WorkOrder;

new #[Layout('layouts.app', ['title' => 'Jobs'])] class extends Component
{
    public string $statusFilter = 'All';

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

        $query = WorkOrder::with(['customer', 'technician'])->latest();

        if ($user->hasRole('Technician')) {
            $query->where('assigned_technician_id', $user->id);
        } elseif ($user->hasRole('Customer')) {
            $query->where('customer_id', $user->customer_id);
        }

        if ($this->statusFilter === 'Overdue') {
            $query->where('due_date', '<', now())->whereNotIn('status', ['Closed']);
        } elseif ($this->statusFilter !== 'All') {
            $query->where('status', $this->statusFilter);
        }

        return [
            'jobs' => $query->get(),
        ];
    }
};
?>

<div>
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold text-neutral-900">Jobs</h1>
            <p class="text-sm text-neutral-500">{{ $jobs->count() }} {{ $statusFilter === 'All' ? 'total' : 'matching' }}</p>
        </div>
    </div>

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
                {{ $status }}
            </button>
        @endforeach
    </div>

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
</div>
