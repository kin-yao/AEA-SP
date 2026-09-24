<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Models\Quotation;
use App\Models\Customer;

new #[Layout('layouts.app', ['title' => 'New quotation'])] class extends Component
{
    public string $customerId = '';
    public string $siteId = '';
    public string $scope = '';
    public string $labour = '';
    public string $validityDays = '30';
    public array $items = [];

    public function mount(): void
    {
        $this->authorize('create', Quotation::class);
        $this->items = [['description' => '', 'quantity' => 1, 'rate' => '']];
    }

    public function getSitesProperty()
    {
        if (! $this->customerId) {
            return collect();
        }

        return Customer::find($this->customerId)?->sites ?? collect();
    }

    public function addItem(): void
    {
        $this->items[] = ['description' => '', 'quantity' => 1, 'rate' => ''];
    }

    public function removeItem(int $index): void
    {
        unset($this->items[$index]);
        $this->items = array_values($this->items);

        if (empty($this->items)) {
            $this->items = [['description' => '', 'quantity' => 1, 'rate' => '']];
        }
    }

    // Live totals as the form's being filled in, same VAT math the model
    // itself uses, computed here from raw input before anything's saved.
    public function getSubtotalProperty(): float
    {
        $itemsTotal = collect($this->items)->sum(fn ($item) => (float) ($item['quantity'] ?: 0) * (float) ($item['rate'] ?: 0));

        return $itemsTotal + (float) ($this->labour ?: 0);
    }

    public function getVatProperty(): float
    {
        return round($this->subtotal * 0.16, 2);
    }

    public function getTotalProperty(): float
    {
        return $this->subtotal + $this->vat;
    }

    public function submit(): void
    {
        $this->validate([
            'customerId' => ['required', 'exists:customers,id'],
            'scope' => ['required', 'string'],
            'labour' => ['nullable', 'numeric', 'min:0'],
            'validityDays' => ['required', 'integer', 'min:1'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.description' => ['required_with:items.*.rate', 'nullable', 'string'],
        ]);

        $quotation = Quotation::create([
            'reference' => 'QT-'.str_pad((string) (Quotation::max('id') + 1), 4, '0', STR_PAD_LEFT),
            'customer_id' => $this->customerId,
            'customer_site_id' => $this->siteId ?: null,
            'scope' => $this->scope,
            'labour_minor' => (int) round(((float) ($this->labour ?: 0)) * 100),
            'validity_days' => $this->validityDays,
            'created_by' => auth()->id(),
        ]);

        foreach ($this->items as $item) {
            if (blank($item['description'])) {
                continue;
            }

            $quotation->items()->create([
                'description' => $item['description'],
                'quantity' => (int) ($item['quantity'] ?: 1),
                'rate_minor' => (int) round(((float) ($item['rate'] ?: 0)) * 100),
            ]);
        }

        $quotation->load('items');
        $quotation->routeApproval();
        $quotation->save();

        $this->redirect('/quotations/'.$quotation->id, navigate: true);
    }
};
?>

<div>
    <a href="/quotations" wire:navigate class="mb-4 inline-flex items-center gap-1.5 text-sm text-gray-500 hover:text-gray-700">
        <x-icon name="arrow-right" class="h-3.5 w-3.5 rotate-180" />
        Back to quotations
    </a>

    <h1 class="mb-6 text-xl font-semibold text-gray-900">New quotation</h1>

    <form wire:submit="submit" class="space-y-4">
        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <div class="mb-3 grid grid-cols-2 gap-4">
                <div>
                    <label class="mb-1.5 block text-xs font-medium text-gray-700">Customer</label>
                    <select wire:model.live="customerId" class="w-full rounded-lg border border-gray-300 py-2 px-3 text-sm focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500">
                        <option value="">Select a customer</option>
                        @foreach (\App\Models\Customer::orderBy('name')->get() as $customer)
                            <option value="{{ $customer->id }}">{{ $customer->name }}</option>
                        @endforeach
                    </select>
                    @error('customerId') <p class="mt-1 text-xs text-primary-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1.5 block text-xs font-medium text-gray-700">Site</label>
                    <select wire:model="siteId" class="w-full rounded-lg border border-gray-300 py-2 px-3 text-sm focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500">
                        <option value="">No specific site</option>
                        @foreach ($this->sites as $site)
                            <option value="{{ $site->id }}">{{ $site->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div>
                <label class="mb-1.5 block text-xs font-medium text-gray-700">Scope of work</label>
                <input wire:model="scope" type="text" placeholder="e.g. Weighbridge load cell replacement"
                       class="w-full rounded-lg border border-gray-300 py-2 px-3 text-sm focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500">
                @error('scope') <p class="mt-1 text-xs text-primary-600">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="text-sm font-medium text-gray-900">Line items</h2>
                <button type="button" wire:click="addItem" class="text-xs font-medium text-primary-600 hover:text-primary-700">
                    Add item
                </button>
            </div>

            @foreach ($items as $index => $item)
                <div class="mb-2 flex items-center gap-2">
                    <input wire:model="items.{{ $index }}.description" type="text" placeholder="Description" class="flex-[2] rounded-lg border border-gray-300 py-2 px-3 text-sm focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500">
                    <input wire:model.live="items.{{ $index }}.quantity" type="number" min="1" placeholder="Qty" class="w-16 rounded-lg border border-gray-300 py-2 px-3 text-sm focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500">
                    <input wire:model.live="items.{{ $index }}.rate" type="text" inputmode="decimal" placeholder="Rate, KES" class="w-28 rounded-lg border border-gray-300 py-2 px-3 text-sm focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500">
                    @if (count($items) > 1)
                        <button type="button" wire:click="removeItem({{ $index }})" class="shrink-0 text-gray-400 hover:text-primary-600">
                            &times;
                        </button>
                    @endif
                </div>
            @endforeach

            <div class="mt-4 grid grid-cols-2 gap-4 border-t border-gray-100 pt-4">
                <div>
                    <label class="mb-1.5 block text-xs font-medium text-gray-700">Labour, KES</label>
                    <input wire:model.live="labour" type="text" inputmode="decimal" placeholder="0.00"
                           class="w-full rounded-lg border border-gray-300 py-2 px-3 text-sm focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500">
                </div>
                <div>
                    <label class="mb-1.5 block text-xs font-medium text-gray-700">Validity, days</label>
                    <input wire:model="validityDays" type="number" min="1"
                           class="w-full rounded-lg border border-gray-300 py-2 px-3 text-sm focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500">
                </div>
            </div>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <div class="flex justify-between py-1 text-sm">
                <span class="text-gray-500">Subtotal</span>
                <span class="text-gray-900">KES {{ number_format($this->subtotal, 2) }}</span>
            </div>
            <div class="flex justify-between py-1 text-sm">
                <span class="text-gray-500">VAT, 16%</span>
                <span class="text-gray-900">KES {{ number_format($this->vat, 2) }}</span>
            </div>
            <div class="mt-1 flex justify-between border-t border-gray-100 py-2 text-sm font-medium">
                <span class="text-gray-900">Total</span>
                <span class="text-gray-900">KES {{ number_format($this->total, 2) }}</span>
            </div>
            <p class="mt-2 text-xs text-gray-500">
                {{ $this->total >= 3000000 ? 'At or above KES 3,000,000, this will route to the Manager for approval.' : 'Under KES 3,000,000, this will route to the Supervisor for approval.' }}
            </p>
        </div>

        <button type="submit" wire:loading.attr="disabled" wire:target="submit"
                class="w-full rounded-lg bg-primary-500 py-2.5 text-sm font-medium text-white hover:bg-primary-600">
            Create quotation
        </button>
    </form>
</div>
