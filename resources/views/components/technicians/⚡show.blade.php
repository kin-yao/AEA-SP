<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Models\User;
use App\Models\WorkOrder;

new #[Layout('layouts.app', ['title' => 'Technician'])] class extends Component
{
    public User $technician;

    public bool $editingSpecialty = false;
    public string $specialty = '';

    public function mount(User $technician): void
    {
        $this->authorize('viewAny', WorkOrder::class);
        abort_unless(auth()->user()->hasAnyRole(['Manager', 'Supervisor', 'Service Admin']), 403);
        abort_unless($technician->hasRole('Technician'), 404);

        $technician->load(['branch.country', 'technicianDocuments']);
        $this->technician = $technician;
    }

    public function toggleEditSpecialty(): void
    {
        $this->editingSpecialty = ! $this->editingSpecialty;
        $this->specialty = $this->technician->specialty ?? '';
    }

    public function saveSpecialty(): void
    {
        $validated = $this->validate([
            'specialty' => ['nullable', 'string', 'max:150'],
        ]);

        $this->technician->update(['specialty' => $validated['specialty'] ?: null]);
        $this->editingSpecialty = false;
    }

    public function toggleOnLeave(): void
    {
        $this->technician->update(['on_leave' => ! $this->technician->on_leave]);
    }

    public function with(): array
    {
        $jobs = WorkOrder::with('customer')
            ->where('assigned_technician_id', $this->technician->id)
            ->orderByDesc('due_date')
            ->get();

        $openJobs = $jobs->whereNotIn('status', ['Closed']);
        $closedJobs = $jobs->where('status', 'Closed');
        $onTimeClosed = $closedJobs->filter(fn ($job) => $job->updated_at->lte($job->due_date->copy()->endOfDay()));

        return [
            'openJobs' => $openJobs->sortBy('due_date')->values(),
            'overdueJobs' => $openJobs->filter(fn ($job) => $job->due_date->isPast())->values(),
            'recentJobs' => $jobs->take(10),
            'closedCount' => $closedJobs->count(),
            'onTimeRate' => $closedJobs->count() > 0 ? (int) round($onTimeClosed->count() / $closedJobs->count() * 100) : null,
            'certificates' => $this->technician->technicianDocuments->sortByDesc(fn ($d) => $d->percentUsed()),
        ];
    }
};
?>

<div>
    <div class="mb-6 flex items-center gap-3">
        <a href="/technicians" wire:navigate class="text-neutral-400 hover:text-neutral-600">
            <x-icon name="arrow-right" class="h-4 w-4 rotate-180" />
        </a>
        <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-neutral-900 text-sm font-semibold text-white">
            {{ collect(explode(' ', $technician->name))->map(fn ($p) => $p[0] ?? '')->take(2)->implode('') }}
        </div>
        <div class="min-w-0 flex-1">
            <h1 class="text-xl font-semibold text-neutral-900">{{ $technician->name }}</h1>
            <p class="text-sm text-neutral-500">
                {{ collect([$technician->branch->name ?? null, $technician->branch?->country?->name])->filter()->implode(', ') ?: '—' }}
                &middot; {{ $technician->email }}
            </p>
        </div>
        <button type="button" wire:click="toggleOnLeave"
                class="btn-outline shrink-0 {{ $technician->on_leave ? 'bg-neutral-900 text-white hover:bg-neutral-800' : '' }}">
            {{ $technician->on_leave ? 'On leave — mark back' : 'Mark on leave' }}
        </button>
        <a href="/users/{{ $technician->id }}" wire:navigate class="btn-outline shrink-0">Manage account</a>
    </div>

    <div class="mb-5 card">
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0 flex-1">
                <p class="text-xs font-medium text-neutral-500">Specialty</p>
                @if ($editingSpecialty)
                    <form wire:submit="saveSpecialty" class="mt-1 flex items-center gap-2">
                        <input wire:model="specialty" type="text" placeholder="e.g. Weighbridges, load cells" class="input flex-1">
                        <button type="submit" class="btn-primary shrink-0">Save</button>
                        <button type="button" wire:click="toggleEditSpecialty" class="btn-ghost shrink-0">Cancel</button>
                    </form>
                    @error('specialty') <p class="field-error">{{ $message }}</p> @enderror
                @else
                    <p class="mt-1 text-sm text-neutral-900">{{ $technician->specialty ?: 'Not set' }}</p>
                @endif
            </div>
            @unless ($editingSpecialty)
                <button type="button" wire:click="toggleEditSpecialty" class="shrink-0 text-xs font-semibold text-primary-700 hover:text-primary-800">
                    Edit
                </button>
            @endunless
        </div>
    </div>

    <div class="mb-5 grid grid-cols-4 gap-4">
        <div class="card">
            <p class="text-xs font-medium text-neutral-500">Open jobs</p>
            <p class="mt-1 text-2xl font-semibold text-neutral-900">{{ $openJobs->count() }}</p>
        </div>
        <div class="card">
            <p class="text-xs font-medium text-neutral-500">Overdue</p>
            <p class="mt-1 text-2xl font-semibold text-critical-700">{{ $overdueJobs->count() }}</p>
        </div>
        <div class="card">
            <p class="text-xs font-medium text-neutral-500">Closed to date</p>
            <p class="mt-1 text-2xl font-semibold text-neutral-900">{{ $closedCount }}</p>
        </div>
        <div class="card">
            <p class="text-xs font-medium text-neutral-500">On-time rate</p>
            <p class="mt-1 text-2xl font-semibold {{ $onTimeRate === null ? 'text-neutral-400' : ($onTimeRate >= 90 ? 'text-success-700' : ($onTimeRate >= 75 ? 'text-amber-700' : 'text-critical-700')) }}">
                {{ $onTimeRate === null ? '—' : $onTimeRate.'%' }}
            </p>
        </div>
    </div>

    <div class="grid grid-cols-3 gap-5">
        <div class="col-span-2 space-y-5">
            @if ($overdueJobs->count() > 0)
                <div class="card">
                    <h2 class="mb-3 text-sm font-semibold text-neutral-900">Overdue jobs</h2>
                    <div class="space-y-2">
                        @foreach ($overdueJobs as $job)
                            <a href="/jobs/{{ $job->id }}" wire:navigate class="flex items-center justify-between gap-3 rounded-[var(--radius-md)] border border-critical-500 bg-critical-50 px-3 py-2.5 hover:bg-critical-100">
                                <div class="min-w-0">
                                    <p class="text-sm font-medium text-neutral-900">{{ $job->reference }}</p>
                                    <p class="truncate text-xs text-neutral-500">{{ $job->customer->name }} &middot; due {{ $job->due_date->format('d M Y') }}</p>
                                </div>
                                <span class="pill-danger shrink-0">{{ $job->status }}</span>
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif

            <div class="card">
                <h2 class="mb-3 text-sm font-semibold text-neutral-900">Recent jobs</h2>
                <div class="space-y-2">
                    @forelse ($recentJobs as $job)
                        @php $isOverdue = $job->due_date->isPast() && $job->status !== 'Closed'; @endphp
                        <a href="/jobs/{{ $job->id }}" wire:navigate class="flex items-center justify-between gap-3 rounded-[var(--radius-md)] border border-neutral-100 px-3 py-2.5 hover:bg-neutral-50">
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <p class="text-sm font-medium text-neutral-900">{{ $job->reference }}</p>
                                    @if ($isOverdue)
                                          <span class="pill-danger">Overdue</span>
                                    @endif
                                </div>
                                <p class="truncate text-xs text-neutra-500">{{ $job->customer->name }} &middot; {{ $job->nature_of_visit }} &middot; due {{ $job->due_date->format('d M Y') }}</p>
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
                        <p class="text-sm text-neutral-500">No jobs on file for this technician.</p>
                    @endforelse
                </div>
            </div>
        </div>

        <div class="card">
            <h2 class="mb-3 text-sm font-semibold text-neutral-900">Certificates &amp; documents</h2>
            <div class="divide-y divide-neutral-100">
                @forelse ($certificates as $doc)
                    <div class="py-2.5">
                        <x-expiry-ring
                           :percent="$doc->percentUsedCapped()"
                            :stage="$doc->expiryStage()"
                            :title="$doc->document_type"
                            :expiresAt="$doc->expiresAt()->format('d M Y')" />
                    </div>
                @empty
                    <p class="py-2 text-sm text-neutral-500">No documents on file.</p>
                @endforelse
            </div>
            <a href="/users/{{ $technician->id }}" wire:navigate class="mt-3 block text-center text-xs font-semibold text-primary-700 hover:text-primary-800">
                Manage documents
            </a>
        </div>
    </div>
</div>
