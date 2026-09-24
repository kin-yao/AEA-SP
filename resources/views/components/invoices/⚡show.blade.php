<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Models\Invoice;
use App\Models\Payment;

new #[Layout('layouts.app', ['title' => 'Invoice'])] class extends Component
{
    public Invoice $invoice;
    public string $paymentAmount = '';
    public string $paymentMethod = 'Bank transfer';

    public function mount(Invoice $invoice): void
    {
        $this->authorize('view', $invoice);
        $this->invoice = $invoice->load([
            'customer',
            'payments',
            'workOrder.sourceQuotation.lpoDetail',
            'workOrder.sourceRequest',
        ]);
    }

    public function issue(): void
    {
        $this->authorize('issue', $this->invoice);

        $this->invoice->update(['status' => 'Unpaid']);
        $this->invoice->refresh();
    }

    public function recordPayment(): void
    {
        $this->authorize('recordPayment', $this->invoice);

        $this->validate([
            'paymentAmount' => ['required', 'numeric', 'min:1'],
            'paymentMethod' => ['required', 'string'],
        ]);

        $amountMinor = (int) round(((float) $this->paymentAmount) * 100);

        try {
            Payment::recordAgainst(
                $this->invoice,
                'RCP-'.str_pad((string) (Payment::max('id') + 1), 4, '0', STR_PAD_LEFT),
                $amountMinor,
                $this->paymentMethod,
                auth()->id()
            );
        } catch (\DomainException $e) {
            $this->addError('paymentAmount', $e->getMessage());
            return;
        }

        $this->invoice->refresh();
        $this->invoice->load('payments');
        $this->paymentAmount = '';
    }
};
?>

<div>
    <a href="/invoices" wire:navigate class="mb-4 inline-flex items-center gap-1.5 text-sm text-gray-500 hover:text-gray-700">
        <x-icon name="arrow-right" class="h-3.5 w-3.5 rotate-180" />
        Back to invoices
    </a>

    <div class="mb-4 flex items-start justify-between">
        <div>
            <h1 class="text-xl font-semibold text-gray-900">{{ $invoice->reference }}</h1>
            <p class="text-sm text-gray-500">{{ $invoice->customer->name }}</p>
        </div>
        <span @class([
            'shrink-0 px-2.5 py-1 text-xs font-medium',
            'bg-gray-100 text-gray-600' => $invoice->status === 'Draft',
            'bg-info-50 text-info-700' => in_array($invoice->status, ['Unpaid', 'Part paid']),
            'bg-success-50 text-success-700' => $invoice->status === 'Paid',
            'bg-primary-50 text-primary-700' => $invoice->status === 'Overdue',
        ])>
            {{ $invoice->status }}
        </span>
    </div>

    @if ($invoice->workOrder)
        <div class="mb-4 border border-gray-200 bg-white p-5">
            <h2 class="mb-3 text-sm font-medium text-gray-900">Where this came from</h2>
            <div class="space-y-3 text-sm">
                <div class="flex items-center justify-between">
                    <span class="text-gray-500">Job</span>
                    <a href="/jobs/{{ $invoice->workOrder->id }}" wire:navigate class="font-medium text-info-700 hover:text-info-800">
                        {{ $invoice->workOrder->reference }}
                    </a>
                </div>

                @if ($invoice->workOrder->sourceRequest)
                    <div class="flex items-center justify-between">
                        <span class="text-gray-500">Request</span>
                        <a href="/requests/{{ $invoice->workOrder->sourceRequest->id }}" wire:navigate class="font-medium text-info-700 hover:text-info-800">
                            {{ $invoice->workOrder->sourceRequest->reference }}
                        </a>
                    </div>
                @endif

                @if ($invoice->workOrder->sourceQuotation)
                    <div class="flex items-center justify-between">
                        <span class="text-gray-500">Quotation</span>
                        <a href="/quotations/{{ $invoice->workOrder->sourceQuotation->id }}" wire:navigate class="font-medium text-info-700 hover:text-info-800">
                            {{ $invoice->workOrder->sourceQuotation->reference }}
                        </a>
                    </div>

                    @if ($invoice->workOrder->sourceQuotation->lpoDetail)
                        <div class="flex items-center justify-between">
                            <span class="flex items-center gap-1 text-gray-500"><x-icon name="cart-check" class="h-3 w-3" /> LPO</span>
                            <span class="font-medium text-gray-900">{{ $invoice->workOrder->sourceQuotation->lpo_reference }}</span>
                        </div>
                    @endif
                @endif

                @if (! $invoice->workOrder->sourceQuotation && ! $invoice->workOrder->sourceRequest)
                    <p class="text-xs text-gray-400">This job wasn't traced back to a request or quotation, likely created before that link existed.</p>
                @endif
            </div>
        </div>
    @endif

    <div class="mb-4 border border-gray-200 bg-white p-5">
        <dl class="grid grid-cols-2 gap-4 text-sm">
            <div>
                <dt class="text-gray-500">Amount</dt>
                <dd class="font-medium text-gray-900">KES {{ number_format($invoice->amount_minor / 100, 2) }}</dd>
            </div>
            <div>
                <dt class="text-gray-500">Paid</dt>
                <dd class="text-gray-900">KES {{ number_format($invoice->paid_minor / 100, 2) }}</dd>
            </div>
            <div>
                <dt class="text-gray-500">Balance</dt>
                <dd class="text-gray-900">KES {{ number_format($invoice->balanceMinor() / 100, 2) }}</dd>
            </div>
            <div>
                <dt class="text-gray-500">Due</dt>
                <dd class="text-gray-900">{{ $invoice->due_at->format('d M Y') }}</dd>
            </div>
        </dl>
    </div>

    @can('issue', $invoice)
        <button wire:click="issue" wire:loading.attr="disabled" wire:target="issue"
                class="mb-4 w-full bg-primary-500 py-2.5 text-sm font-medium text-white hover:bg-primary-600">
            Issue invoice
        </button>
    @endcan

    @if ($invoice->payments->isNotEmpty())
        <div class="mb-4 border border-gray-200 bg-white p-5">
            <h2 class="mb-3 text-sm font-medium text-gray-900">Payments</h2>
            <div class="space-y-2 text-sm">
                @foreach ($invoice->payments as $payment)
                    <div class="flex items-center justify-between border-b border-gray-100 pb-2 last:border-0 last:pb-0">
                        <span class="text-gray-900">{{ $payment->reference }} &middot; {{ $payment->method }}</span>
                        <span class="font-medium text-gray-900">KES {{ number_format($payment->amount_minor / 100, 2) }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    @can('recordPayment', $invoice)
        <div class="border border-gray-200 bg-white p-5">
            <h2 class="mb-3 text-sm font-medium text-gray-900">Record a payment</h2>
            <div class="mb-3">
                <label class="mb-1.5 block text-xs font-medium text-gray-700">Amount, KES</label>
                <input wire:model="paymentAmount" type="text" inputmode="decimal" placeholder="0.00"
                       class="w-full border border-gray-300 py-2 px-3 text-sm focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500">
                @error('paymentAmount') <p class="mt-1 text-xs text-primary-600">{{ $message }}</p> @enderror
            </div>
            <div class="mb-4">
                <label class="mb-1.5 block text-xs font-medium text-gray-700">Method</label>
                <select wire:model="paymentMethod" class="w-full border border-gray-300 py-2 px-3 text-sm focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500">
                    <option>Bank transfer</option>
                    <option>M-Pesa</option>
                    <option>Cheque</option>
                </select>
            </div>
            <button wire:click="recordPayment" wire:loading.attr="disabled" wire:target="recordPayment"
                    class="w-full bg-primary-500 py-2.5 text-sm font-medium text-white hover:bg-primary-600">
                Record payment
            </button>
        </div>
    @endcan
</div>
