<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\WorkflowNotifier;
use App\Services\FinancePdf;

new #[Layout('layouts.app', ['title' => 'Invoice'])] class extends Component
{
    public Invoice $invoice;
    public string $paymentAmount = '';
    public string $paymentMethod = '';
    public ?int $justPaidId = null;      // the receipt just made, offered for download
    public string $shareLink = '';
    public ?string $notice = null;

    public function mount(Invoice $invoice): void
    {
        $this->authorize('view', $invoice);
        $this->paymentMethod = setting('payment_methods')[0] ?? '';
        $this->paymentAmount = $invoice->balanceMinor() > 0 ? number_format($invoice->balanceMinor() / 100, 2, '.', '') : '';
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
        $this->notice = 'Invoice issued and sent to the customer.';

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
            'paymentAmount' => ['required', 'numeric', 'min:0.01', 'max:999999999', 'decimal:0,2'],
            'paymentMethod' => ['required', \Illuminate\Validation\Rule::in(setting('payment_methods'))],
        ]);

        $amountMinor = (int) round(((float) $this->paymentAmount) * 100);

        try {
            $payment = Payment::recordAgainst(
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
        $this->justPaidId = $payment->id;
        $this->notice = null;

        WorkflowNotifier::customer(
            $this->invoice->customer,
            'Payment received',
            [
                "We've received your payment of ".$this->invoice->currency_code." ".number_format($amountMinor / 100, 2)." against invoice {$this->invoice->reference}.",
                "Status: {$this->invoice->status}",
            ],
        );

        $this->paymentAmount = $this->invoice->balanceMinor() > 0 ? number_format($this->invoice->balanceMinor() / 100, 2, '.', '') : '';
    }

    public function downloadPdf()
    {
        $this->authorize('view', $this->invoice);
        $bytes = FinancePdf::invoice($this->invoice);

        return response()->streamDownload(fn () => print($bytes), $this->invoice->reference.'.pdf');
    }

    public function downloadReceipt(int $paymentId)
    {
        $this->authorize('view', $this->invoice);
        $payment = $this->invoice->payments->firstWhere('id', $paymentId);
        abort_unless($payment, 404);
        $bytes = FinancePdf::receipt($payment);

        return response()->streamDownload(fn () => print($bytes), $payment->reference.'.pdf');
    }

    /** Make a link anyone can open for 14 days, to paste into WhatsApp or an email. */
    public function makeLink(?int $paymentId = null): void
    {
        $this->authorize('share', $this->invoice);

        if ($paymentId) {
            $payment = $this->invoice->payments->firstWhere('id', $paymentId);
            abort_unless($payment, 404);
            $this->shareLink = FinancePdf::receiptLink($payment);
        } else {
            abort_if($this->invoice->status === 'Draft', 403, 'Issue the invoice before sharing it.');
            $this->shareLink = FinancePdf::invoiceLink($this->invoice);
        }
    }

    /** Email the customer a link to the invoice, or to a receipt. */
    public function sendToCustomer(?int $paymentId = null): void
    {
        $this->authorize('share', $this->invoice);

        if ($paymentId) {
            $payment = $this->invoice->payments->firstWhere('id', $paymentId);
            abort_unless($payment, 404);
            WorkflowNotifier::customer($this->invoice->customer, 'Your payment receipt', ["Receipt {$payment->reference} for {$this->invoice->currency_code} ".number_format($payment->amount_minor / 100, 2)." is ready."], FinancePdf::receiptLink($payment), 'Open receipt');
            $this->notice = 'Receipt sent to the customer.';

            return;
        }

        abort_if($this->invoice->status === 'Draft', 403, 'Issue the invoice before sharing it.');
        WorkflowNotifier::customer($this->invoice->customer, 'Your invoice', ["Invoice {$this->invoice->reference} for {$this->invoice->currency_code} ".number_format($this->invoice->amount_minor / 100, 2).' is ready.', 'Due '.$this->invoice->due_at->format('d M Y')], FinancePdf::invoiceLink($this->invoice), 'Open invoice');
        $this->notice = 'Invoice sent to the customer.';
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

    @if ($invoice->workOrder)
        <x-journey :job="$invoice->workOrder" />
    @endif

    @if ($notice)
        <div class="card mb-4 text-sm" style="background-color: var(--color-fresh-50); border-color: #bfe3c7">{{ $notice }}</div>
    @endif

    @if ($justPaidId && ($jp = $invoice->payments->firstWhere('id', $justPaidId)))
        <div class="card mb-4" style="background-color: var(--color-fresh-50); border-color: #bfe3c7">
            <p class="text-sm font-semibold text-neutral-900">Payment recorded. Receipt {{ $jp->reference }} is ready.</p>
            <div class="mt-3 flex flex-wrap gap-2">
                <button wire:click="downloadReceipt({{ $jp->id }})" class="btn-primary">Download receipt</button>
                @can('share', $invoice)
                    <button wire:click="sendToCustomer({{ $jp->id }})" class="btn-outline">Send to customer</button>
                    <button wire:click="makeLink({{ $jp->id }})" class="btn-outline">Get share link</button>
                @endcan
            </div>
        </div>
    @endif

    <div class="mb-4 flex flex-wrap gap-2">
        <button wire:click="downloadPdf" wire:loading.attr="disabled" wire:target="downloadPdf" class="btn-outline">
            <x-icon name="folder" class="h-3.5 w-3.5" />
            Download PDF
        </button>
        @can('share', $invoice)
            <button wire:click="sendToCustomer" class="btn-outline">Send to customer</button>
            <button wire:click="makeLink" class="btn-outline">Get share link</button>
        @endcan
    </div>

    @if ($shareLink)
        <div class="card mb-4" x-data="{ copied: false }">
            <p class="mb-2 text-xs text-neutral-500">Anyone with this link can open the PDF for {{ \App\Services\FinancePdf::LINK_DAYS }} days. Paste it into WhatsApp or an email.</p>
            <input type="text" readonly value="{{ $shareLink }}" class="input" onclick="this.select()" style="font-size: 12px">
            <button type="button" class="btn-outline mt-2" x-on:click="navigator.clipboard.writeText('{{ $shareLink }}'); copied = true">
                <span x-show="! copied">Copy link</span><span x-show="copied" x-cloak>Copied</span>
            </button>
        </div>
    @endif

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
                            @if ($invoice->lpo_document_id && ! auth()->user()->hasRole('Customer'))
                                <a href="/documents/{{ $invoice->lpo_document_id }}" wire:navigate class="font-semibold text-info-700 hover:text-info-800">{{ $invoice->workOrder->sourceQuotation->lpo_reference }}</a>
                            @else
                                <span class="font-semibold text-neutral-900">{{ $invoice->workOrder->sourceQuotation->lpo_reference }}</span>
                            @endif
                        </div>
                    @endif
                @endif

                @if (! $invoice->workOrder->sourceQuotation && ! $invoice->workOrder->sourceRequest)
                    <p class="text-xs text-neutral-400">No linked request or quotation.</p>
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
            <p class="text-sm text-neutral-500">No itemized lines.</p>
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
            Issue invoice and send to customer
        </button>
    @endcan

    @if ($invoice->payments->isNotEmpty())
        <div class="card mb-4">
            <h2 class="mb-3 text-sm font-semibold text-neutral-900">Payments</h2>
            <div class="divide-y divide-neutral-100 text-sm">
                @foreach ($invoice->payments as $payment)
                    <div class="flex items-center justify-between py-2 first:pt-0 last:pb-0">
                        <span class="text-neutral-900">{{ $payment->reference }} &middot; {{ $payment->method }} &middot; {{ $payment->paid_at->format('d M Y') }}</span>
                        <span class="flex items-center gap-3">
                            <span class="font-semibold text-neutral-900">{{ $invoice->currency_code }} {{ number_format($payment->amount_minor / 100, 2) }}</span>
                            <button wire:click="downloadReceipt({{ $payment->id }})" class="text-xs font-semibold text-info-700 hover:text-info-800">Receipt PDF</button>
                        </span>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    @can('recordPayment', $invoice)
        <div class="card">
            <h2 class="mb-1 text-sm font-semibold text-neutral-900">Record a payment</h2>
            <p class="mb-3 text-xs text-neutral-500">The full balance is filled in. Change it if the customer paid part. A receipt is made for you.</p>
            <div class="mb-3">
                <label class="label">Amount, {{ $invoice->currency_code }}</label>
                <input wire:model="paymentAmount" type="text" inputmode="decimal" placeholder="0.00" class="input">
                @error('paymentAmount') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div class="mb-4">
                <label class="label">Method</label>
                <select wire:model="paymentMethod" class="input">
                    @foreach (setting('payment_methods') as $method)
                        <option>{{ $method }}</option>
                    @endforeach
                </select>
                @error('paymentMethod') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <button wire:click="recordPayment" wire:loading.attr="disabled" wire:target="recordPayment" class="btn-primary w-full">
                Record payment
            </button>
        </div>
    @endcan
</div>
