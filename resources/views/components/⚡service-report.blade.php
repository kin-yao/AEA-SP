<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Models\WorkOrder;

new #[Layout('layouts.app', ['title' => 'Service report'])] class extends Component
{
    public function mount(): void
    {
        abort_unless(auth()->user()->hasRole('Technician'), 403);
    }

    public function with(): array
    {
        $base = WorkOrder::with(['customer', 'site'])
            ->where('assigned_technician_id', auth()->id());

        return [
            'ready' => (clone $base)->where('status', 'On site')->orderBy('due_date')->get(),
            'notStarted' => (clone $base)->where('status', 'Assigned')->orderBy('due_date')->get(),
        ];
    }
};
?>

<div>
    <div class="mb-6">
        <h1 class="text-xl font-semibold text-neutral-900">Service report</h1>
    </div>

    <h2 class="mb-3 text-sm font-semibold text-neutral-900">Ready to file</h2>
    <div class="mb-8 space-y-3">
        @forelse ($ready as $job)
            <div class="card flex items-center gap-4">
                <div class="icon-badge icon-badge-info">
                    <x-icon name="file-earmark-text" class="h-5 w-5" />
                </div>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-semibold text-neutral-900">{{ $job->reference }}</p>
                    <p class="truncate text-sm text-neutral-600">
                        {{ $job->customer->name }}@if ($job->site) &middot; {{ $job->site->name }}@endif
                    </p>
                    <p class="mt-1 truncate text-xs text-neutral-500">{{ $job->nature_of_visit }} &middot; due {{ $job->due_date->format('d M') }}</p>
                </div>
                <a href="/jobs/{{ $job->id }}/report" wire:navigate class="btn-primary shrink-0">File report</a>
            </div>
        @empty
            <div class="card border-dashed text-center text-sm text-neutral-500">
                No job is marked On site right now. Open a job and mark yourself On site to file its report.
            </div>
        @endforelse
    </div>

    @if ($notStarted->isNotEmpty())
        <h2 class="mb-3 text-sm font-semibold text-neutral-900">Not started yet</h2>
        <div class="space-y-3">
            @foreach ($notStarted as $job)
                <a href="/jobs/{{ $job->id }}" wire:navigate class="card flex items-center justify-between gap-3 hover:shadow-[var(--shadow-card-hover)]">
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-neutral-900">{{ $job->reference }}</p>
                        <p class="truncate text-sm text-neutral-600">{{ $job->customer->name }}</p>
                        <p class="mt-1 truncate text-xs text-neutral-500">{{ $job->nature_of_visit }} &middot; due {{ $job->due_date->format('d M') }}</p>
                    </div>
                    <span class="pill-neutral shrink-0">Assigned</span>
                </a>
            @endforeach
        </div>
    @endif
</div>
