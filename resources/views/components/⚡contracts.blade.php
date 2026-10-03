<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Models\Contract;

new #[Layout('layouts.app', ['title' => 'Contracts'])] class extends Component
{
    public string $statusFilter = 'All';
    public string $search = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Contract::class);
    }

    public function setFilter(string $status): void
    {
        $this->statusFilter = $status;
    }

    public function with(): array
    {
        $user = auth()->user();

        $query = Contract::with('customer')->orderBy('ends_at');

        if ($user->hasRole('Customer')) {
            $query->where('customer_id', $user->customer_id);
        }

        if ($this->search !== '') {
            $query->whereHas('customer', fn ($q) => $q->where('name', 'like', '%'.$this->search.'%'));
        }

        if ($this->statusFilter !== 'All') {
            $query->where('status', $this->statusFilter);
        }

        $contracts = $query->get();

        return [
            'contracts' => $contracts,
            'expiringCount' => $contracts->filter(
                fn (Contract $c) => $c->status === 'Active' && in_array($c->expiryStage(), ['urgent', 'critical'])
            )->count(),
            'visitsRemaining' => $contracts->where('status', 'Active')->sum(fn (Contract $c) => $c->visitsRemaining()),
        ];
    }
};
?>

<div>
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-xl font-semibold text-neutral-900">
            {{ auth()->user()->hasRole('Customer') ? 'My contract' : 'Contracts' }}
        </h1>
        @can('create', \App\Models\Contract::class)
            <a href="/contracts/create" wire:navigate class="btn-primary">New contract</a>
        @endcan
    </div>

    @unless (auth()->user()->hasRole('Customer'))
        <div class="mb-5 grid grid-cols-3 gap-4">
            <div class="card">
                <p class="text-xs font-medium text-neutral-500">Contracts on file</p>
                <p class="mt-1 text-2xl font-semibold text-neutral-900">{{ $contracts->count() }}</p>
            </div>
            <div class="card">
                <p class="text-xs font-mediow text-neutral-500">Nearing expiry</p>
                <p class="mt-1 text-2xl font-semibold text-urgent-700">{{ $expiringCount }}</p>
            </div>
            <div class="card">
                <p class="text-xs font-medium text-neutral-500">Visits remaining</p>
                <p class="mt-1 text-2xl font-semibold text-neutral-900">{{ $visitsRemaining }}</p>
            </div>
        </div>

        <div class="mb-4">
            <input wire:model.live.debounce.400ms="search" type="text" placeholder="Search by customer name"
                   class="input max-w-sm">
        </div>
    @endunless

    <div class="mb-4 flex gap-1.5 border-b border-neutral-200">
        @foreach (['All', 'Active', 'Terminated'] as $status)
            <button type="button" wire:click="setFilter('{{ $status }}')"
                    class="border-b-2 px-3 py-2 text-sm font-medium {{ $statusFilter === $status ? 'border-primary-600 text-primary-700' : 'border-transparent text-neutral-500 hover:text-neutral-700' }}">
                {{ $status }}
            </button>
        @endforeach
    </div>

    <div class="space-y-3">
        @forelse ($contracts as $contract)
            <a href="/contracts/{{ $contract->id }}" wire:navigate class="card flex items-center gap-4 hover:border-neutral-300">
                <x-expiry-ring
                    :percent="$contract->percentOfTermUsedCapped()"
                    :stage="$contract->status === 'Terminated' ? 'fresh' : $contract->expiryStage()"
                    :title="$contract->value_minor ? $contract->currency_code.' '.number_format($contract->value_minor / 100, 2) : 'No value on file'"
                    :expiresAt="$contract->ends_at->format('d M Y')"
                />
                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2">
                        <p class="truncate text-sm font-semibold text-neutral-900">{{ $contract->customer->name }}</p>
                        <span class="pill-{{ $contract->status === 'Active' ? 'success' : 'neutral' }}">{{ $contract->status }}</span>
                    </div>
                    <p class="mt-0.5 text-xs text-neutral-500">{{ $contract->reference }} &middot; {{ $contract->type }}</p>
                    <p class="mt-0.5 text-xs text-neutral-500">
                        {{ $contract->starts_at->format('d M Y') }} &ndash; {{ $contract->ends_at->format('d M Y') }}
                        &middot; {{ $contract->visitsRemaining() }}/{{ $contract->visits_included }} visits left
                    </p>
                </div>
                <x-icon name="arrow-right" class="h-4 w-4 shrink-0 text-neutral-300" />
            </a>
        @empty
            <div class="card text-center text-sm text-neutral-500">No contracts found.</div>
        @endforelse
    </div>
</div>
