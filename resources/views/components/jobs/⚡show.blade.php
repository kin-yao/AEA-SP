<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Models\WorkOrder;
use App\Services\WorkflowNotifier;

new #[Layout('layouts.app', ['title' => 'Job'])] class extends Component
{
    public WorkOrder $job;

    public function mount(WorkOrder $job): void
    {
        $this->authorize('view', $job);
        $this->job = $job;
    }

    protected array $technicianStages = [
        'Assigned' => 'On site',
    ];

    protected array $staffStages = [
        'Approved' => 'Closed',
    ];

    public function getNextStatusProperty(): ?string
    {
        $user = auth()->user();

        if ($user->hasRole('Technician') && $user->id === $this->job->assigned_technician_id) {
            return $this->technicianStages[$this->job->status] ?? null;
        }

        if ($user->hasAnyRole(['Supervisor', 'Service Admin'])) {
            return $this->staffStages[$this->job->status]
                ?? $this->technicianStages[$this->job->status]
                ?? null;
        }

        return null;
    }

    public function getPendingReportProperty()
    {
        if ($this->job->status !== 'Awaiting review') {
            return null;
        }

        return $this->job->documents()->where('type', 'rep')->latest()->first();
    }

    public function advanceStatus(): void
    {
        $this->authorize('updateStatus', $this->job);

        $next = $this->nextStatus;

        if (! $next) {
            return;
        }

        $this->job->update(['status' => $next]);
        $this->job->refresh();

        // Only the customer-visible milestone gets an email, not every
        // internal stage, the technician's own "On site" step included.
        if ($next === 'Closed') {
            WorkflowNotifier::customer(
                $this->job->customer,
                'Your service job has been completed',
                [
                    "Job {$this->job->reference} has been marked complete.",
                ],
            );
        }
    }
};
?>

<div>
    <a href="/jobs" wire:navigate class="mb-4 inline-flex items-center gap-1.5 text-sm text-neutral-500 hover:text-neutral-900">
        <x-icon name="arrow-right" class="h-3.5 w-3.5 rotate-180" />
        Back to jobs
    </a>

    @php
        $isOverdue = $job->due_date->isPast() && $job->status !== 'Closed';
    @endphp

    <div class="mb-4 flex items-start justify-between">
        <div>
            <h1 class="text-xl font-semibold text-neutral-900">{{ $job->reference }}</h1>
            <p class="text-sm text-neutral-500">{{ $job->customer->name }}</p>
        </div>
        <div class="flex shrink-0 items-center gap-2">
            @if ($isOverdue)
                <span class="pill-danger">Overdue</span>
            @endif
            <span @class([
                'pill-neutral' => $job->status === 'Assigned',
                'pill-info' => in_array($job->status, ['On site', 'Awaiting review']),
                'pill-success' => in_array($job->status, ['Approved', 'Closed']),
            ])>
                {{ $job->status }}
            </span>
        </div>
    </div>

    <div class="card mb-4">
        <dl class="grid grid-cols-2 gap-4 text-sm">
            <div>
                <dt class="text-neutral-500">Nature of visit</dt>
                <dd class="text-neutral-900">{{ $job->nature_of_visit }}</dd>
            </div>
            <div>
                <dt class="text-neutral-500">Priority</dt>
                <dd class="text-neutral-900">{{ $job->priority }}</dd>
            </div>
            <div>
                <dt class="text-neutral-500">Technician</dt>
                <dd class="text-neutral-900">{{ $job->technician->name ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-neutral-500">Due date</dt>
                <dd @class(['text-critical-700 font-medium' => $isOverdue, 'text-neutral-900' => ! $isOverdue])>
                    {{ $job->due_date->format('d M Y') }}
                </dd>
            </div>
        </dl>
        @if ($job->equipment_description)
            <div class="mt-4 border-t border-neutral-100 pt-4">
                <dt class="mb-1 text-sm text-neutral-500">Equipment</dt>
                <dd class="text-sm text-neutral-900">{{ $job->equipment_description }}</dd>
            </div>
        @endif
    </div>

    @if ($job->status === 'On site' && auth()->id() === $job->assigned_technician_id)
        <a href="/jobs/{{ $job->id }}/report" wire:navigate class="btn-primary w-full">
            File service report
        </a>
    @elseif ($this->pendingReport)
        <a href="/documents/{{ $this->pendingReport->id }}" wire:navigate class="btn-outline w-full">
            View report awaiting review
        </a>
    @elseif ($this->nextStatus)
        <div class="card">
            <p class="mb-3 text-sm text-neutral-600">
                Next stage: <span class="font-medium text-neutral-900">{{ $this->nextStatus }}</span>
            </p>
            <button wire:click="advanceStatus" wire:loading.attr="disabled" wire:target="advanceStatus" class="btn-primary">
                Move to {{ $this->nextStatus }}
            </button>
        </div>
    @endif
</div>
