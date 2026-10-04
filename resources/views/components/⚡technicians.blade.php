<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Models\User;
use App\Models\WorkOrder;

new #[Layout('layouts.app', ['title' => 'Technicians'])] class extends Component
{
    public string $search = '';
    public string $sort = 'name';

    public function mount(): void
    {
        $this->authorize('viewAny', WorkOrder::class);
        abort_unless(auth()->user()->hasAnyRole(['Manager', 'Supervisor', 'Service Admin']), 403);
    }

    public function apply(): void
    {
        // Livewire syncs $search and $sort on this round trip regardless;
        // this method just gives the "Apply" button something to call.
    }

    public function with(): array
    {
        $totalTechnicians = User::role('Technician')->count();

        $technicians = User::role('Technician')
            ->with(['branch.country', 'technicianDocuments'])
            ->withCount([
                'assignedWorkOrders as open_jobs_count' => fn ($q) => $q->where('status', '!=', 'Closed'),
                'assignedWorkOrders as overdue_jobs_count' => fn ($q) => $q->where('status', '!=', 'Closed')->where('due_date', '<', today()),
                'assignedWorkOrders as closed_jobs_count' => fn ($q) => $q->where('status', 'Closed'),
                'assignedWorkOrders as on_time_closed_count' => fn ($q) => $q->where('status', 'Closed')->whereRaw('DATE(updated_at) <= due_date'),
            ])
            ->withExists([
                'assignedWorkOrders as has_on_site_job' => fn ($q) => $q->where('status', 'On site'),
            ])
            ->when($this->search !== '', fn ($q) => $q->where('name', 'like', '%'.$this->search.'%'))
            ->get()
            ->map(function ($technician) {
                $technician->expiring_soon_count = $technician->technicianDocuments
                    ->filter(fn ($doc) => in_array($doc->expiryStage(), ['urgent', 'critical']))
                    ->count();

                $technician->on_time_rate = $technician->closed_jobs_count > 0
                    ? (int) round($technician->on_time_closed_count / $technician->closed_jobs_count * 100)
                    : null;

                $technician->duty_status = $technician->on_leave
                    ? 'On leave'
                    : ($technician->has_on_site_job ? 'On site' : 'Available');

                return $technician;
            });

        $technicians = match ($this->sort) {
            'open_desc' => $technicians->sortByDesc('open_jobs_count'),
            'rate_desc' => $technicians->sortByDesc(fn ($t) => $t->on_time_rate ?? -1),
            'overdue_desc' => $technicians->sortByDesc('overdue_jobs_count'),
            default => $technicians->sortBy('name'),
        };

        return [
            'technicians' => $technicians->values(),
            'totalTechnicians' => $totalTechnicians,
        ];
    }
};
?>

<div>
    <div class="mb-6 flex items-center gap-4">
        <h1 class="shrink-0 text-xl font-semibold text-neutral-900">Technicians</h1>
        <div class="h-px flex-1 border-t border-dashed border-neutral-300"></div>
        @can('create', \App\Models\User::class)
            <a href="/users/create?role=Technician" wire:navigate
               class="shrink-0 rounded-[var(--radius-md)] bg-amber-500 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-600">
                New technician
            </a>
        @endcan
    </div>

    <div class="mb-6 flex flex-wrap items-center gap-3">
        <input wire:model="search" type="text" placeholder="Search technician name" class="input min-w-[220px] flex-1">
        <select wire:model="sort" class="input w-auto">
            <option value="name">Sort: name</option>
            <option value="open_desc">Sort: most open jobs>option>
            <option value="rate_desc">Sort:
 on-time rate</option>
            <option value="overdue_desc">Sort: overdue</option>
        </select>
        <button type="button" wire:click="apply" class="btn-primary shrink-0">Apply</button>
        <span class="shrink-0 text-sm text-neutral-400">{{ $technicians->count() }} of {{ $totalTechnicians }} shown</span>
    </div>

    <div class="grid grid-cols-2 gap-4">
        @forelse ($technicians as $technician)
            @php
                $ring = $technician->on_time_rate !== null && $technician->on_time_rate >= 90 ? 'amber' : 'primary';
                $ringPercent = $technician->on_time_rate ?? 0;
                $circumference = 2 * M_PI * 15;
                $dasharray = round($ringPercent / 100 * $circumference, 1).' '.round($circumference, 1);
                $location = collect([$technician->branch->name ?? null, $technician->branch?->country?->name])->filter()->implode(', ');
            @endphp
            <a href="/technicians/{{ $technician->id }}" wire:navigate class="card flex items-start gap-4 hover:border-neutral-300">
                <svg viewBox="0 0 36 36" class="h-16 w-16 shrink-0 -rotate-90">
                    <circle cx="18" cy="18" r="15" fill="none" stroke-width="3" class="stroke-neutral-100" />
                    @if ($technician->on_time_rate !== null)
                        <circle cx="18" cy="18" r="15" fill="none" stroke-width="3" stroke-linecap="round"
                                class="{{ $ring === 'amber' ? 'stroke-amber-500' : 'stroke-primary-600' }}"
                                stroke-dasharray="{{ $dasharray }}" />
                    @endif
                    <text x="18" y="18" text-anchor="middle" dominant-baseline="central" transform="rotate(90 18 18)"
                          class="fill-neutral-900" style="font-size: 7px; font-weight: 700">
                        {{ $technician->on_time_rate !== null ? $technician->on_time_rate.'%' : '—' }}
                    </text>
                </svg>
              <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-semibold text-neutral-900">{{ $technician->name }}</p>
                    <p class="truncate text-xs text-neutral-500">
                        {{ $location ?: '—' }}
                        @if ($technician->specialty)
                            &middot; {{ $technician->specialty }}
                        @endif
                    </p>
                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        <span class="text-xs text-neutral-600">{{ $technician->open_jobs_count }} open</span>
                        <span @class([
                            'pill-amber' => $technician->duty_status === 'On site',
                            'pill-success' => $technician->duty_status === 'Available',
                            'pill-neutral' => $technician->duty_status === 'On leave',
                        ])>
                            {{ $technician->duty_status }}
                        </span>
                        @if ($technician->expiring_soon_count > 0)
                            <span class="pill-neutral">{{ $technician->expiring_soon_count }} document{{ $technician->expiring_soon_count === 1 ? '' : 's' }} due</span>
                        @endif
                        @if ($technician->overdue_jobs_count > 0)
                            <span class="pill-danger">{{ $technician->overdue_jobs_count }} overdue</span>
                        @endif
                    </div>
                </div>
            </a>
        @empty
            <div class="card col-span-2 text-center text-sm text-neutral-500">No technician accounts on file yet.</div>
        @endforelse
    </div>
</div>
