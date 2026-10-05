<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Models\Equipment;
use App\Models\WorkOrder;

new #[Layout('layouts.app', ['title' => 'Equipment'])] class extends Component
{
    public Equipment $equipment;

    public function mount(Equipment $equipment): void
    {
        $this->authorize('view', $equipment);

        $equipment->load(['customer', 'site']);
        $this->equipment = $equipment;
    }

    public function with(): array
    {
        $jobs = WorkOrder::with('technician')
            ->where('equipment_id', $this->equipment->id)
            ->orderByDesc('due_date')
            ->take(15)
            ->get();

        return [
            'jobs' => $jobs,
        ];
    }
};
?>

<div>
    <a href="/equipment" wire:navigate class="mb-4 inline-flex items-center gap-1.5 text-sm text-neutral-500 hover:text-neutral-900">
        <x-icon name="arrow-right" class="h-3.5 w-3.5 rotate-180" />
        Back to equipment
    </a>

    <div class="mb-6 flex items-center gap-3">
        <div class="icon-badge icon-badge-primary h-14 w-14">
            <x-icon name="nut" class="h-6 w-6" />
        </div>
        <div class="min-w-0 flex-1">
            <div class="flex items-center gap-2">
                <h1 class="text-xl font-semibold text-neutral-900">{{ $equipment->model }}</h1>
                <span class="rounded-full bg-neutral-900 px-2.5 py-1 font-mono text-xs text-white">{{ $equipment->serial_number }}</span>
            </div>
            <p class="text-sm text-neutral-500">
                {{ $equipment->customer->name }}
                @if ($equipment->site)
                    &middot; {{ $equipment->site->name }}
                @endif
            </p>
        </div>
        @can('update', $equipment)
            <a href="/equipment/{{ $equipment->id }}/edit" wire:navigate class="btn-outline shrink-0">Edit</a>
        @endcan
    </div>

    <div class="mb-5 grid grid-cols-4 gap-4">
        <div class="card">
            <p class="text-xs font-medium text-neutral-500">Category</p>
            <p class="mt-1 text-sm font-semibold text-neutral-900">{{ $equipment->category ?: '—' }}</p>
        </div>
        <div class="card">
            <p class="text-xs font-medium text-neutral-500">Cover</p>
            <p class="mt-1 text-sm font-semibold text-neutral-900">{{ $equipment->cover }}</p>
        </div>
        <div class="card">
            <p class="text-xs font-medium text-neutral-500">Warranty</p>
            <p class="mt-1 text-sm font-semibold text-neutral-900">
                {{ $equipment->warranty_expires_at ? ($equipment->warranty_expires_at->isPast() ? 'Expired' : 'To '.$equipment->warranty_expires_at->format('d M Y')) : '—' }}
            </p>
        </div>
        <div class="card">
            <p class="text-xs font-medium text-neutral-500">Next visit</p>
            @php
                $status = $equipment->visitStatus();
                $pillClass = match ($status) {
                    'Active' => 'pill-success',
                    'Due soon' => 'pill-amber',
                    default => 'pill-danger',
                };
            @endphp
            <p class="mt-1 flex items-center gap-2 text-sm font-semibold text-neutral-900">
                {{ $equipment->next_visit_due_at?->format('d M Y') ?? 'Not set' }}
                <span class="{{ $pillClass }}">{{ $status }}</span>
            </p>
        </div>
    </div>

    <div class="card">
        <h2 class="mb-3 text-sm font-semibold text-neutral-900">Service history</h2>
        <div class="space-y-2">
            @forelse ($jobs as $job)
                <a href="/jobs/{{ $job->id }}" wire:navigate class="flex items-center justify-between gap-3 rounded-[var(--radius-md)] border border-neutral-100 px-3 py-2.5 hover:bg-neutral-50">
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-neutral-900">{{ $job->reference }}</p>
                        <p class="truncate text-xs text-neutral-500">
                            {{ $job->nature_of_visit }}
                            @if ($job->technician)
                                &middot; {{ $job->technician->name }}
                            @endif
                            &middot; due {{ $job->due_date->format('d M Y') }}
                        </p>
                    </div>
                    <span @class([
                        'shrink-0',
                        'pill-neutral' => $job->status === 'Assigned',
                        'pill-info' => in_array($job->status, ['On site', 'Awaiting review']),
                        'pill-success' => $job->status === 'Closed',
                    ])>
                        {{ $job->status }}
                    </span>
                </a>
            @empty
                <p class="text-sm text-neutral-500">No service visits on file for this machine.</p>
            @endforelse
        </div>
    </div>
</div>
