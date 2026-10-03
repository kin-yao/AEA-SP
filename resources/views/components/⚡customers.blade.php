<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Models\Customer;
use App\Models\Branch;

new #[Layout('layouts.app', ['title' => 'Customers'])] class extends Component
{
    public string $search = '';
    public string $branchFilter = 'All';
    public string $contractFilter = 'All';

    public function mount(): void
    {
        $this->authorize('viewAny', Customer::class);
    }

    public function setBranch(string $branch): void
    {
        $this->branchFilter = $branch;
    }

    public function setContract(string $status): void
    {
        $this->contractFilter = $status;
    }

    public function with(): array
    {
        $query = Customer::with('branch.country')->withCount([
            'serviceRequests as open_requests_count' => fn ($q) => $q->where('status', 'Open'),
            'invoices as overdue_invoices_count' => fn ($q) => $q->where('status', '!=', 'Paid')->where('due_at', '<', now()),
        ])->orderBy('name');

        if ($this->search !== '') {
            $query->where(function ($q) {
                $q->where('name', 'like', '%'.$this->search.'%')
                    ->orWhere('reference', 'like', '%'.$this->search.'%')
                    ->orWhere('main_contact_name', 'like', '%'.$this->search.'%');
            });
        }

        if ($this->branchFilter !== 'All') {
            $query->where('branch_id', $this->branchFilter);
        }

        if ($this->contractFilter === 'On contract') {
            $query->where('has_active_contract', true);
        } elseif ($this->contractFilter === 'No contract') {
            $query->where('has_active_contract', false);
        }

        $customers = $query->get();

        return [
            'customers' => $customers,
            'branches' => Branch::orderBy('name')->get(),
            'totalCustomers' => Customer::count(),
            'onContractCount' => Customer::where('has_active_contract', true)->count(),
            'overdueBalanceCount' => Customer::where('balance_minor', '>', 0)->count(),
        ];
    }
};
?>

<div>
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-xl font-semibold text-neutral-900">Customers</h1>
        @can('create', \App\Models\Customer::class)
            <a href="/customers/create" wire:navigate class="btn-primary">New customer</a>
        @endcan
    </div>

    <div class="mb-5 grid grid-cols-3 gap-4">
        <div class="card">
            <p class="text-xs font-medium text-neutral-500">Customers on file</p>
            <p class="mt-1 text-2xl font-semibold text-neutral-900">{{ $totalCustomers }}</p>
        </div>
        <div class="card">
            <p class="text-xs font-medium text-neutral-500">On an active contract</p>
            <p class="mt-1 text-2xl font-semibold text-success-700">{{ $onContractCount }}</p>
        </div>
        <div class="card">
            <p class="text-xs font-medium text-neutral-500">Carrying a balance</p>
            <p class="mt-1 text-2xl font-semibold text-urgent-700">{{ $overdueBalanceCount }}</p>
        </div>
    </div>

    <div class="mb-4 flex flex-wrap items-center gap-3">
        <input wire:model.live.debounce.400ms="search" type="text" placeholder="Search by name, reference or contact"
               class="input max-w-sm">

        <select wire:model.live="branchFilter" class="input w-auto">
            <option value="All">All branches</option>
            @foreach ($branches as $branch)
                <option value="{{ $branch->id }}">{{ $branch->name }}</option>
            @endforeach
        </select>

        <div class="flex gap-1.5">
            @foreach (['All', 'On contract', 'No contract'] as $status)
                <button type="button" wire:click="setContract('{{ $status }}')"
                        class="rounded-full px-3 py-1.5 text-xs font-medium {{ $contractFilter === $status ? 'bg-primary-50 text-primary-700' : 'bg-neutral-100 text-neutral-500 hover:bg-neutral-200' }}">
                    {{ $status }}
                </button>
            @endforeach
        </div>
    </div>

    <div class="space-y-3">
        @forelse ($customers as $customer)
            <a href="/customers/{{ $customer->id }}" wire:navigate class="card flex items-center gap-4 hover:border-neutral-300">
                <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-neutral-100 text-sm font-semibold text-neutral-600">
                    {{ collect(explode(' ', $customer->name))->map(fn ($p) => $p[0] ?? '')->take(2)->implode('') }}
                </div>
                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2">
                        <p class="truncate text-sm font-semibold text-neutral-900">{{ $customer->name }}</p>
                        @if ($customer->has_active_contract)
                            <span class="pill-success">On contract</span>
                        @else
                            <span class="pill-neutral">No contract</span>
                        @endif
                        @if ($customer->overdue_invoices_count > 0)
                            <span class="pill-danger">{{ $customer->overdue_invoices_count }} overdue</span>
                        @endif
                    </div>
                    <p class="mt-0.5 text-xs text-neutral-500">
                        {{ $customer->reference }} &middot; {{ $customer->branch->name }}
                        @if ($customer->main_contact_name)
                            &middot; {{ $customer->main_contact_name }}
                        @endif
                    </p>
                    @if ($customer->open_requests_count > 0)
                        <p class="mt-0.5 text-xs text-neutral-500">{{ $customer->open_requests_count }} open request{{ $customer->open_requests_count === 1 ? '' : 's' }}</p>
                    @endif
                </div>
                @if ($customer->balance_minor > 0)
                    <div class="shrink-0 text-right">
                        <p class="text-xs text-neutral-500">Balance</p>
                        <p class="text-sm font-semibold text-urgent-700">KES {{ $customer->balanceFormatted() }}</p>
                    </div>
                @endif
                <x-icon name="arrow-right" class="h-4 w-4 shrink-0 text-neutral-300" />
            </a>
        @empty
            <div class="card text-center text-sm text-neutral-500">No customers found.</div>
        @endforelse
    </div>
</div>
