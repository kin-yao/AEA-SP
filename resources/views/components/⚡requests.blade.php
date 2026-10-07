<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Models\ServiceRequest;

new #[Layout('layouts.app', ['title' => 'Requests'])] class extends Component
{
    use \App\Support\ShowsMore;

    public string $statusFilter = 'All';

    public function mount(): void
    {
        $this->authorize('viewAny', ServiceRequest::class);
    }

    public function setFilter(string $status): void
    {
        $this->statusFilter = $status;
        $this->limit = 40;
    }

    public function with(): array
    {
        $user = auth()->user();

        $query = ServiceRequest::with(['customer', 'technician'])->latest();

        if ($user->hasRole('Customer')) {
            $query->where('customer_id', $user->customer_id);
        }

        if ($this->statusFilter !== 'All') {
            $query->where('status', $this->statusFilter);
        }

        return [
            'requests' => (clone $query)->limit($this->limit)->get(),
            'totalCount' => $query->count(),
        ];
    }
};
?>

<div>
    <div class="mb-5 flex items-start justify-between">
        <div>
            <h1 class="text-2xl font-semibold text-neutral-900">{{ auth()->user()->hasRole('Customer') ? 'My requests' : 'Requests' }}</h1>
            <p class="text-sm text-neutral-500">{{ $requests->count() }} of {{ number_format($totalCount) }} shown</p>
        </div>
        @can('create', \App\Models\ServiceRequest::class)
            <a href="/requests/create" wire:navigate class="btn-primary">
                Log request
            </a>
        @endcan
    </div>

    <div class="mb-5 flex flex-wrap gap-1 border-b border-neutral-200 pb-px">
        @foreach (['All', 'Open', 'Assigned', 'Quoted', 'Converted', 'Declined'] as $status)
            <button wire:click="setFilter('{{ $status }}')"
                    class="rounded-t-[var(--radius-sm)] border-b-2 px-3 py-2 text-sm font-medium {{ $statusFilter === $status ? 'border-primary-500 text-primary-600' : 'border-transparent text-neutral-500 hover:text-neutral-700' }}">
                {{ $status }}
            </button>
        @endforeach
    </div>

    <div class="space-y-3">
        @forelse ($requests as $request)
            <a href="/requests/{{ $request->id }}" wire:navigate class="card block hover:border-primary-200">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-neutral-900">{{ $request->reference }}</p>
                        <p class="truncate text-sm text-neutral-600">{{ $request->customer->name }}</p>
                        <p class="mt-1 truncate text-xs text-neutral-500">{{ $request->fault_description }}</p>
                        @if ($request->technician)
                            <p class="mt-1 text-xs text-neutral-400">Assigned to {{ $request->technician->name }}</p>
                        @endif
                    </div>
                    @php
                        $pill = match (true) {
                            $request->status === 'Converted' => 'pill-success',
                            in_array($request->status, ['Assigned', 'Quoted']) => 'pill-info',
                            $request->status === 'Declined' => 'pill-danger',
                            default => 'pill-neutral',
                        };
                    @endphp
                    <span class="{{ $pill }} shrink-0">{{ $request->status }}</span>
                </div>
            </a>
        @empty
            <div class="card border-dashed text-center text-sm text-neutral-500">
                No requests match this filter.
            </div>
        @endforelse
    </div>
    <x-show-more :shown="$requests->count()" :total="$totalCount" />
</div>
