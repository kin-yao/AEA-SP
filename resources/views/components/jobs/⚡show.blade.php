<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Models\WorkOrder;

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
    }
};
?>

<div>
    <a href="/jobs" wire:navigate class="mb-4 inline-flex items-center gap-1.5 text-sm text-gray-500 hover:text-gray-700">
        <x-icon name="arrow-right" class="h-3.5 w-3.5 rotate-180" />
        Back to jobs
    </a>

    <div class="mb-4 flex items-start justify-between">
        <div>
            <h1 class="text-xl font-semibold text-gray-900">{{ $job->reference }}</h1>
            <p class="text-sm text-gray-500">{{ $job->customer->name }}</p>
        </div>
        <span @class([
            'shrink-0 px-2.5 py-1 text-xs font-medium',
            'bg-gray-100 text-gray-600' => $job->status === 'Assigned',
            'bg-info-50 text-info-700' => in_array($job->status, ['On site', 'Awaiting review']),
            'bg-success-50 text-success-700' => in_array($job->status, ['Approved', 'Closed']),
            'bg-primary-50 text-primary-700' => $job->status === 'Overdue',
        ])>
            {{ $job->status }}
        </span>
    </div>

    <div class="mb-4 border border-gray-200 bg-white p-5">
        <dl class="grid grid-cols-2 gap-4 text-sm">
            <div>
                <dt class="text-gray-500">Nature of visit</dt>
                <dd class="text-gray-900">{{ $job->nature_of_visit }}</dd>
            </div>
            <div>
                <dt class="text-gray-500">Priority</dt>
                <dd class="text-gray-900">{{ $job->priority }}</dd>
            </div>
            <div>
                <dt class="text-gray-500">Technician</dt>
                <dd class="text-gray-900">{{ $job->technician->name }}</dd>
            </div>
            <div>
                <dt class="text-gray-500">Due date</dt>
                <dd class="text-gray-900">{{ $job->due_date->format('d M Y') }}</dd>
            </div>
        </dl>
        @if ($job->equipment_description)
            <div class="mt-4 border-t border-gray-100 pt-4">
                <dt class="mb-1 text-sm text-gray-500">Equipment</dt>
                <dd class="text-sm text-gray-900">{{ $job->equipment_description }}</dd>
            </div>
        @endif
    </div>

    @if ($job->status === 'On site' && auth()->id() === $job->assigned_technician_id)
        <a href="/jobs/{{ $job->id }}/report" wire:navigate
           class="block bg-primary-500 px-4 py-3 text-center text-sm font-medium text-white hover:bg-primary-600">
            File service report
        </a>
    @elseif ($this->pendingReport)
        <a href="/documents/{{ $this->pendingReport->id }}" wire:navigate
           class="block border border-gray-200 bg-white px-4 py-3 text-center text-sm font-medium text-gray-900 hover:border-gray-300">
            View report awaiting review
        </a>
    @elseif ($this->nextStatus)
        <div class="border border-gray-200 bg-white p-5">
            <p class="mb-3 text-sm text-gray-600">
                Next stage: <span class="font-medium text-gray-900">{{ $this->nextStatus }}</span>
            </p>
            <button wire:click="advanceStatus" wire:loading.attr="disabled" wire:target="advanceStatus"
                    class="bg-primary-500 px-4 py-2 text-sm font-medium text-white hover:bg-primary-600">
                Move to {{ $this->nextStatus }}
            </button>
        </div>
    @endif
</div>
