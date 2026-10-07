<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Models\Invoice;
use App\Models\WorkOrder;

new #[Layout('layouts.app', ['title' => 'Invoices'])] class extends Component
{
    use \App\Support\ShowsMore;

    public string $statusFilter = 'All';

    public function mount(): void
    {
        $this->authorize('viewAny', Invoice::class);
    }

    public function setFilter(string $status): void
    {
        $this->statusFilter = $status;
        $this->limit = 40;
    }

    public function with(): array
    {
        $user = auth()->user();

        // Only Finance sees this list, so nobody else pays for the query.
        $readyToInvoice = $user->hasRole('Finance')
            ? WorkOrder::with('customer')
                ->whereHas('documents', fn ($q) => $q->where('type', 'rep')->where('status', 'Released'))
                ->whereDoesntHave('invoices')
                ->latest()
                ->limit(50)
                ->get()
            : collect();

        $query = Invoice::with('customer')->latest();

        if ($user->hasRole('Customer')) {
            $query->where('customer_id', $user->customer_id);
        }

        if ($this->statusFilter === 'Overdue') {
            $query->where('due_at', '<', now())->whereNotIn('status', ['Draft', 'Paid']);
        } elseif ($this->statusFilter !== 'All') {
            $query->where('status', $this->statusFilter);
        }

        $total = (clone $query)->count();

        return [
            'readyToInvoice' => $readyToInvoice,
            'total' => $total,
            'invoices' => $query->limit($this->limit)->get(),
        ];
    }
};
?>

<div>
    <div class="mb-6">
        <h1 class="text-xl font-semibold text-neutral-900">Invoices</h1>
        <p class="text-sm text-neutral-500">{{ number_format($total) }} {{ $statusFilter === 'All' ? 'total' : 'matching' }}</p>
    </div>

    @if ($readyToInvoice->isNotEmpty())
        <div class="mb-6">
            <h2 class="mb-3 text-sm font-semibold text-neutral-900">Ready to invoice</h2>
            <div class="space-y-3">
                @foreach ($readyToInvoice as $job)
                    <a href="/invoices/create/{{ $job->id }}" wire:navigate
                       class="card flex items-center justify-between" style="background-color: var(--color-info-50); border-color: var(--color-info-200)">
                        <div>
                            <p class="text-sm font-semibold text-neutral-900">{{ $job->reference }}</p>
                            <p class="text-sm text-neutral-600">{{ $job->customer->name }}</p>
                        </div>
                        <span class="text-xs font-semibold text-info-700">Raise invoice &rarr;</span>
                    </a>
                @endforeach
            </div>
        </div>
    @endif

    <div class="mb-5 flex flex-wrap gap-1 border-b border-neutral-200">
        @foreach (['All', 'Draft', 'Unpaid', 'Part paid', 'Paid', 'Overdue'] as $status)
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
        @forelse ($invoices as $invoice)
            @php
                $isOverdue = $invoice->due_at->isPast() && ! in_array($invoice->status, ['Draft', 'Paid']);
            @endphp
            <a href="/invoices/{{ $invoice->id }}" wire:navigate class="card flex items-start justify-between gap-3 hover:shadow-[var(--shadow-card-hover)]">
                <div class="min-w-0">
                    <div class="flex items-center gap-2">
                        <p class="text-sm font-semibold text-neutral-900">{{ $invoice->reference }}</p>
                        @if ($isOverdue)
                            <span class="pill-danger">Overdue</span>
                        @endif
                    </div>
                    <p class="truncate text-sm text-neutral-600">{{ $invoice->customer->name }}</p>
                    <p class="mt-1 text-xs text-neutral-500">Due {{ $invoice->due_at->format('d M Y') }}</p>
                </div>
                <div class="shrink-0 text-right">
                    <p class="text-sm font-semibold text-neutral-900">{{ $invoice->currency_code }} {{ number_format($invoice->amount_minor / 100, 2) }}</p>
                    <span @class([
                        'mt-1 inline-block',
                        'pill-neutral' => $invoice->status === 'Draft',
                        'pill-info' => in_array($invoice->status, ['Unpaid', 'Part paid']),
                        'pill-success' => $invoice->status === 'Paid',
                    ])>
                        {{ $invoice->status }}
                    </span>
                </div>
            </a>
        @empty
            <div class="card border-dashed text-center text-sm text-neutral-500">
                No invoices match this filter.
            </div>
        @endforelse
    </div>
    <x-show-more :shown="$invoices->count()" :total="$total" />
</div>
