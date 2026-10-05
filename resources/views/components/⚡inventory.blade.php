<?php

use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\Attributes\Layout;
use App\Models\Branch;
use App\Models\InventoryItem;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

new #[Layout('layouts.app', ['title' => 'Inventory'])] class extends Component
{
    use WithFileUploads;

    public string $tab = 'items';
    public string $search = '';
    public string $categoryFilter = 'All';

    public bool $showImport = false;
    public $csv = null;
    public ?array $importResult = null;

    public ?int $moveItemId = null;
    public string $moveType = 'Issue';
    public ?int $moveQty = null;
    public ?string $notice = null;

    public function mount(): void
    {
        $this->authorize('viewAny', InventoryItem::class);
    }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, ['items', 'movements'], true) ? $tab : 'items';
        $this->notice = null;
    }

    public function filter(): void
    {
        // Livewire syncs $search and $categoryFilter on this round trip
        // regardless; this method just gives the "Filter" button something
        // to call.
    }

    public function with(): array
    {
        $categories = ['Spare part', 'Equipment', 'Test equipment', 'Consumable'];
        $user = auth()->user();

        $query = InventoryItem::with('branch');

        if ($this->search !== '') {
            $term = '%'.$this->search.'%';
            $query->where(function ($q) use ($term) {
                $q->where('code', 'like', $term)
                    ->orWhere('name', 'like', $term)
                    ->orWhere('model', 'like', $term)
                    ->orWhere('manufacturer', 'like', $term);
            });
        }

        if ($this->categoryFilter !== 'All') {
            $query->where('category', $this->categoryFilter);
        }

        $everything = InventoryItem::get(['id', 'quantity', 'reorder_level', 'cost_minor']);
        $canRecord = $user->hasAnyRole(['Service Admin', 'Technician']);

        return [
            'items' => $query->orderBy('code')->get(),
            'totalItems' => $everything->count(),
            'lowCount' => $everything->filter(fn ($i) => $i->isBelowReorderLevel())->count(),
            'stockValue' => $everything->sum(fn ($i) => $i->quantity * ($i->cost_minor ?? 0)) / 100,
            'categories' => $categories,
            'canManage' => $user->can('create', InventoryItem::class),
            'canRecord' => $canRecord,
            'isAdmin' => $user->hasRole('Service Admin'),
            'movements' => $this->tab === 'movements'
                ? StockMovement::with(['inventoryItem', 'recordedBy'])
                    ->orderByDesc('occurred_at')->orderByDesc('id')->limit(100)->get()
                : collect(),
            'pickList' => ($this->tab === 'movements' && $canRecord)
                ? InventoryItem::orderBy('code')->get(['id', 'code', 'name', 'quantity'])
                : collect(),
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

    public function recordMovement(): void
    {
        $this->notice = null;

        $this->validate([
            'moveItemId' => ['required', 'exists:inventory_items,id'],
            'moveType' => ['required', Rule::in(['Issue', 'Stock in', 'Adjustment'])],
            'moveQty' => ['required', 'integer', 'not_in:0'],
        ], [
            'moveItemId.required' => 'Choose an item.',
            'moveQty.required' => 'Enter a quantity.',
            'moveQty.not_in' => 'Quantity cannot be zero.',
        ]);

        $item = InventoryItem::with('branch')->findOrFail($this->moveItemId);
        $this->authorize('recordMovement', $item);

        $user = auth()->user();

        // A technician's one job here is issuing parts they used. Stock in
        // and adjustments stay with the Service Admin.
        if (! $user->hasRole('Service Admin') && $this->moveType !== 'Issue') {
            $this->addError('moveType', 'You can only record issues.');

            return;
        }

        if ($this->moveType !== 'Adjustment' && $this->moveQty < 0) {
            $this->addError('moveQty', 'Enter a positive quantity. The type decides the direction.');

            return;
        }

        $delta = match ($this->moveType) {
            'Issue' => -abs($this->moveQty),
            'Stock in' => abs($this->moveQty),
            default => $this->moveQty,
        };

        try {
            StockMovement::recordAgainst($item, $this->nextReference(), $this->moveType, $delta, $user->id, [
                'from_location' => $delta < 0 ? $item->branch?->name : null,
                'to_location' => $delta > 0 ? $item->branch?->name : null,
            ]);
        } catch (\DomainException $e) {
            $this->addError('moveQty', $e->getMessage());

            return;
        }

        $this->notice = "{$this->moveType} recorded for {$item->name} (".($delta > 0 ? '+' : '').$delta.').';
        $this->moveQty = null;
    }

    public function template()
    {
        $this->authorize('create', InventoryItem::class);

        $rows = [
            ['code', 'name', 'category', 'manufacturer', 'model', 'unit', 'serial_tracked', 'quantity', 'reorder_level', 'branch', 'cost', 'price'],
            ['ITM-0001', 'Load cell 50kg', 'Spare part', 'HBM', 'Z6FC3', 'Piece', 'no', '10', '3', 'Nairobi', '1250.00', '1800.00'],
        ];

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            foreach ($rows as $row) {
                fputcsv($out, $row, ',', '"', '\\');
            }
            fclose($out);
        }, 'inventory-template.csv', ['Content-Type' => 'text/csv']);
    }

    public function import(): void
    {
        $this->authorize('create', InventoryItem::class);
        $this->importResult = null;

        $this->validate([
            'csv' => ['required', 'file', 'mimes:csv,txt', 'max:2048'],
        ], [
            'csv.required' => 'Choose a CSV file first.',
            'csv.mimes' => 'The file must be a .csv file.',
        ]);

        $handle = fopen($this->csv->getRealPath(), 'r');
        $header = $handle ? fgetcsv($handle, 0, ',', '"', '\\') : false;

        if (! $header) {
            $this->addError('csv', 'The file looks empty.');

            return;
        }

        $header = array_map(
            fn ($h) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))),
            $header
        );

        foreach (['code', 'name', 'category'] as $required) {
            if (! in_array($required, $header, true)) {
                fclose($handle);
                $this->addError('csv', "Missing required column: {$required}.");

                return;
            }
        }

        $categories = ['Spare part', 'Equipment', 'Test equipment', 'Consumable'];
        $branches = Branch::all()->keyBy(fn ($b) => strtolower($b->name));
        $defaultBranchId = auth()->user()->branch_id ?? $branches->first()?->id;
        $userId = auth()->id();

        $money = function (string $v) {
            if ($v === '') {
                return null;
            }
            $n = str_replace([',', ' '], '', $v);

            return (is_numeric($n) && (float) $n >= 0) ? (int) round((float) $n * 100) : false;
        };

        $whole = function (string $v) {
            if ($v === '') {
                return null;
            }

            return (ctype_digit($v)) ? (int) $v : false;
        };

        $created = 0;
        $updated = 0;
        $errors = [];
        $line = 1;

        DB::transaction(function () use ($handle, $header, $categories, $branches, $defaultBranchId, $userId, $money, $whole, &$created, &$updated, &$errors, &$line) {
            while (($cells = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
                $line++;

                if (count(array_filter($cells, fn ($c) => trim((string) $c) !== '')) === 0) {
                    continue;
                }

                $row = [];
                foreach ($header as $i => $col) {
                    $row[$col] = trim((string) ($cells[$i] ?? ''));
                }

                $code = $row['code'] ?? '';
                $name = $row['name'] ?? '';
                $categoryRaw = $row['category'] ?? '';

                if ($code === '' || $name === '' || $categoryRaw === '') {
                    $errors[] = "Row {$line}: code, name and category are required.";
                    continue;
                }

                $category = null;
                foreach ($categories as $c) {
                    if (strcasecmp($c, $categoryRaw) === 0) {
                        $category = $c;
                    }
                }
                if ($category === null) {
                    $errors[] = "Row {$line}: category \"{$categoryRaw}\" must be one of ".implode(', ', $categories).'.';
                    continue;
                }

                $branchName = $row['branch'] ?? '';
                if ($branchName !== '') {
                    $branchId = $branches->get(strtolower($branchName))?->id;
                    if (! $branchId) {
                        $errors[] = "Row {$line}: branch \"{$branchName}\" does not exist.";
                        continue;
                    }
                } else {
                    $branchId = $defaultBranchId;
                }

                $qty = $whole($row['quantity'] ?? '');
                $reorder = $whole($row['reorder_level'] ?? '');
                $cost = $money($row['cost'] ?? '');
                $price = $money($row['price'] ?? '');

                if ($qty === false || $reorder === false) {
                    $errors[] = "Row {$line}: quantity and reorder_level must be whole numbers.";
                    continue;
                }
                if ($cost === false || $price === false) {
                    $errors[] = "Row {$line}: cost and price must be amounts in KES, like 1250.00.";
                    continue;
                }

                // Blank cells leave an existing value alone, so a partial
                // file can't wipe data by accident.
                $attrs = array_filter([
                    'name' => $name,
                    'category' => $category,
                    'manufacturer' => $row['manufacturer'] ?? '',
                    'model' => $row['model'] ?? '',
                    'unit' => $row['unit'] ?? '',
                    'branch_id' => $branchId,
                    'reorder_level' => $reorder,
                    'cost_minor' => $cost,
                    'price_minor' => $price,
                ], fn ($v) => $v !== null && $v !== '');

                if (($row['serial_tracked'] ?? '') !== '') {
                    $attrs['serial_tracked'] = in_array(strtolower($row['serial_tracked']), ['yes', 'y', 'true', '1'], true);
                }

                $item = InventoryItem::where('code', $code)->first();

                if ($item) {
                    $item->update($attrs);
                    $updated++;

                    if ($qty !== null && $qty !== (int) $item->quantity) {
                        StockMovement::recordAgainst($item, $this->nextReference(), 'Adjustment', $qty - (int) $item->quantity, $userId);
                    }
                } else {
                    if (! $branchId) {
                        $errors[] = "Row {$line}: no branch given and none could be assumed.";
                        continue;
                    }

                    $item = InventoryItem::create($attrs + ['code' => $code, 'quantity' => 0]);
                    $created++;

                    if ($qty !== null && $qty > 0) {
                        StockMovement::recordAgainst($item, $this->nextReference(), 'Stock in', $qty, $userId);
                    }
                }
            }
        });

        fclose($handle);

        $this->importResult = [
            'created' => $created,
            'updated' => $updated,
            'errors' => array_slice($errors, 0, 20),
            'errorCount' => count($errors),
        ];
        $this->csv = null;
    }
};
?>

<div>
    <div class="mb-6 flex items-center gap-4">
        <h1 class="shrink-0 text-xl font-semibold text-neutral-900">{{ auth()->user()->hasRole('Technician') ? 'Parts' : 'Inventory' }}</h1>
        <div class="h-px flex-1 border-t border-dashed border-neutral-300"></div>
        @if ($canManage)
            <button type="button" wire:click="template" class="btn-outline shrink-0">CSV template</button>
            <button type="button" wire:click="$toggle('showImport')" class="btn-outline shrink-0">Import CSV</button>
            <a href="/inventory/create" wire:navigate class="btn-primary shrink-0">New item</a>
        @endif
    </div>

    <div class="mb-6 grid gap-4" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));">
        <div class="card flex items-center gap-4">
            <div class="icon-badge icon-badge-primary">
                <x-icon name="cart-check" class="h-5 w-5" />
            </div>
            <div>
                <p class="text-xs text-neutral-500">Item lines</p>
                <p class="text-2xl font-semibold text-neutral-900">{{ number_format($totalItems) }}</p>
            </div>
        </div>
        <div class="card flex items-center gap-4">
            <div class="icon-badge icon-badge-amber">
                <x-icon name="exclamation-circle" class="h-5 w-5" />
            </div>
            <div>
                <p class="text-xs text-neutral-500">At or below reorder</p>
                <p class="text-2xl font-semibold text-neutral-900">{{ number_format($lowCount) }}</p>
            </div>
        </div>
        <div class="card flex items-center gap-4">
            <div class="icon-badge icon-badge-success">
                <x-icon name="receipt" class="h-5 w-5" />
            </div>
            <div>
                <p class="text-xs text-neutral-500">Stock value (at cost)</p>
                <p class="text-2xl font-semibold text-neutral-900">KES {{ number_format($stockValue, 0) }}</p>
            </div>
        </div>
    </div>

    @if ($canManage && $showImport)
        <div class="card mb-6 space-y-3">
            <div>
                <p class="text-sm font-semibold text-neutral-900">Import items from CSV</p>
                <p class="mt-1 text-xs text-neutral-500">
                    Items are matched on <span class="font-mono">code</span>: new codes are created, existing ones are updated.
                    Blank cells keep the current value. Quantity changes are logged as stock movements. Cost and price are in KES.
                    Download the template for the exact columns.
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <input type="file" wire:model="csv" accept=".csv,.txt" class="input min-w-[260px] flex-1">
                <button type="button" wire:click="import" wire:loading.attr="disabled" wire:target="import,csv" class="btn-primary shrink-0">
                    <span wire:loading.remove wire:target="import">Import</span>
                    <span wire:loading wire:target="import">Importing...</span>
                </button>
            </div>
            @error('csv') <p class="text-xs text-critical-700">{{ $message }}</p> @enderror

            @if ($importResult)
                <div class="rounded-[var(--radius-md)] bg-neutral-50 p-3 text-sm">
                    <p class="text-neutral-900">
                        <span class="font-semibold">{{ $importResult['created'] }}</span> created,
                        <span class="font-semibold">{{ $importResult['updated'] }}</span> updated,
                        <span class="font-semibold">{{ $importResult['errorCount'] }}</span> skipped.
                    </p>
                    @if ($importResult['errors'])
                        <ul class="mt-2 list-disc space-y-0.5 pl-5 text-xs text-critical-700">
                            @foreach ($importResult['errors'] as $err)
                                <li>{{ $err }}</li>
                            @endforeach
                        </ul>
                        @if ($importResult['errorCount'] > count($importResult['errors']))
                            <p class="mt-1 text-xs text-neutral-500">Showing the first {{ count($importResult['errors']) }} problems.</p>
                        @endif
                    @endif
                </div>
            @endif
        </div>
    @endif

    <div class="mb-5 flex gap-1 border-b border-neutral-200">
        @foreach (['items' => 'Items', 'movements' => 'Movements'] as $key => $label)
            <button type="button" wire:click="setTab('{{ $key }}')"
                    class="-mb-px border-b-2 px-4 py-2.5 text-sm font-medium {{ $tab === $key ? 'border-primary-500 text-primary-700' : 'border-transparent text-neutral-500 hover:text-neutral-800' }}">
                {{ $label }}
            </button>
        @endforeach
    </div>

    @if ($tab === 'items')
        <div class="mb-4 flex flex-wrap items-center gap-3">
            <input wire:model="search" type="text" placeholder="Search code, item, model or manufacturer" class="input min-w-[260px] flex-1">
            <select wire:model="categoryFilter" class="input w-auto">
                <option value="All">All categories</option>
                @foreach ($categories as $c)
                    <option value="{{ $c }}">{{ $c }}</option>
                @endforeach
            </select>
            <button type="button" wire:click="filter" class="btn-primary shrink-0">Filter</button>
            <span class="shrink-0 text-sm text-neutral-400">{{ $items->count() }} of {{ $totalItems }} shown</span>
        </div>

        <div class="overflow-x-auto rounded-[var(--radius-md)] border border-neutral-200 bg-white shadow-[var(--shadow-card)]">
            <table class="table-clean">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Item</th>
                        <th>Category</th>
                        <th>Model</th>
                        <th>Unit</th>
                        <th>Serial</th>
                        <th class="text-right">In stock</th>
                        <th class="text-right">Reorder</th>
                        <th>Store</th>
                        <th class="text-right">Cost</th>
                        @if ($canManage)
                            <th></th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @forelse ($items as $item)
                        @php
                            $catClass = match ($item->category) {
                                'Spare part' => 'pill-info',
                                'Equipment' => 'pill-success',
                                'Consumable' => 'pill-amber',
                                default => 'pill-neutral',
                            };
                        @endphp
                        <tr>
                            <td class="font-mono text-xs text-neutral-500">{{ $item->code }}</td>
                            <td>
                                <p class="font-medium text-neutral-900">{{ $item->name }}</p>
                                @if ($item->manufacturer)
                                    <p class="text-xs text-neutral-500">{{ $item->manufacturer }}</p>
                                @endif
                            </td>
                            <td><span class="{{ $catClass }}">{{ $item->category }}</span></td>
                            <td class="text-neutral-600">{{ $item->model ?: '-' }}</td>
                            <td class="text-neutral-600">{{ $item->unit }}</td>
                            <td class="text-neutral-600">{{ $item->serial_tracked ? 'Yes' : '-' }}</td>
                            <td class="text-right">
                                @if ((int) $item->quantity === 0)
                                    <span class="pill-danger">0</span>
                                @elseif ($item->isBelowReorderLevel())
                                    <span class="pill-amber">{{ $item->quantity }}</span>
                                @else
                                    <span class="font-semibold text-neutral-900">{{ $item->quantity }}</span>
                                @endif
                            </td>
                            <td class="text-right text-neutral-500">{{ $item->reorder_level }}</td>
                            <td class="text-neutral-600">{{ $item->branch?->name ?? '-' }}</td>
                            <td class="whitespace-nowrap text-right text-neutral-800">
                                {{ $item->cost_minor !== null ? 'KES '.number_format($item->cost_minor / 100, 2) : '-' }}
                            </td>
                            @if ($canManage)
                                <td class="text-right">
                                    <a href="/inventory/{{ $item->id }}/edit" wire:navigate class="btn-outline">Edit</a>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $canManage ? 11 : 10 }}" class="py-8 text-center text-sm text-neutral-500">No items found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @else
        @if ($canRecord)
            <form wire:submit="recordMovement" class="card mb-5 space-y-3">
                <p class="text-sm font-semibold text-neutral-900">Record a movement</p>
                <div class="grid gap-3" style="grid-template-columns: minmax(0, 2fr) minmax(0, 1fr) minmax(0, 1fr);">
                    <div>
                        <label class="label">Item</label>
                        <select wire:model="moveItemId" class="input">
                            <option value="">Select an item...</option>
                            @foreach ($pickList as $p)
                                <option value="{{ $p->id }}">{{ $p->code }} - {{ $p->name }} ({{ $p->quantity }} in stock)</option>
                            @endforeach
                        </select>
                        @error('moveItemId') <p class="mt-1 text-xs text-critical-700">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label">Type</label>
                        <select wire:model="moveType" class="input">
                            <option value="Issue">Issue (parts used)</option>
                            @if ($isAdmin)
                                <option value="Stock in">Stock in</option>
                                <option value="Adjustment">Adjustment (+/-)</option>
                            @endif
                        </select>
                        @error('moveType') <p class="mt-1 text-xs text-critical-700">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label">Quantity</label>
                        <input type="number" wire:model="moveQty" class="input">
                        @error('moveQty') <p class="mt-1 text-xs text-critical-700">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <button type="submit" wire:loading.attr="disabled" wire:target="recordMovement" class="btn-primary">Record movement</button>
                    @if ($notice)
                        <span class="text-sm text-neutral-600">{{ $notice }}</span>
                    @endif
                </div>
            </form>
        @endif

        <div class="overflow-x-auto rounded-[var(--radius-md)] border border-neutral-200 bg-white shadow-[var(--shadow-card)]">
            <table class="table-clean">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Reference</th>
                        <th>Item</th>
                        <th>Type</th>
                        <th class="text-right">Qty</th>
                        <th>Location</th>
                        <th>Recorded by</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($movements as $m)
                        @php
                            $typeClass = match ($m->type) {
                                'Issue' => 'pill-amber',
                                'Stock in' => 'pill-success',
                                default => 'pill-info',
                            };
                        @endphp
                        <tr>
                            <td class="whitespace-nowrap text-neutral-600">{{ $m->occurred_at->format('d M Y, H:i') }}</td>
                            <td class="font-mono text-xs text-neutral-500">{{ $m->reference }}</td>
                            <td>
                                <p class="font-medium text-neutral-900">{{ $m->inventoryItem->name }}</p>
                                <p class="font-mono text-xs text-neutral-500">{{ $m->inventoryItem->code }}</p>
                            </td>
                            <td><span class="{{ $typeClass }}">{{ $m->type }}</span></td>
                            <td class="text-right font-semibold {{ $m->quantity_delta < 0 ? 'text-critical-700' : 'text-neutral-900' }}">
                                {{ $m->quantity_delta > 0 ? '+' : '' }}{{ $m->quantity_delta }}
                            </td>
                            <td class="text-neutral-600">{{ $m->to_location ?: ($m->from_location ?: '-') }}</td>
                            <td class="text-neutral-600">{{ $m->recordedBy?->name ?? '-' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="py-8 text-center text-sm text-neutral-500">No movements recorded yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <p class="mt-2 text-xs text-neutral-400">Showing the latest 100 movements.</p>
    @endif
</div>
