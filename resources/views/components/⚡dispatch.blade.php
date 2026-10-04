<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Models\WorkOrder;
use App\Models\User;
use App\Services\WorkflowNotifier;

new #[Layout('layouts.app', ['title' => 'Dispatch board'])] class extends Component
{
    public string $weekStart;

    public ?int $selectedTechnicianId = null;
    public ?string $selectedDate = null;

    public ?int $reassigningJobId = null;
    public ?int $newTechnicianId = null;

    public function mount(): void
    {
        $this->authorize('viewAny', WorkOrder::class);
        abort_unless(auth()->user()->hasAnyRole(['Manager', 'Supervisor', 'Service Admin']), 403);

        $this->weekStart = today()->startOfWeek()->format('Y-m-d');
    }

    public function previousWeek(): void
    {
        $this->weekStart = \Illuminate\Support\Carbon::parse($this->weekStart)->subWeek()->format('Y-m-d');
        $this->closeSlot();
    }

    public function nextWeek(): void
    {
        $this->weekStart = \Illuminate\Support\Carbon::parse($this->weekStart)->addWeek()->format('Y-m-d');
        $this->closeSlot();
    }

    public function thisWeek(): void
    {
        $this->weekStart = today()->startOfWeek()->format('Y-m-d');
        $this->closeSlot();
    }

    public function selectSlot(int $technicianId, string $date): void
    {
        if ($this->selectedTechnicianId === $technicianId && $this->selectedDate === $date) {
            $this->closeSlot();

            return;
        }

        $this->selectedTechnicianId = $technicianId;
        $this->selectedDate = $date;
        $this->reassigningJobId = null;
    }

    public function closeSlot(): void
    {
        $this->selectedTechnicianId = null;
        $this->selectedDate = null;
        $this->reassigningJobId = null;
    }

    public function toggleReassign(int $jobId, ?int $currentTechnicianId): void
    {
        $this->reassigningJobId = $this->reassigningJobId === $jobId ? null : $jobId;
        $this->newTechnicianId = $currentTechnicianId;
    }

    public function reassign(): void
    {
        $job = WorkOrder::findOrFail($this->reassigningJobId);

        $this->authorize('update', $job);

        $validated = $this->validate([
            'newTechnicianId' => ['required', 'exists:users,id'],
        ]);

        $newTechnician = User::role('Technician')->findOrFail($validated['newTechnicianId']);
        $previousTechnician = $job->technician;

        if ($newTechnician->id !== $job->assigned_technician_id) {
            $job->update(['assigned_technician_id' => $newTechnician->id]);

            WorkflowNotifier::user(
                $newTechnician,
                'A job has been assigned to you',
                [
                    "Job {$job->reference} for {$job->customer->name} is now assigned to you.",
                    "Due {$job->due_date->format('d M Y')}.",
                ],
            );

            if ($previousTechnician) {
                WorkflowNotifier::user(
                    $previousTechnician,
                    'A job has been reassigned',
                    [
                        "Job {$job->reference} for {$job->customer->name} has been moved to {$newTechnician->name}.",
                    ],
                );
            }
        }

        $this->reassigningJobId = null;
        $this->closeSlot();
    }

    public function with(): array
    {
        $weekStart = \Illuminate\Support\Carbon::parse($this->weekStart);
        $days = collect(range(0, 4))->map(fn ($i) => $weekStart->copy()->addDays($i));

        $technicians = User::role('Technician')->orderBy('name')->get();

        $jobs = WorkOrder::with('customer')
            ->whereIn('assigned_technician_id', $technicians->pluck('id'))
            ->whereBetween('due_date', [$weekStart->copy()->format('Y-m-d'), $weekStart->copy()->addDays(4)->format('Y-m-d')])
            ->where('status', '!=', 'Closed')
            ->get();

        $grid = [];
        foreach ($jobs as $job) {
            $grid[$job->assigned_technician_id][$job->due_date->format('Y-m-d')][] = $job;
        }

        $selectedJobs = [];
        if ($this->selectedTechnicianId && $this->selectedDate) {
            $selectedJobs = $grid[$this->selectedTechnicianId][$this->selectedDate] ?? [];
        }

        return [
            'days' => $days,
            'technicians' => $technicians,
            'grid' => $grid,
            'overdueCount' => WorkOrder::where('status', '!=', 'Closed')->where('due_date', '<', today())->count(),
            'selectedJobs' => $selectedJobs,
            'selectedTechnician' => $this->selectedTechnicianId ? $technicians->firstWhere('id', $this->selectedTechnicianId) : null,
        ];
    }
};
?>

<div>
    <div class="mb-5 flex flex-wrap items-center justify-between gap-3 rounded-[var(--radius-lg)] border border-neutral-200 bg-white px-5 py-4">
        <div class="flex items-center gap-3">
            <h1 class="text-lg font-semibold text-neutral-900">
                Dispatch, week of {{ \Illuminate\Support\Carbon::parse($weekStart)->format('d F') }}
            </h1>
            <div class="flex items-center gap-1">
                <button type="button" wire:click="previousWeek" class="btn-ghost px-2 py-1">&larr;</button>
                <button type="button" wire:click="thisWeek" class="btn-ghost px-2 py-1 text-xs">This week</button>
                <button type="button" wire:click="nextWeek" class="btn-ghost px-2 py-1">&rarr;</button>
            </div>
        </div>
        @if ($overdueCount > 0)
            <span class="pill-danger">Overdue ({{ $overdueCount }})</span>
        @endif
    </div>

    <div class="mb-5 flex flex-wrap items-center gap-5 rounded-[var(--radius-lg)] border border-neutral-200 bg-white px-5 py-3 text-xs text-neutral-600">
        <span class="font-semibold uppercase tracking-wider text-neutral-400">Key</span>
        <span class="flex items-center gap-1.5"><span class="h-3 w-3 rounded-sm bg-neutral-900"></span> Assigned, on schedule</span>
        <span class="flex items-center gap-1.5"><span class="h-3 w-3 rounded-sm bg-amber-500"></span> Due soon</span>
        <span class="flex items-center gap-1.5"><span class="h-3 w-3 rounded-sm bg-critical-500"></span> Late, past target</span>
        <span class="flex items-center gap-1.5"><span class="h-3 w-3 rounded-sm border border-dashed border-neutral-300"></span> Free slot</span>
    </div>

    <div class="overflow-x-auto rounded-[var(--radius-lg)] border border-neutral-200 bg-white">
        <table class="w-full border-collapse text-sm">
            <thead>
                <tr class="border-b border-neutral-200">
                    <th class="w-48 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-400">Technician</th>
                    @foreach ($days as $day)
                        <th class="px-3 py-3 text-left text-xs font-semibold text-neutral-500">
                            {{ $day->format('D d') }}
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse ($technicians as $technician)
                    <tr class="border-b border-neutral-100 last:border-b-0">
                        <td class="px-4 py-3 align-top">
                            <p class="text-sm font-medium text-neutral-900">{{ $technician->name }}</p>
                        </td>
                        @foreach ($days as $day)
                            @php
                                $dateKey = $day->format('Y-m-d');
                                $slotJobs = $grid[$technician->id][$dateKey] ?? [];
                                $primary = collect($slotJobs)->sortBy('due_date')->first();
                                $isSelected = $selectedTechnician?->id === $technician->id && $selectedDate === $dateKey;

                                $cellClass = 'border-dashed border-neutral-300 bg-neutral-50 text-neutral-400';
                                if ($primary) {
                                    $isOverdue = $primary->due_date->isPast();
                                    $isDueSoon = ! $isOverdue && $primary->due_date->lte(today()->addDay());
                                    $cellClass = match (true) {
                                        $isOverdue => 'border-critical-500 bg-critical-500 text-white',
                                        $isDueSoon => 'border-amber-500 bg-amber-500 text-white',
                                        default => 'border-neutral-900 bg-neutral-900 text-white',
                                    };
                                }
                            @endphp
                            <td class="px-2 py-2 align-top">
                                <button type="button" wire:click="selectSlot({{ $technician->id }}, '{{ $dateKey }}')"
                                        class="block w-full rounded-[var(--radius-md)] border px-3 py-2.5 text-left transition {{ $cellClass }} {{ $isSelected ? 'ring-2 ring-primary-500' : '' }}">
                                    @if ($primary)
                                        <p class="text-xs font-semibold">{{ $primary->reference }}</p>
                                        <p class="truncate text-xs opacity-90">{{ $primary->customer->name }}</p>
                                        @if (count($slotJobs) > 1)
                                            <p class="mt-0.5 text-[11px] opacity-80">+{{ count($slotJobs) - 1 }} more</p>
                                        @endif
                                    @else
                                        <p class="text-xs">free</p>
                                    @endif
                                </button>
                            </td>
                        @endforeach
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-6 text-center text-sm text-neutral-500">No technician accounts on file yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($selectedTechnician && $selectedDate)
        <div class="mt-5 card">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="text-sm font-semibold text-neutral-900">
                    {{ $selectedTechnician->name }} &middot; {{ \Illuminate\Support\Carbon::parse($selectedDate)->format('D, d M Y') }}
                </h2>
                <button type="button" wire:click="closeSlot" class="text-xs font-semibold text-neutral-400 hover:text-neutral-600">Close</button>
            </div>

            <div class="space-y-3">
                @forelse ($selectedJobs as $job)
                    @php $isOverdue = $job->due_date->isPast() && $job->status !== 'Closed'; @endphp
                    <div class="rounded-[var(--radius-md)] border border-neutral-100 px-3 py-2.5">
                        <div class="flex items-center justify-between gap-3">
                            <a href="/jobs/{{ $job->id }}" wire:navigate class="min-w-0 flex-1">
                                <div class="flex items-center gap-2">
                                    <p class="text-sm font-medium text-neutral-900">{{ $job->reference }}</p>
                                    @if ($isOverdue)
                                        <span class="pill-danger">Overdue</span>
                                    @endif
                                </div>
                                <p class="truncate text-xs text-neutral-500">{{ $job->customer->name }} &middot; {{ $job->nature_of_visit }}</p>
                            </a>
                            <span @class([
                                'shrink-0',
                                'pill-neutral' => $job->status === 'Assigned',
                                'pill-info' => in_array($job->status, ['On site', 'Awaiting review']),
                                'pill-success' => in_array($job->status, ['Approved', 'Closed']),
                            ])>
                                {{ $job->status }}
                            </span>
                            @can('update', $job)
                                <button type="button" wire:click="toggleReassign({{ $job->id }}, {{ $job->assigned_technician_id }})"
                                        class="shrink-0 text-xs font-semibold text-primary-700 hover:text-primary-800">
                                    {{ $reassigningJobId === $job->id ? 'Cancel' : 'Reassign' }}
                                </button>
                            @endcan
                        </div>

                        @if ($reassigningJobId === $job->id)
                            <form wire:submit="reassign" class="mt-3 flex items-center gap-2 border-t border-neutral-100 pt-3">
                                <select wire:model="newTechnicianId" class="input">
                                    @foreach ($technicians as $option)
                                        <option value="{{ $option->id }}">{{ $option->name }}</option>
                                    @endforeach
                                </select>
                                <button type="submit" class="btn-primary shrink-0">Move job</button>
                            </form>
                            @error('newTechnicianId') <p class="mt-1 text-xs text-critical-700">{{ $message }}</p> @enderror
                    @endif
                </div>
                @empty
                    <p class="text-sm text-neutral-500">No jobs in this slot. Free on this day.</p>
                @endforelse
            </div>
        </div>
    @endif
</div>
