<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Models\WorkOrder;
use App\Models\Invoice;

new #[Layout('layouts.app', ['title' => 'New invoice'])] class extends Component
{
    public WorkOrder $job;
    public array $items = [];
    public string $vatRate = '16';
    public string $dueAt = '';

    public function mount(WorkOrder $job): void
    {
        $this->authorize('create', Invoice::class);

        $hasReleasedReport = $job->documents()->where('type', 'rep')->where('status', 'Released')->exists();

        if (! $hasReleasedReport || $job->invoices()->exists()) {
            abort(403, 'This job is not ready to invoice, or already has one.');
        }

        $this->job = $job;
        $this->dueAt = now()->addDays(30)->toDateString();

        if ($job->sourceQuotation) {
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

            // Real default, the same rate the quotation was actually
            // priced at, still fully editable, never assumed unchanging.
            $this->vatRate = number_format((float) $job->sourceQuotation->vat_rate * 100, 0, '.', '');
        }

        if (empty($this->items)) {
            $this->items = [['description' => '', 'quantity' => '1', 'rate' => '']];
        }
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
        return collect($this->items)->sum(function ($item) {
            $quantity = (float) ($item['quantity'] ?: 0);
            $rateMinor = (int) round((float) ($item['rate'] ?: 0) * 100);

            return (int) round($quantity * $rateMinor);
        });
    }

    public function getVatMinorProperty(): int
    {
        return (int) round($this->subtotalMinor * ((float) ($this->vatRate ?: 0) / 100));
    }

    public function getTotalMinorProperty(): int
    {
        return $this->subtotalMinor + $this->vatMinor;
    }

    public function submit(): void
    {
        $this->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.description' => ['required', 'string'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.01'],
            'items.*.rate' => ['required', 'numeric', 'min:0'],
            'vatRate' => ['required', 'numeric', 'min:0', 'max:100'],
            'dueAt' => ['required', 'date'],
        ]);

        $invoice = Invoice::create([
            'reference' => 'INV-'.str_pad((string) (Invoice::max('id') + 1), 4, '0', STR_PAD_LEFT),
            'customer_id' => $this->job->customer_id,
            'work_order_id' => $this->job->id,
            'issued_at' => now(),
            'due_at' => $this->dueAt,
            'amount_minor' => $this->totalMinor,
            'vat_rate' => ((float) $this->vatRate) / 100,
            'raised_by' => auth()->id(),
        ]);

        foreach ($this->items as $item) {
            $invoice->items()->create([
                'description' => $item['description'],
                'quantity' => $item['quantity'],
                'rate_minor' => (int) round((float) $item['rate'] * 100),
            ]);
        }

        $this->redirect('/invoices/'.$invoice->id, navigate: true);
    }
};
?>

<div>
    <a href="/invoices" wire:navigate class="mb-4 inline-flex items-center gap-1.5 text-sm text-gray-500 hover:text-gray-700">
        <x-icon name="arrow-right" class="h-3.5 w-3.5 rotate-180" />
        Back to invoices
    </a>

    <h1 class="mb-1 text-xl font-semibold text-gray-900">New invoice</h1>
    <p class="mb-6 text-sm text-gray-500">{{ $job->reference }} &middot; {{ $job->customer->name }}</p>

    @if ($job->sourceQuotation)
        <div class="mb-4 border border-info-200 bg-info-50 p-3 text-xs text-info-800">
            Pre-filled from {{ $job->sourceQuotation->reference }}, the quotation the LPO approved. Adjust anything before saving.
        </div>
    @endif

    <form wire:submit="submit" class="border border-gray-200 bg-white p-5">
        <h2 class="mb-3 text-sm font-medium text-gray-900">What's being charged</h2>

        <div class="mb-3 space-y-2">
            @foreach ($items as $index => $item)
                <div class="flex gap-2">
                    <input wire:model="items.{{ $index }}.description" type="text" placeholder="Description"
                           class="flex-1 border border-gray-300 py-2 px-3 text-sm focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500">
                    <input wire:model="items.{{ $index }}.quantity" type="text" inputmode="decimal" placeholder="Qty"
                           class="w-16 border border-gray-300 py-2 px-2 text-sm focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500">
                    <input wire:model="items.{{ $index }}.rate" type="text" inputmode="decimal" placeholder="Rate"
                           class="w-24 border border-gray-300 py-2 px-2 text-sm focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500">
                    @if (count($items) > 1)
                        <button type="button" wire:click="removeItem({{ $index }})" class="px-2 text-gray-400 hover:text-primary-600">
                            &times;
                        </button>
                    @endif
                </div>
            @endforeach
        </div>
        @error('items') <p class="mb-3 text-xs text-primary-600">{{ $message }}</p> @enderror

        <button type="button" wire:click="addItem" class="mb-4 text-xs font-medium text-info-700 hover:text-info-800">
            + Add line
        </button>

        <div class="mb-4 flex items-center justify-end gap-2 border-t border-gray-100 pt-4">
            <label class="text-sm text-gray-600">VAT rate</label>
            <input wire:model.live="vatRate" type="text" inputmode="decimal"
                   class="w-16 border border-gray-300 py-1.5 px-2 text-sm text-right focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500">
            <span class="text-sm text-gray-600">%</span>
        </div>
        @error('vatRate') <p class="mb-3 text-right text-xs text-primary-600">{{ $message }}</p> @enderror

        <div class="mb-4 flex justify-end">
            <div class="w-56 text-sm">
                <div class="flex justify-between py-1">
                    <span class="text-gray-500">Subtotal</span>
                    <span class="text-gray-900">{{ number_format($this->subtotalMinor / 100, 2) }}</span>
                </div>
                <div class="flex justify-between py-1">
                    <span class="text-gray-500">VAT, {{ $vatRate ?: 0 }}%</span>
                    <span class="text-gray-900">{{ number_format($this->vatMinor / 100, 2) }}</span>
                </div>
                <div class="flex justify-between border-t border-gray-100 py-2 font-medium">
                    <span class="text-gray-900">Total</span>
                    <span class="text-gray-900">KES {{ number_format($this->totalMinor / 100, 2) }}</span>
                </div>
            </div>
        </div>

        <div class="mb-4">
            <label class="mb-1.5 block text-xs font-medium text-gray-700">Due date</label>
            <input wire:model="dueAt" type="date"
                   class="w-full border border-gray-300 py-2 px-3 text-sm focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500">
            @error('dueAt') <p class="mt-1 text-xs text-primary-600">{{ $message }}</p> @enderror
        </div>

        <button type="submit" wire:loading.attr="disabled" wire:target="submit"
                class="w-full bg-primary-500 py-2.5 text-sm font-medium text-white hover:bg-primary-600">
            Create invoice
        </button>
    </form>
</div>
