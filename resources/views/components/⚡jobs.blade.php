<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Models\WorkOrder;

new #[Layout('layouts.app', ['title' => 'Jobs'])] class extends Component
{
    public function mount(): void
    {
        $this->authorize('viewAny', WorkOrder::class);
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

        return [
            'jobs' => $query->get(),
        ];
    }
};
?>

<div>
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold text-gray-900">Jobs</h1>
            <p class="text-sm text-gray-500">{{ $jobs->count() }} total</p>
        </div>
    </div>

    <div class="space-y-3">
        @forelse ($jobs as $job)
            <a href="/jobs/{{ $job->id }}" wire:navigate class="block border border-gray-200 bg-white p-4 hover:border-gray-300">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-gray-900">{{ $job->reference }}</p>
                        <p class="truncate text-sm text-gray-600">{{ $job->customer->name }}</p>
                        <p class="mt-1 text-xs text-gray-500">{{ $job->nature_of_visit }} &middot; due {{ $job->due_date->format('d M') }}</p>
                        <p class="mt-1 text-xs text-gray-400">{{ $job->technician->name }}</p>
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
            </a>
        @empty
            <div class="border border-dashed border-gray-300 p-8 text-center text-sm text-gray-500">
                No jobs yet.
            </div>
        @endforelse
    </div>
</div>
