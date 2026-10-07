<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\WorkflowNotifier;
use Barryvdh\DomPDF\Facade\Pdf;

new #[Layout('layouts.app', ['title' => 'Invoice'])] class extends Component
{
    public Invoice $invoice;
    public string $paymentAmount = '';
    public string $paymentMethod = '';

    public function mount(Invoice $invoice): void
    {
        $this->authorize('view', $invoice);
        $this->paymentMethod = setting('payment_methods')[0] ?? '';
        $this->invoice = $invoice->load([
            'customer',
            'payments',
            'items',
            'workOrder.sourceQuotation.lpoDetail',
            'workOrder.sourceRequest',
        ]);
    }

    public function issue(): void
    {
        $this->authorize('issue', $this->invoice);

        $this->invoice->update(['status' => 'Unpaid']);
        $this->invoice->refresh();

        WorkflowNotifier::customer(
            $this->invoice->customer,
            'Invoice issued',
            [
                "Invoice {$this->invoice->reference} for ".$this->invoice->currency_code." ".number_format($this->invoice->amount_minor / 100, 2).' has been issued.',
                'Due date: '.$this->invoice->due_at->format('d M Y'),
            ],
        );
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
                \App\Models\ReferenceSeries::next('receipt'),
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

        WorkflowNotifier::customer(
            $this->invoice->customer,
            'Payment received',
            [
                "We've received your payment of ".$this->invoice->currency_code." ".number_format($amountMinor / 100, 2)." against invoice {$this->invoice->reference}.",
                "Status: {$this->invoice->status}",
            ],
        );

        $this->paymentAmount = '';
    }

    public function downloadPdf()
    {
        $this->authorize('view', $this->invoice);

        $pdf = Pdf::loadView('pdfs.invoice', [
            'invoice' => $this->invoice,
            'company' => \App\Support\Settings::company(),
            'banks' => \App\Models\BankAccount::forDocument($this->invoice->currency_code, $this->invoice->customer?->branch?->country_id),
        ]);

        return response()->streamDownload(
            fn () => print($pdf->output()),
            $this->invoice->reference.'.pdf'
        );
    }
};
?>

<div>
    <a href="/invoices" wire:navigate class="mb-4 inline-flex items-center gap-1.5 text-sm text-neutral-500 hover:text-neutral-900">
        <x-icon name="arrow-right" class="h-3.5 w-3.5 rotate-180" />
        Back to invoices
    </a>

    @php
        $isOverdue = $invoice->due_at->isPast() && ! in_array($invoice->status, ['Draft', 'Paid']);
    @endphp

    <div class="mb-4 flex items-start justify-between">
        <div>
            <h1 class="text-xl font-semibold text-neutral-900">{{ $invoice->reference }}</h1>
            <p class="text-sm text-neutral-500">{{ $invoice->customer->name }}</p>
        </div>
        <div class="flex shrink-0 items-center gap-2">
            @if ($isOverdue)
                <span class="pill-danger">Overdue</span>
            @endif
            <span @class([
                'pill-neutral' => $invoice->status === 'Draft',
                'pill-info' => in_array($invoice->status, ['Unpaid', 'Part paid']),
                'pill-success' => $invoice->status === 'Paid',
            ])>
                {{ $invoice->status }}
            </span>
        </div>
    </div>

    <button wire:click="downloadPdf" wire:loading.attr="disabled" wire:target="downloadPdf" class="btn-outline mb-4">
        <x-icon name="folder" class="h-3.5 w-3.5" />
        Download PDF
    </button>

    @if ($invoice->workOrder)
        <div class="card mb-4">
            <h2 class="mb-3 text-sm font-semibold text-neutral-900">Where this came from</h2>
            <div class="space-y-3 text-sm">
                <div class="flex items-center justify-between">
                    <span class="text-neutral-500">Job</span>
                    <a href="/jobs/{{ $invoice->workOrder->id }}" wire:navigate class="font-semibold text-info-700 hover:text-info-800">
                        {{ $invoice->workOrder->reference }}
                    </a>
                </div>

                @if ($invoice->workOrder->sourceRequest)
                    <div class="flex items-center justify-between">
                        <span class="text-neutral-500">Request</span>
                        <a href="/requests/{{ $invoice->workOrder->sourceRequest->id }}" wire:navigate class="font-semibold text-info-700 hover:text-info-800">
                            {{ $invoice->workOrder->sourceRequest->reference }}
                        </a>
                    </div>
                @endif

                @if ($invoice->workOrder->sourceQuotation)
                    <div class="flex items-center justify-between">
                        <span class="text-neutral-500">Quotation</span>
                        <a href="/quotations/{{ $invoice->workOrder->sourceQuotation->id }}" wire:navigate class="font-semibold text-info-700 hover:text-info-800">
                            {{ $invoice->workOrder->sourceQuotation->reference }}
                        </a>
                    </div>

                    @if ($invoice->workOrder->sourceQuotation->lpoDetail)
                        <div class="flex items-center justify-between">
                            <span class="flex items-center gap-1 text-neutral-500"><x-icon name="cart-check" class="h-3 w-3" /> LPO</span>
                            <span class="font-semibold text-neutral-900">{{ $invoice->workOrder->sourceQuotation->lpo_reference }}</span>
                        </div>
                    @endif
                @endif

                @if (! $invoice->workOrder->sourceQuotation && ! $invoice->workOrder->sourceRequest)
                    <p class="text-xs text-neutral-400">This job wasn't traced back to a request or quotation, likely created before that link existed.</p>
                @endif
            </div>
        </div>
    @endif

    <div class="card mb-4">
        <h2 class="mb-3 text-sm font-semibold text-neutral-900">What was charged</h2>
        @if ($invoice->items->isNotEmpty())
            <table class="table-clean">
                <thead>
                    <tr>
                        <th class="text-left">Item</th>
                        <th class="text-right">Qty</th>
                        <th class="text-right">Rate</th>
                        <th class="text-right">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($invoice->items as $item)
                        <tr>
                            <td class="text-neutral-900">{{ $item->description }}</td>
                            <td class="text-right text-neutral-600">{{ rtrim(rtrim($item->quantity, '0'), '.') }}</td>
                            <td class="text-right text-neutral-600">{{ number_format($item->rate_minor / 100, 2) }}</td>
                            <td class="text-right text-neutral-900">{{ number_format($item->amountMinor() / 100, 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="mt-3 flex justify-end">
                <div class="w-56 text-sm">
                    <div class="flex justify-between py-1">
                        <span class="text-neutral-500">Subtotal</span>
                        <span class="text-neutral-900">{{ number_format($invoice->itemsSubtotalMinor() / 100, 2) }}</span>
                    </div>
                    <div class="flex justify-between py-1">
                        <span class="text-neutral-500">VAT, {{ number_format($invoice->vat_rate * 100, 0) }}%</span>
                        <span class="text-neutral-900">{{ number_format($invoice->vatMinor() / 100, 2) }}</span>
                    </div>
                    <div class="flex justify-between border-t border-neutral-100 py-2 font-semibold">
                        <span class="text-neutral-900">Total</span>
                        <span class="text-neutral-900">{{ $invoice->currency_code }} {{ number_format($invoice->amount_minor / 100, 2) }}</span>
                    </div>
                </div>
            </div>
        @else
            <p class="text-sm text-neutral-500">No itemized lines on file for this invoice, likely created before this was tracked.</p>
        @endif
    </div>

    <div class="card mb-4">
        <dl class="grid grid-cols-2 gap-4 text-sm">
            <div>
                <dt class="text-neutral-500">Paid</dt>
                <dd class="text-neutral-900">{{ $invoice->currency_code }} {{ number_format($invoice->paid_minor / 100, 2) }}</dd>
            </div>
            <div>
                <dt class="text-neutral-500">Balance</dt>
                <dd class="text-neutral-900">{{ $invoice->currency_code }} {{ number_format($invoice->balanceMinor() / 100, 2) }}</dd>
            </div>
            <div>
                <dt class="text-neutral-500">Due</dt>
                <dd @class(['text-critical-700 font-medium' => $isOverdue, 'text-neutral-900' => ! $isOverdue])>
                    {{ $invoice->due_at->format('d M Y') }}
                </dd>
            </div>
            <div>
                <dt class="text-neutral-500">Customer KRA PIN</dt>
                <dd class="text-neutral-900">{{ $invoice->customer->kra_pin ?? '—' }}</dd>
            </div>
            <div class="col-span-2">
                <dt class="text-neutral-500">Payment terms</dt>
                <dd class="text-neutral-900">{{ setting('payment_terms') }}</dd>
            </div>
        </dl>
    </div>

    @can('issue', $invoice)
        <button wire:click="issue" wire:loading.attr="disabled" wire:target="issue" class="btn-primary mb-4 w-full">
            Issue invoice
        </button>
    @endcan

    @if ($invoice->payments->isNotEmpty())
        <div class="card mb-4">
            <h2 class="mb-3 text-sm font-semibold text-neutral-900">Payments</h2>
            <div class="divide-y divide-neutral-100 text-sm">
                @foreach ($invoice->payments as $payment)
                    <div class="flex items-center justify-between py-2 first:pt-0 last:pb-0">
                        <span class="text-neutral-900">{{ $payment->reference }} &middot; {{ $payment->method }}</span>
                        <span class="font-semibold text-neutral-900">{{ $invoice->currency_code }} {{ number_format($payment->amount_minor / 100, 2) }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    @can('recordPayment', $invoice)
        <div class="card">
            <h2 class="mb-3 text-sm font-semibold text-neutral-900">Record a payment</h2>
            <div class="mb-3">
                <label class="label">Amount, {{ $invoice->currency_code }}</label>
                <input wire:model="paymentAmount" type="text" inputmode="decimal" placeholder="0.00" class="input">
                @error('paymentAmount') <p class="mt-1 text-xs text-critical-700">{{ $message }}</p> @enderror
            </div>
            <div class="mb-4">
                <label class="label">Method</label>
                <select wire:model="paymentMethod" class="input">
                    @foreach (setting('payment_methods') as $method)
                        <option>{{ $method }}</option>
                    @endforeach
                </select>
            </div>
            <button wire:click="recordPayment" wire:loading.attr="disabled" wire:target="recordPayment" class="btn-primary w-full">
                Record payment
            </button>
        </div>
    @endcan
</div>
