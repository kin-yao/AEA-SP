<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Models\WorkOrder;
use App\Models\Invoice;

new #[Layout('layouts.app', ['title' => 'New invoice'])] class extends Component
{
    public WorkOrder $job;
    public array $items = [];
    public string $vatRate = '';
    public string $dueAt = '';
    #[\Livewire\Attributes\Locked]
    public ?int $lpoDocumentId = null;   // set when the prices come from the customer's LPO
    public string $lpoReference = '';

    public function getCurrencyCodeProperty(): string
    {
        return $this->job->sourceQuotation?->lpoDetail?->currency_code
            ?? $this->job->sourceQuotation?->currency_code
            ?? $this->job->customer?->currencyCode()
            ?? currency();
    }

    public function mount(WorkOrder $job): void
    {
        $this->authorize('create', Invoice::class);

        if (\App\Services\InvoiceBuilder::blocker($job)) {
            abort(403, 'This job is not ready to invoice, or already has one.');
        }

        $this->job = $job;
        $this->dueAt = now()->addDays((int) setting('invoice_due_days'))->toDateString();
        $this->vatRate = rtrim(rtrim(number_format((float) ($job->customer?->vatPercent() ?? setting('vat_rate')), 3, '.', ''), '0'), '.');

        $lpo = $job->sourceQuotation?->lpoDetail;

        if ($lpo) {
            // The LPO is the binding agreement, so the invoice is raised from its prices.
            $this->fillFromLpo($lpo);
        } elseif ($job->sourceQuotation) {
            $job->sourceQuotation->load('items');

            $this->items = $job->sourceQuotation->items->map(fn ($item) => [
                'description' => $item->description,
                'quantity' => (string) $item->quantity,
                'rate' => number_format($item->rate_minor / 100, 2, '.', ''),
            ])->toArray();

            if ($job->sourceQuotation->labour_minor > 0) {
                $this->items[] = [
                    'description' => 'Labour',
                    'quantity' => '1',
                    'rate' => number_format($job->sourceQuotation->labour_minor / 100, 2, '.', ''),
                ];
            }

            $this->vatRate = number_format((float) $job->sourceQuotation->vat_rate * 100, 0, '.', '');
        }

        if (empty($this->items)) {
            $this->items = [['description' => '', 'quantity' => '1', 'rate' => '']];
        }
    }

    protected function fillFromLpo(\App\Models\LpoDetail $lpo): void
    {
        $lpo->ensureLines();
        $lpo->load('items');

        $this->items = $lpo->items->map(fn ($item) => [
            'description' => $item->description,
            'quantity' => rtrim(rtrim(number_format($item->quantity, 2, '.', ''), '0'), '.'),
            'rate' => number_format($item->rate_minor / 100, 2, '.', ''),
        ])->toArray();

        if ($lpo->labour_minor > 0) {
            $this->items[] = ['description' => 'Labour', 'quantity' => '1', 'rate' => number_format($lpo->labour_minor / 100, 2, '.', '')];
        }

        $this->vatRate = rtrim(rtrim(number_format((float) $lpo->vat_rate * 100, 3, '.', ''), '0'), '.');
        $this->lpoDocumentId = $lpo->document_id;
        $this->lpoReference = (string) $lpo->document?->reference;
    }

    /** The quotation and the customer's LPO for this job, to read beside the invoice form. */
    public function with(): array
    {
        $q = $this->job->sourceQuotation;
        $q?->load('items');
        $lpo = $q?->lpoDetail;
        $lpo?->load(['items', 'document']);

        return ['quote' => $q, 'lpo' => $lpo];
    }

    public function addItem(): void
    {
        $this->items[] = ['description' => '', 'quantity' => '1', 'rate' => ''];
    }

    public function removeItem(int $index): void
    {
        unset($this->items[$index]);
        $this->items = array_values($this->items);
    }

    public function getSubtotalMinorProperty(): int
    {
        $sum = collect($this->items)->sum(function ($item) {
            $quantity = (float) ($item['quantity'] ?: 0);
            $rateMinor = (int) round((float) ($item['rate'] ?: 0) * 100);

            return round($quantity * $rateMinor);
        });

        // Capped so a silly entry shows an error instead of overflowing.
        return (int) min($sum, 9.0e15);
    }

    public function getVatMinorProperty(): int
    {
        return (int) round($this->subtotalMinor * ((float) ($this->vatRate ?: 0) / 100));
    }

    public function getTotalMinorProperty(): int
    {
        return $this->subtotalMinor + $this->vatMinor;
    }

    /** $send true: issue it to the customer now. false: keep it as a draft to check first. */
    public function submit(bool $send = true): void
    {
        // Lines that came from an LPO cannot be altered here, whatever the browser sends back.
        // To change a price, change the LPO.
        if ($this->lpoDocumentId) {
            $lpo = $this->job->sourceQuotation?->lpoDetail;
            abort_unless($lpo && $lpo->document_id === $this->lpoDocumentId, 403);
            $this->fillFromLpo($lpo);
        }

        $this->validate([
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.description' => ['required', 'string', 'min:2', 'max:500'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.01', 'max:1000000', 'decimal:0,2'],
            'items.*.rate' => \App\Support\Rules::money(),
            'vatRate' => ['required', 'numeric', 'min:0', 'max:100'],
            'dueAt' => ['required', 'date', 'after_or_equal:today'],
        ]);

        if ($this->totalMinor > \App\Support\Rules::MAX_TOTAL_MINOR) {
            $this->addError('items', 'The invoice total is too large. Keep it under '.number_format(\App\Support\Rules::MAX_TOTAL_MINOR / 100, 2).' or split it into more than one invoice.');

            return;
        }

        $invoice = Invoice::create([
            'reference' => \App\Models\ReferenceSeries::next('invoice'),
            'customer_id' => $this->job->customer_id,
            'work_order_id' => $this->job->id,
            'issued_at' => now(),
            'due_at' => $this->dueAt,
            'amount_minor' => $this->totalMinor,
            'vat_rate' => ((float) $this->vatRate) / 100,
            'currency_code' => $this->currencyCode,
            'raised_by' => auth()->id(),
            'lpo_document_id' => $this->lpoDocumentId,
            'status' => $send ? 'Unpaid' : 'Draft',
        ]);

        foreach ($this->items as $item) {
            $invoice->items()->create([
                'description' => $item['description'],
                'quantity' => $item['quantity'],
                'rate_minor' => (int) round((float) $item['rate'] * 100),
            ]);
        }

        if ($send) {
            \App\Services\WorkflowNotifier::customer(
                $this->job->customer,
                'Invoice issued',
                ["Invoice {$invoice->reference} for {$invoice->currency_code} ".number_format($invoice->amount_minor / 100, 2).' has been issued.', 'Due date: '.$invoice->due_at->format('d M Y')],
            );
        }

        $this->redirect('/invoices/'.$invoice->id, navigate: true);
    }
};
?>

<div>
    <a href="/invoices" wire:navigate class="mb-4 inline-flex items-center gap-1.5 text-sm text-neutral-500 hover:text-neutral-900">
        <x-icon name="arrow-right" class="h-3.5 w-3.5 rotate-180" />
        Back to invoices
    </a>

    <h1 class="mb-1 text-xl font-semibold text-neutral-900">New invoice</h1>
    <p class="mb-6 text-sm text-neutral-500">{{ $job->reference }} &middot; {{ $job->customer->name }}</p>

    @if ($lpoDocumentId)
        <div class="card mb-4 text-xs text-info-800" style="background-color: var(--color-info-50); border-color: var(--color-info-200)">
            Raised from the customer's LPO {{ $lpoReference }}, which is the agreed price. To change a price, edit the LPO first.
            <a href="/documents/{{ $lpoDocumentId }}" wire:navigate class="font-semibold underline">Open the LPO</a>
        </div>
    @elseif ($job->sourceQuotation)
        <div class="card mb-4 text-xs text-info-800" style="background-color: var(--color-info-50); border-color: var(--color-info-200)">
            Pre-filled from {{ $job->sourceQuotation->reference }}. There is no LPO on file for it, so adjust anything before saving.
        </div>
    @endif

    @if ($quote || $lpo)
        @php
            $cur = $this->currencyCode;
            $fmt = fn ($minor) => number_format($minor / 100, 2);
        @endphp
        <div class="mb-4 grid gap-3" style="grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); align-items: start">
            {{-- Quotation --}}
            <div class="card">
                <div class="mb-2 flex items-center justify-between gap-2">
                    <h2 class="text-sm font-semibold text-neutral-900">Quotation</h2>
                    @if ($quote)
                        <a href="/quotations/{{ $quote->id }}" wire:navigate target="_blank" class="text-xs font-semibold text-info-700 hover:text-info-800">{{ $quote->reference }}</a>
                    @endif
                </div>
                @if ($quote)
                    <table class="table-clean w-full text-xs">
                        <thead><tr><th class="text-left">Item</th><th class="text-right">Qty</th><th class="text-right">Rate</th><th class="text-right">Amount</th></tr></thead>
                        <tbody>
                            @foreach ($quote->items as $it)
                                <tr><td>{{ $it->description }}</td><td class="text-right">{{ rtrim(rtrim((string) $it->quantity, '0'), '.') }}</td><td class="text-right">{{ $fmt($it->rate_minor) }}</td><td class="text-right">{{ $fmt($it->amountMinor()) }}</td></tr>
                            @endforeach
                            @if ($quote->labour_minor > 0)
                                <tr><td>Labour</td><td class="text-right">1</td><td class="text-right">{{ $fmt($quote->labour_minor) }}</td><td class="text-right">{{ $fmt($quote->labour_minor) }}</td></tr>
                            @endif
                        </tbody>
                    </table>
                    <div class="mt-2 space-y-0.5 text-xs">
                        <div class="flex justify-between"><span class="text-neutral-500">Subtotal</span><span>{{ $fmt($quote->subtotalMinor()) }}</span></div>
                        <div class="flex justify-between"><span class="text-neutral-500">VAT, {{ rtrim(rtrim(number_format($quote->vat_rate * 100, 2), '0'), '.') }}%</span><span>{{ $fmt($quote->vatMinor()) }}</span></div>
                        <div class="flex justify-between border-t border-neutral-100 pt-1 font-semibold"><span>Total</span><span>{{ $quote->currency_code }} {{ $fmt($quote->totalMinor()) }}</span></div>
                    </div>
                @else
                    <p class="text-sm text-neutral-500">This job did not come from a quotation.</p>
                @endif
            </div>

            {{-- LPO --}}
            <div class="card">
                <div class="mb-2 flex items-center justify-between gap-2">
                    <h2 class="text-sm font-semibold text-neutral-900">Customer LPO <span class="font-normal text-neutral-500">(the agreed price)</span></h2>
                    @if ($lpo?->document)
                        <a href="/documents/{{ $lpo->document->id }}" wire:navigate target="_blank" class="text-xs font-semibold text-info-700 hover:text-info-800">{{ $lpo->document->reference }}</a>
                    @endif
                </div>
                @if ($lpo)
                    <table class="table-clean w-full text-xs">
                        <thead><tr><th class="text-left">Item</th><th class="text-right">Qty</th><th class="text-right">Rate</th><th class="text-right">Amount</th></tr></thead>
                        <tbody>
                            @foreach ($lpo->items as $it)
                                <tr><td>{{ $it->description }}</td><td class="text-right">{{ rtrim(rtrim(number_format($it->quantity, 2, '.', ''), '0'), '.') }}</td><td class="text-right">{{ $fmt($it->rate_minor) }}</td><td class="text-right">{{ $fmt($it->amountMinor()) }}</td></tr>
                            @endforeach
                            @if ($lpo->labour_minor > 0)
                                <tr><td>Labour</td><td class="text-right">1</td><td class="text-right">{{ $fmt($lpo->labour_minor) }}</td><td class="text-right">{{ $fmt($lpo->labour_minor) }}</td></tr>
                            @endif
                        </tbody>
                    </table>
                    <div class="mt-2 space-y-0.5 text-xs">
                        <div class="flex justify-between"><span class="text-neutral-500">Subtotal</span><span>{{ $fmt($lpo->subtotalMinor()) }}</span></div>
                        <div class="flex justify-between"><span class="text-neutral-500">VAT, {{ rtrim(rtrim(number_format($lpo->vat_rate * 100, 2), '0'), '.') }}%</span><span>{{ $fmt($lpo->vatMinor()) }}</span></div>
                        <div class="flex justify-between border-t border-neutral-100 pt-1 font-semibold"><span>Total</span><span>{{ $lpo->currency_code ?: $cur }} {{ $fmt($lpo->totalMinor()) }}</span></div>
                    </div>
                    @if ($lpo->change_note)
                        <p class="mt-2 text-xs text-neutral-500">Changed from the quotation: {{ $lpo->change_note }}</p>
                    @endif
                    @if ($quote && $lpo->totalMinor() !== $quote->totalMinor())
                        <p class="mt-2 text-xs font-medium text-amber-700">The LPO total differs from the quotation by {{ $cur }} {{ $fmt(abs($lpo->totalMinor() - $quote->totalMinor())) }}. The invoice follows the LPO.</p>
                    @endif
                @else
                    <p class="text-sm text-neutral-500">No LPO has been logged for this job. The invoice is filled in from the quotation, so check it before saving.</p>
                @endif
            </div>
        </div>
    @endif

    <form wire:submit="submit(true)" class="card">
        <h2 class="mb-3 text-sm font-semibold text-neutral-900">What's being charged</h2>

        <div class="mb-3 space-y-2">
            @foreach ($items as $index => $item)
                <div class="flex gap-2">
                    <input wire:model="items.{{ $index }}.description" type="text" placeholder="Description" class="input flex-1" @readonly($lpoDocumentId)>
                    <input wire:model="items.{{ $index }}.quantity" type="text" inputmode="decimal" placeholder="Qty" class="input w-16" @readonly($lpoDocumentId)>
                    <input wire:model="items.{{ $index }}.rate" type="text" inputmode="decimal" placeholder="Rate" class="input w-24" @readonly($lpoDocumentId)>
                    @if (count($items) > 1 && ! $lpoDocumentId)
                        <button type="button" wire:click="removeItem({{ $index }})" class="px-2 text-neutral-400 hover:text-critical-700">
                            &times;
                        </button>
                    @endif
                </div>
                @foreach (['description', 'quantity', 'rate'] as $col)
                    @error("items.$index.$col") <p class="field-error" data-for="items.{{ $index }}.{{ $col }}" style="margin:-0.25rem 0 0.5rem">{{ $message }}</p> @enderror
                @endforeach
            @endforeach
        </div>
        @error('items') <p class="field-error">{{ $message }}</p> @enderror

        @unless ($lpoDocumentId)
            <button type="button" wire:click="addItem" class="mb-4 text-xs font-medium text-info-700 hover:text-info-800">
                + Add line
            </button>
        @endunless

        <div class="mb-4 flex items-center justify-end gap-2 border-t border-neutral-100 pt-4">
            <label class="text-sm text-neutral-600">VAT rate</label>
            <input wire:model.live="vatRate" type="text" inputmode="decimal" class="input w-16 text-right" @readonly($lpoDocumentId)>
            <span class="text-sm text-neutral-600">%</span>
        </div>
        @error('vatRate') <p class="field-error">{{ $message }}</p> @enderror

        <div class="mb-4 flex justify-end">
            <div class="w-56 text-sm">
                <div class="flex justify-between py-1">
                    <span class="text-neutral-500">Subtotal</span>
                    <span class="text-neutral-900">{{ number_format($this->subtotalMinor / 100, 2) }}</span>
                </div>
                <div class="flex justify-between py-1">
                    <span class="text-neutral-500">VAT, {{ $vatRate ?: 0 }}%</span>
                    <span class="text-neutral-900">{{ number_format($this->vatMinor / 100, 2) }}</span>
                </div>
                <div class="flex justify-between border-t border-neutral-100 py-2 font-semibold">
                    <span class="text-neutral-900">Total</span>
                    <span class="text-neutral-900">{{ $this->currencyCode }} {{ number_format($this->totalMinor / 100, 2) }}</span>
                </div>
            </div>
        </div>

        <div class="mb-4">
            <label class="label">Due date</label>
            <input wire:model="dueAt" type="date" class="input">
            @error('dueAt') <p class="field-error">{{ $message }}</p> @enderror
        </div>

        <button type="submit" wire:loading.attr="disabled" wire:target="submit" class="btn-primary w-full">
            Create invoice and send to customer
        </button>
        <button type="button" wire:click="submit(false)" wire:loading.attr="disabled" wire:target="submit" class="btn-outline mt-2 w-full">
            Save as draft to check first
        </button>
    </form>
</div>
