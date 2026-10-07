<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Models\Branch;
use App\Models\InventoryItem;
use Illuminate\Validation\Rule;

new #[Layout('layouts.app', ['title' => 'Edit inventory item'])] class extends Component
{
    public InventoryItem $item;

    public string $code = '';
    public string $name = '';
    public string $category = 'Spare part';
    public string $manufacturer = '';
    public string $model = '';
    public string $unit = 'Piece';
    public bool $serial_tracked = false;
    public ?int $branch_id = null;
    public ?int $reorder_level = 0;
    public string $cost = '';
    public string $price = '';

    public function mount(InventoryItem $inventoryItem): void
    {
        $this->authorize('update', $inventoryItem);

        $this->item = $inventoryItem;
        $this->code = $inventoryItem->code;
        $this->name = $inventoryItem->name;
        $this->category = $inventoryItem->category;
        $this->manufacturer = (string) $inventoryItem->manufacturer;
        $this->model = (string) $inventoryItem->model;
        $this->unit = $inventoryItem->unit;
        $this->serial_tracked = (bool) $inventoryItem->serial_tracked;
        $this->branch_id = $inventoryItem->branch_id;
        $this->reorder_level = $inventoryItem->reorder_level;
        $this->cost = $inventoryItem->cost_minor !== null ? number_format($inventoryItem->cost_minor / 100, 2, '.', '') : '';
        $this->price = $inventoryItem->price_minor !== null ? number_format($inventoryItem->price_minor / 100, 2, '.', '') : '';
    }

    public function with(): array
    {
        return [
            'branches' => Branch::orderBy('name')->get(),
            'categories' => setting('stock_categories'),
        ];
    }

    public function save(): void
    {
        $this->authorize('update', $this->item);

        $this->validate([
            'code' => ['required', 'string', 'min:2', 'max:50', 'regex:/^[A-Za-z0-9][A-Za-z0-9 ._\/-]*$/', Rule::unique('inventory_items', 'code')->ignore($this->item->id)],
            'name' => ['required', 'string', 'min:2', 'max:150'],
            'category' => ['required', Rule::in(setting('stock_categories'))],
            'manufacturer' => ['nullable', 'string', 'max:100'],
            'model' => ['nullable', 'string', 'max:100'],
            'unit' => ['required', 'string', 'max:30'],
            'serial_tracked' => ['boolean'],
            'branch_id' => ['required', 'exists:branches,id'],
            'reorder_level' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'cost' => \App\Support\Rules::money(false),
            'price' => \App\Support\Rules::money(false),
        ]);

        // Stock quantity is deliberately not editable here. It only changes
        // through recorded movements, so the trail always explains it.
        $this->item->update([
            'code' => trim($this->code),
            'name' => trim($this->name),
            'category' => $this->category,
            'manufacturer' => $this->manufacturer !== '' ? trim($this->manufacturer) : null,
            'model' => $this->model !== '' ? trim($this->model) : null,
            'unit' => trim($this->unit),
            'serial_tracked' => $this->serial_tracked,
            'branch_id' => $this->branch_id,
            'reorder_level' => $this->reorder_level ?? 0,
            'cost_minor' => $this->cost !== '' ? (int) round((float) $this->cost * 100) : null,
            'price_minor' => $this->price !== '' ? (int) round((float) $this->price * 100) : null,
        ]);

        session()->flash('status', "{$this->item->name} updated.");

        $this->redirect('/inventory', navigate: true);
    }
};
?>

<div>
    <a href="/inventory" wire:navigate class="mb-4 inline-flex items-center gap-1.5 text-sm text-neutral-500 hover:text-neutral-900">
        <x-icon name="arrow-right" class="h-3.5 w-3.5 rotate-180" />
        Back to inventory
    </a>

    <div class="mb-6">
        <h1 class="text-xl font-semibold text-neutral-900">Edit inventory item</h1>
        <p class="text-sm text-neutral-500">{{ $item->code }} &middot; {{ $item->quantity }} {{ strtolower($item->unit) }}(s) in stock</p>
    </div>

    <form wire:submit="save" class="card space-y-4">
        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="label">Item code</label>
                <input type="text" wire:model="code" placeholder="e.g. ITM-0142" class="input">
                @error('code') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="label">Category</label>
                <select wire:model="category" class="input">
                    @foreach ($categories as $c)
                        <option value="{{ $c }}">{{ $c }}</option>
                    @endforeach
                </select>
                @error('category') <p class="field-error">{{ $message }}</p> @enderror
            </div>
        </div>

        <div>
            <label class="label">Item name</label>
            <input type="text" wire:model="name" class="input">
            @error('name') <p class="field-error">{{ $message }}</p> @enderror
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="label">Manufacturer (optional)</label>
                <input type="text" wire:model="manufacturer" class="input">
                @error('manufacturer') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="label">Model (optional)</label>
                <input type="text" wire:model="model" class="input">
                @error('model') <p class="field-error">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="label">Unit</label>
                <input type="text" wire:model="unit" list="unit-options" class="input">
                <datalist id="unit-options">
                    <option value="Piece"></option>
                    <option value="Set"></option>
                    <option value="Roll"></option>
                    <option value="Box"></option>
                    <option value="Litre"></option>
                </datalist>
                @error('unit') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="label">Store (branch)</label>
                <select wire:model="branch_id" class="input">
                    <option value="">Select a branch...</option>
                    @foreach ($branches as $branch)
                        <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                    @endforeach
                </select>
                @error('branch_id') <p class="field-error">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="label">Reorder level</label>
                <input type="number" min="0" wire:model="reorder_level" class="input">
                <p class="mt-1 text-xs text-neutral-400">Alert level.</p>
                @error('reorder_level') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="label">Current stock</label>
                <input type="text" value="{{ $item->quantity }}" disabled class="input">
                <p class="mt-1 text-xs text-neutral-400">Change stock under Movements.</p>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="label">Cost, {{ currency() }} (optional)</label>
                <input type="text" inputmode="decimal" wire:model="cost" placeholder="e.g. 1250.00" class="input">
                @error('cost') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="label">Selling price, {{ currency() }} (optional)</label>
                <input type="text" inputmode="decimal" wire:model="price" placeholder="e.g. 1800.00" class="input">
                @error('price') <p class="field-error">{{ $message }}</p> @enderror
            </div>
        </div>

        <label class="flex items-center gap-2 text-sm text-neutral-700">
            <input type="checkbox" wire:model="serial_tracked" class="rounded border-neutral-300">
            Track by serial number
        </label>

        <button type="submit" wire:loading.attr="disabled" wire:target="save" class="btn-primary w-full">
            Save changes
        </button>
    </form>
</div>
