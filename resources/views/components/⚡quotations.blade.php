<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Models\Quotation;

new #[Layout('layouts.app', ['title' => 'Quotations'])] class extends Component
{
    use \App\Support\ShowsMore;

    public string $statusFilter = 'All';

    public function mount(): void
    {
        $this->authorize('viewAny', Quotation::class);
    }

    public function setFilter(string $status): void
    {
        $this->statusFilter = $status;
        $this->limit = 40;
    }

    public function with(): array
    {
        $user = auth()->user();

        $query = Quotation::with(['customer', 'items'])->latest();

        if ($user->hasRole('Customer')) {
            $query->where('customer_id', $user->customer_id);
        }

        if ($this->statusFilter !== 'All') {
            $query->where('status', $this->statusFilter);
        }

        $total = (clone $query)->count();

        return [
            'total' => $total,
            'quotations' => $query->limit($this->limit)->get(),
        ];
    }
};
?>

<div>
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold text-neutral-900">Quotations</h1>
            <p class="text-sm text-neutral-500">{{ number_format($total) }} {{ $statusFilter === 'All' ? 'total' : 'matching' }}</p>
        </div>
        @can('create', \App\Models\Quotation::class)
            <a href="/quotations/create" wire:navigate class="btn-primary">
                New quotation
            </a>
        @endcan
    </div>

    <div class="mb-5 flex flex-wrap gap-1 border-b border-neutral-200">
        @foreach (['All', 'Awaiting Supervisor', 'Awaiting Manager', 'Approved', 'Accepted', 'Sent back', 'Converted'] as $status)
            <button
                wire:click="setFilter('{{ $status }}')"
                @class([
                    'border-b-2 px-3 py-2 text-sm font-medium transition-colors',
                    'border-primary-500 text-primary-600' => $statusFilter === $status,
                    'border-transparent text-neutral-500 hover:text-neutral-900' => $statusFilter !== $status,
                ])
            >
                {{ $status }}
            </button>
        @endforeach
    </div>

    <div class="space-y-3">
        @forelse ($quotations as $quotation)
            <a href="/quotations/{{ $quotation->id }}" wire:navigate class="card flex items-start justify-between gap-3 hover:shadow-[var(--shadow-card-hover)]">
                <div class="min-w-0">
                    <p class="text-sm font-semibold text-neutral-900">{{ $quotation->reference }}</p>
                    <p class="truncate text-sm text-neutral-600">{{ $quotation->customer->name }}</p>
                    <p class="mt-1 truncate text-xs text-neutral-500">{{ $quotation->scope }}</p>
                </div>
                <div class="shrink-0 text-right">
                    <p class="text-sm font-semibold text-neutral-900">{{ $quotation->currency_code }} {{ number_format($quotation->totalMinor() / 100, 2) }}</p>
                    <span @class([
                        'mt-1 inline-block',
                        'pill-neutral' => str_starts_with($quotation->status, 'Awaiting'),
                        'pill-info' => in_array($quotation->status, ['Approved', 'Accepted']),
                        'pill-success' => $quotation->status === 'Converted',
                        'pill-danger' => $quotation->status === 'Sent back',
                    ])>
                        {{ $quotation->status }}
                    </span>
                </div>
            </a>
        @empty
            <div class="card border-dashed text-center text-sm text-neutral-500">
                No quotations match this filter.
            </div>
        @endforelse
    </div>
    <x-show-more :shown="$quotations->count()" :total="$total" />
</div>
