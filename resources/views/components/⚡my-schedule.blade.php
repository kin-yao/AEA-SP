<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Models\WorkOrder;

new #[Layout('layouts.app', ['title' => 'My schedule'])] class extends Component
{
    public int $weekOffset = 0;

    public function mount(): void
    {
        abort_unless(auth()->user()->hasRole('Technician'), 403);
    }

    public function previousWeek(): void
    {
        $this->weekOffset--;
    }

    public function nextWeek(): void
    {
        $this->weekOffset++;
    }

    public function thisWeek(): void
    {
        $this->weekOffset = 0;
    }

    public function with(): array
    {
        $start = now()->startOfWeek()->addWeeks($this->weekOffset)->startOfDay();
        $end = $start->copy()->addDays(6);

        $jobs = WorkOrder::with(['customer', 'site'])
            ->where('assigned_technician_id', auth()->id())
            ->whereBetween('due_date', [$start->toDateString(), $end->toDateString()])
            ->orderBy('due_date')
            ->get()
            ->groupBy(fn (WorkOrder $job) => $job->due_date->toDateString());

        $days = collect(range(0, 6))->map(function (int $i) use ($start, $jobs) {
            $date = $start->copy()->addDays($i);

            return [
                'date' => $date,
                'isToday' => $date->isToday(),
                'jobs' => $jobs->get($date->toDateString(), collect()),
            ];
        });

        return [
            'days' => $days,
            'rangeLabel' => $start->format('j M').' to '.$end->format('j M Y'),
            'jobCount' => $jobs->sum(fn ($group) => $group->count()),
            'overdue' => $this->weekOffset === 0
                ? WorkOrder::with('customer')
                    ->where('assigned_technician_id', auth()->id())
                    ->whereIn('status', ['Assigned', 'On site'])
                    ->where('due_date', '<', today())
                    ->orderBy('due_date')
                    ->get()
                : collect(),
        ];
    }
};
?>

<div>
    <div class="mb-6 flex flex-wrap items-center gap-4">
        <div>
            <h1 class="text-xl font-semibold text-neutral-900">My schedule</h1>
            <p class="text-sm text-neutral-500">{{ $rangeLabel }} &middot; {{ $jobCount }} {{ $jobCount === 1 ? 'job' : 'jobs' }}</p>
        </div>
        <div class="h-px min-w-[40px] flex-1 border-t border-dashed border-neutral-300"></div>
        <div class="flex shrink-0 gap-2">
            <button type="button" wire:click="previousWeek" class="btn-outline">Previous</button>
            <button type="button" wire:click="thisWeek" class="btn-outline">This week</button>
            <button type="button" wire:click="nextWeek" class="btn-outline">Next</button>
        </div>
    </div>

    @if ($overdue->isNotEmpty())
        <div class="card mb-6 border-critical-500">
            <p class="mb-3 text-sm font-semibold text-critical-700">Overdue, still open ({{ $overdue->count() }})</p>
            <div class="space-y-2">
                @foreach ($overdue as $job)
                    <a href="/jobs/{{ $job->id }}" wire:navigate class="flex items-center justify-between gap-3 rounded-[var(--radius-md)] px-2 py-1.5 hover:bg-neutral-50">
                        <div class="min-w-0">
                            <span class="text-sm font-semibold text-neutral-900">{{ $job->reference }}</span>
                            <span class="ml-2 text-sm text-neutral-600">{{ $job->customer->name }}</span>
                        </div>
                        <span class="shrink-0 text-xs text-critical-700">was due {{ $job->due_date->format('d M') }}</span>
                    </a>
                @endforeach
            </div>
        </div>
    @endif

    <div class="grid gap-3" style="grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));">
        @foreach ($days as $day)
            <div @class([
                'card',
                'border-primary-500' => $day['isToday'],
            ])>
                <div class="mb-3 flex items-center justify-between">
                    <p class="text-sm font-semibold text-neutral-900">{{ $day['date']->format('D j M') }}</p>
                    @if ($day['isToday'])
                        <span class="pill-info">Today</span>
                    @endif
                </div>

                @forelse ($day['jobs'] as $job)
                    <a href="/jobs/{{ $job->id }}" wire:navigate
                       class="mb-2 block rounded-[var(--radius-md)] border border-neutral-200 p-2.5 hover:bg-neutral-50">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-sm font-semibold text-neutral-900">{{ $job->reference }}</p>
                            <span @class([
                                'shrink-0',
                                'pill-neutral' => $job->status === 'Assigned',
                                'pill-info' => in_array($job->status, ['On site', 'Awaiting review']),
                                'pill-success' => in_array($job->status, ['Approved', 'Closed']),
                            ])>{{ $job->status }}</span>
                        </div>
                        <p class="mt-1 truncate text-xs text-neutral-600">{{ $job->customer->name }}</p>
                        <p class="truncate text-xs text-neutral-400">{{ $job->site?->name ?? $job->nature_of_visit }}</p>
                    </a>
                @empty
                    <p class="text-xs text-neutral-400">Nothing scheduled.</p>
                @endforelse
            </div>
        @endforeach
    </div>
</div>
