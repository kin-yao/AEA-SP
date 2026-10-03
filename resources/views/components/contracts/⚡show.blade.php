<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Models\Contract;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\Storage;

new #[Layout('layouts.app', ['title' => 'Contract'])] class extends Component
{
    public Contract $contract;

    public function mount(Contract $contract): void
    {
        $this->authorize('view', $contract);
        $contract->load('customer');
        $this->contract = $contract;
    }

    public function with(): array
    {
        return [
            'nextVisit' => $this->contract->nextVisit(),
            'visitHistory' => $this->contract->workOrders()
                ->with('technician')
                ->orderByDesc('due_date')
                ->get(),
        ];
    }

    public function terminate(): void
    {
        $this->authorize('terminate', $this->contract);

        $this->contract->update(['status' => 'Terminated']);
        $this->contract->refresh();
    }
};
?>

<div>
    <a href="/contracts" wire:navigate class="mb-4 inline-flex items-center gap-1.5 text-sm text-neutral-500 hover:text-neutral-900">
        <x-icon name="arrow-right" class="h-3.5 w-3.5 rotate-180" />
        Back to contracts
    </a>

    <div class="mb-6 flex items-center justify-between">
        <div class="flex items-center gap-3">
            <h1 class="text-xl font-semibold text-neutral-900">{{ $contract->reference }}</h1>
            <span class="pill-{{ $contract->status === 'Active' ? 'success' : 'neutral' }}">{{ $contract->status }}</span>
        </div>

        <div class="flex gap-2">
            @if ($contract->status === 'Terminated' && auth()->user()->can('create', \App\Models\Contract::class))
                <a href="/contracts/create?customer={{ $contract->customer_id }}" wire:navigate class="btn-outline">
                    Create new contract
                </a>
            @endif
            @can('terminate', $contract)
                <button type="button" wire:click="terminate" wire:confirm="Terminate this contract?" class="btn-outline">
                    Terminate
                </button>
            @endcan
        </div>
    </div>

    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
        <div class="card flex flex-col items-center justify-center text-center">
            <x-expiry-ring
                :percent="$contract->percentOfTermUsedCapped()"
                :stage="$contract->status === 'Terminated' ? 'fresh' : $contract->expiryStage()"
                :title="$contract->value_minor ? $contract->currency_code.' '.number_format($contract->value_minor / 100, 2) : 'No value on file'"
                :expiresAt="$contract->ends_at->format('d M Y')"
            />
        </div>

        <div class="card">
            <dl class="space-y-3 text-sm">
                <div class="flex justify-between">
                    <dt class="text-neutral-500">Customer</dt>
                    <dd class="font-medium text-neutral-900">{{ $contract->customer->name }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-neutral-500">Type</dt>
                    <dd class="font-medium text-neutral-900">{{ $contract->type }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-neutral-500">Start date</dt>
                    <dd class="font-medium text-neutral-900">{{ $contract->starts_at->format('d M Y') }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-neutral-500">End date</dt>
                    <dd class="font-medium text-neutral-900">{{ $contract->ends_at->format('d M Y') }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-neutral-500">Visits used</dt>
                    <dd class="font-medium text-neutral-900">{{ $contract->visits_included - $contract->visitsRemaining() }}/{{ $contract->visits_included }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-neutral-500">Visits remaining</dt>
                    <dd class="font-medium text-neutral-900">{{ $contract->visitsRemaining() }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-neutral-500">Value</dt>
                    <dd class="font-medium text-neutral-900">
                        {{ $contract->value_minor ? $contract->currency_code.' '.number_format($contract->value_minor / 100, 2) : '—' }}
                    </dd>
                </div>
            </dl>
        </div>
    </div>

    <div class="card mt-4">
        <p class="mb-2 text-sm font-medium text-neutral-700">Next visit</p>
        @if ($nextVisit)
            <p class="text-sm text-neutral-900">
                {{ $nextVisit->due_date->format('d M Y') }} &middot; {{ $nextVisit->technician->name ?? 'Technician to be confirmed' }}
                <span class="text-neutral-500">&middot; {{ $nextVisit->nature_of_visit }}</span>
            </p>
        @else
            <p class="text-sm text-neutral-500">No visit currently scheduled.</p>
        @endif
    </div>

    <div class="card mt-4">
        <p class="mb-2 text-sm font-medium text-neutral-700">Visit history</p>
        @forelse ($visitHistory as $visit)
            <div class="flex items-center justify-between border-b border-neutral-100 py-2 text-sm last:border-0">
                <div>
                    <p class="font-medium text-neutral-900">{{ $visit->due_date->format('d M Y') }} &middot; {{ $visit->technician->name ?? 'Unassigned' }}</p>
                    <p class="text-xs text-neutral-500">{{ $visit->reference }} &middot; {{ $visit->nature_of_visit }}</p>
                </div>
                <span class="pill-{{ $visit->status === 'Closed' ? 'success' : 'info' }}">{{ $visit->status }}</span>
            </div>
        @empty
            <p class="text-sm text-neutral-500">No visits logged against this contract yet.</p>
        @endforelse
    </div>

    <div class="card mt-4">
        <p class="mb-2 text-sm font-medium text-neutral-700">Signed contract</p>
        @if ($contract->scan_file_path)
            <a href="{{ Storage::url($contract->scan_file_path) }}" target="_blank" class="inline-flex items-center gap-1.5 text-sm font-medium text-primary-700 hover:underline">
                <x-icon name="file-earmark-pdf" class="h-4 w-4" />
                View document
            </a>
        @else
            <p class="text-sm text-neutral-500">No document on file.</p>
        @endif
    </div>
</div>
