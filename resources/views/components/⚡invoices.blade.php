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

    /** One click: invoice a finished job from its agreed prices and send it. */
    public function quickInvoice(int $jobId): void
    {
        $this->authorize('create', Invoice::class);
        $job = WorkOrder::with('sourceQuotation.lpoDetail')->findOrFail($jobId);

        if (! \App\Services\InvoiceBuilder::canRaiseDirectly($job)) {
            $this->redirect('/invoices/create/'.$job->id, navigate: true);

            return;
        }

        try {
            $invoice = \App\Services\InvoiceBuilder::raise($job, auth()->id());
        } catch (\DomainException $e) {
            $this->addError('quick', $e->getMessage());

            return;
        }

        $this->redirect('/invoices/'.$invoice->id, navigate: true);
    }

    public function with(): array
    {
        $user = auth()->user();

        // Only Finance sees this list, so nobody else pays for the query.
        $readyToInvoice = $user->hasRole('Finance')
            ? WorkOrder::with(['customer', 'sourceQuotation.lpoDetail'])
                ->readyToInvoice()
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
        } elseif ($this->statusFilter === 'Unpaid') {
            $query->whereIn('status', ['Unpaid', 'Part paid']);
        } elseif ($this->statusFilter !== 'All') {
            $query->where('status', $this->statusFilter);
        }

        $total = (clone $query)->count();

        $money = $user->hasRole('Customer') ? null : [
            'owed' => (int) Invoice::whereIn('status', ['Unpaid', 'Part paid'])->selectRaw('COALESCE(SUM(amount_minor - paid_minor), 0) as d')->value('d'),
            'overdue' => Invoice::where('due_at', '<', now())->whereIn('status', ['Unpaid', 'Part paid'])->count(),
            'drafts' => Invoice::where('status', 'Draft')->count(),
        ];

        return [
            'money' => $money,
            'user' => $user,
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

    @if ($money)
        <div class="mb-5 grid gap-3" style="grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));">
            <div class="card"><p class="text-xs text-neutral-500">Waiting to be invoiced</p><p class="mt-1 font-mono text-2xl font-bold text-neutral-900">{{ $readyToInvoice->count() }}</p></div>
            <div class="card"><p class="text-xs text-neutral-500">Customers owe us</p><p class="mt-1 font-mono text-xl font-bold text-neutral-900">{{ currency() }} {{ number_format($money['owed'] / 100, 0) }}</p></div>
            <div class="card"><p class="text-xs text-neutral-500">Overdue invoices</p><p @class(['mt-1 font-mono text-2xl font-bold', 'text-critical-700' => $money['overdue'] > 0, 'text-neutral-900' => $money['overdue'] === 0])>{{ $money['overdue'] }}</p></div>
        </div>
    @endif

    @error('quick') <div class="card mb-4 text-sm text-critical-700">{{ $message }}</div> @enderror

    @if ($user->hasRole('Finance'))
        <div class="mb-6">
            <h2 class="mb-1 text-sm font-semibold text-neutral-900">Completed jobs to invoice</h2>
            <p class="mb-3 text-xs text-neutral-500">Review and create opens the quotation and the customer's LPO side by side with the invoice. Create now skips the review and sends it from the agreed prices.</p>
            <div class="space-y-3">
                @forelse ($readyToInvoice as $job)
                    @php $direct = \App\Services\InvoiceBuilder::canRaiseDirectly($job); @endphp
                    <div class="card flex flex-wrap items-center justify-between gap-3" style="background-color: var(--color-info-50); border-color: var(--color-info-200)" wire:key="ready-{{ $job->id }}">
                        <div>
                            <p class="text-sm font-semibold text-neutral-900">{{ $job->reference }}</p>
                            <p class="text-sm text-neutral-600">{{ $job->customer->name }}@if ($job->sourceQuotation) &middot; {{ $job->sourceQuotation->reference }}@endif</p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <a href="/invoices/create/{{ $job->id }}" wire:navigate class="btn-primary">Review and create</a>
                            @if ($direct)
                                <button type="button" wire:click="quickInvoice({{ $job->id }})" wire:loading.attr="disabled" wire:target="quickInvoice({{ $job->id }})" class="btn-outline">Create now</button>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="card border-dashed text-center text-sm text-neutral-500">
                        No finished jobs are waiting. A job shows here once it is closed or its service report is released, until it has an invoice.
                    </div>
                @endforelse
            </div>
        </div>
    @endif

    <div class="mb-5 flex flex-wrap gap-1 border-b border-neutral-200">
        @foreach (['All', 'Unpaid', 'Overdue', 'Paid', 'Draft'] as $status)
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
