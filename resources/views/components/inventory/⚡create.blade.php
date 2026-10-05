<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Models\Branch;
use App\Models\InventoryItem;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

new #[Layout('layouts.app', ['title' => 'New inventory item'])] class extends Component
{
    public string $code = '';
    public string $name = '';
    public string $category = 'Spare part';
    public string $manufacturer = '';
    public string $model = '';
    public string $unit = 'Piece';
    public bool $serial_tracked = false;
    public ?int $branch_id = null;
    public ?int $quantity = 0;
    public ?int $reorder_level = 0;
    public string $cost = '';
    public string $price = '';

    public function mount(): void
    {
        $this->authorize('create', InventoryItem::class);

        $this->branch_id = auth()->user()->branch_id;
    }

    public function with(): array
    {
        return [
            'branches' => Branch::orderBy('name')->get(),
            'categories' => ['Spare part', 'Equipment', 'Test equipment', 'Consumable'],
        ];
    }

    private function nextReference(): string
    {
        $n = (StockMovement::max('id') ?? 0) + 1;

        while (StockMovement::where('reference', 'MOV-'.str_pad((string) $n, 5, '0', STR_PAD_LEFT))->exists()) {
            $n++;
        }

        return 'MOV-'.str_pad((string) $n, 5, '0', STR_PAD_LEFT);
    }

    public function save(): void
    {
        $this->authorize('create', InventoryItem::class);

        $this->validate([
            'code' => ['required', 'string', 'max:255', Rule::unique('inventory_items', 'code')],
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', Rule::in(['Spare part', 'Equipment', 'Test equipment', 'Consumable'])],
            'manufacturer' => ['nullable', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'unit' => ['required', 'string', 'max:50'],
            'serial_tracked' => ['boolean'],
            'branch_id' => ['required', 'exists:branches,id'],
            'quantity' => ['nullable', 'integer', 'min:0'],
            'reorder_level' => ['nullable', 'integer', 'min:0'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'price' => ['nullable', 'numeric', 'min:0'],
        ]);

        $item = DB::transaction(function () {
            $item = InventoryItem::create([
                'code' => trim($this->code),
                'name' => trim($this->name),
                'category' => $this->category,
                'manufacturer' => $this->manufacturer !== '' ? trim($this->manufacturer) : null,
                'model' => $this->model !== '' ? trim($this->model) : null,
                'unit' => trim($this->unit),
                'serial_tracked' => $this->serial_tracked,
                'branch_id' => $this->branch_id,
                'quantity' => 0,
                'reorder_level' => $this->reorder_level ?? 0,
                'cost_minor' => $this->cost !== '' ? (int) round((float) $this->cost * 100) : null,
                'price_minor' => $this->price !== '' ? (int) round((float) $this->price * 100) : null,
            ]);

            // Opening stock goes through the movement trail so quantity and
            // history never drift apart.
            if (($this->quantity ?? 0) > 0) {
                StockMovement::recordAgainst($item, $this->nextReference(), 'Stock in', $this->quantity, auth()->id(), [
                    'to_location' => Branch::find($this->branch_id)?->name,
                ]);
            }

            return $item;
        });

        session()->flash('status', "{$item->name} added to inventory.");

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
        <h1 class="text-xl font-semibold text-neutral-900">New inventory item</h1>
        <p class="text-sm text-neutral-500">Add a part, machine or consumable to the stock list.</p>
    </div>

    <form wire:submit="save" class="card space-y-4">
        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="label">Item code</label>
                <input type="text" wire:model="code" placeholder="e.g. ITM-0142" class="input">
                @error('code') <p class="mt-1 text-xs text-critical-700">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="label">Category</label>
                <select wire:model="category" class="input">
                    @foreach ($categories as $c)
                        <option value="{{ $c }}">{{ $c }}</option>
                    @endforeach
                </select>
                @error('category') <p class="mt-1 text-xs text-critical-700">{{ $message }}</p> @enderror
            </div>
        </div>

        <div>
            <label class="label">Item name</label>
            <input type="text" wire:model="name" class="input">
            @error('name') <p class="mt-1 text-xs text-critical-700">{{ $message }}</p> @enderror
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="label">Manufacturer (optional)</label>
                <input type="text" wire:model="manufacturer" class="input">
                @error('manufacturer') <p class="mt-1 text-xs text-critical-700">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="label">Model (optional)</label>
                <input type="text" wire:model="model" class="input">
                @error('model') <p class="mt-1 text-xs text-critical-700">{{ $message }}</p> @enderror
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
                @error('unit') <p class="mt-1 text-xs text-critical-700">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="label">Store (branch)</label>
                <select wire:model="branch_id" class="input">
                    <option value="">Select a branch...</option>
                    @foreach ($branches as $branch)
                        <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                    @endforeach
                </select>
                @error('branch_id') <p class="mt-1 text-xs text-critical-700">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="label">Opening stock</label>
                <input type="number" min="0" wire:model="quantity" class="input">
                <p class="mt-1 text-xs text-neutral-400">Recorded as a Stock in movement.</p>
                @error('quantity') <p class="mt-1 text-xs text-critical-700">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="label">Reorder level</label>
                <input type="number" min="0" wire:model="reorder_level" class="input">
                <p class="mt-1 text-xs text-neutral-400">Flagged when stock is at or below this.</p>
                @error('reorder_level') <p class="mt-1 text-xs text-critical-700">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="label">Cost, KES (optional)</label>
                <input type="text" inputmode="decimal" wire:model="cost" placeholder="e.g. 1250.00" class="input">
                @error('cost') <p class="mt-1 text-xs text-critical-700">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="label">Selling price, KES (optional)</label>
                <input type="text" inputmode="decimal" wire:model="price" placeholder="e.g. 1800.00" class="input">
                @error('price') <p class="mt-1 text-xs text-critical-700">{{ $message }}</p> @enderror
            </div>
        </div>

        <label class="flex items-center gap-2 text-sm text-neutral-700">
            <input type="checkbox" wire:model="serial_tracked" class="rounded border-neutral-300">
            Track by serial number
        </label>

        <button type="submit" wire:loading.attr="disabled" wire:target="save" class="btn-primary w-full">
            Add item
        </button>
    </form>
</div>
