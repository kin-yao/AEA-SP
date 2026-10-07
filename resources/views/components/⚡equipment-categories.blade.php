<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use Illuminate\Validation\Rule;

new #[Layout('layouts.app', ['title' => 'Equipment categories'])] class extends Component
{
    public bool $showForm = false;
    public ?int $editingId = null;
    public string $name = '';

    public ?string $notice = null;
    public ?string $problem = null;

    public function mount(): void
    {
        abort_unless(auth()->user()->hasAnyRole(['ICT', 'Super Admin']), 403);
    }

    public function create(): void
    {
        $this->reset(['editingId', 'name', 'notice', 'problem']);
        $this->resetValidation();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $c = EquipmentCategory::findOrFail($id);
        $this->reset(['notice', 'problem']);
        $this->resetValidation();
        $this->editingId = $c->id;
        $this->name = $c->name;
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->name = trim($this->name);

        $this->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('equipment_categories', 'name')->ignore($this->editingId)],
        ], [
            'name.required' => 'Enter the category name.',
            'name.unique' => 'That category already exists.',
        ]);

        if ($this->editingId) {
            $c = EquipmentCategory::findOrFail($this->editingId);
            $old = $c->name;

            if ($old !== $this->name) {
                Equipment::where('category', $old)->update(['category' => $this->name]);
                $c->update(['name' => $this->name]);
            }
            $this->notice = 'Category updated. Machines using it were moved across.';
        } else {
            EquipmentCategory::create(['name' => $this->name]);
            $this->notice = 'Category added.';
        }

        $this->showForm = false;
    }

    public function delete(int $id): void
    {
        $c = EquipmentCategory::findOrFail($id);
        $this->reset(['notice', 'problem']);

        $n = $c->machineCount();
        if ($n > 0) {
            $this->problem = "{$c->name} is used by {$n} machines. Rename it, or change those machines first.";

            return;
        }

        $c->delete();
        $this->notice = "{$c->name} removed.";
    }

    public function close(): void
    {
        $this->showForm = false;
        $this->resetValidation();
    }

    public function with(): array
    {
        $counts = Equipment::selectRaw('category, count(*) as n')->groupBy('category')->pluck('n', 'category');
        $categories = EquipmentCategory::orderBy('name')->get();
        $listed = $categories->pluck('name')->all();

        // Machines whose category is typed in but not on the list (older records).
        $strays = $counts->except(array_merge($listed, ['']))->filter(fn ($n, $k) => $k !== null && $k !== '');

        return [
            'categories' => $categories,
            'counts' => $counts,
            'strays' => $strays,
            'none' => (int) ($counts[''] ?? 0) + (int) ($counts[null] ?? 0),
        ];
    }
};
?>

<div class="mx-auto" style="max-width: 56rem">
    <div class="mb-5 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-neutral-900">Equipment categories</h1>
        </div>
        <button type="button" wire:click="create" class="btn-primary">Add category</button>
    </div>

    @if ($notice)
        <div class="mb-4 rounded-lg border border-success-200 bg-success-50 px-4 py-3 text-sm text-success-700" role="status">{{ $notice }}</div>
    @endif
    @if ($problem)
        <div class="mb-4 rounded-lg border border-critical-200 bg-critical-50 px-4 py-3 text-sm text-critical-700" role="alert">{{ $problem }}</div>
    @endif

    @if ($showForm)
        <div style="position: fixed; inset: 0; z-index: 60; display: flex; align-items: center; justify-content: center; padding: 1rem; background: rgba(0, 0, 0, 0.45)" wire:click.self="close">
            <form wire:submit="save" class="card" style="width: 100%; max-width: 26rem">
                <h2 class="text-base font-semibold text-neutral-900">{{ $editingId ? 'Rename category' : 'Add a category' }}</h2>
                @if ($editingId)
                    <p class="mt-1 text-sm text-neutral-500">Machines already in this category move to the new name.</p>
                @endif
                <div class="mt-4">
                    <label class="label" for="ecn">Category name</label>
                    <input id="ecn" type="text" wire:model="name" class="input" style="font-size: 16px" placeholder="e.g. Weighbridge">
                    @error('name') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div class="mt-5 flex gap-2">
                    <button type="submit" class="btn-primary">Save</button>
                    <button type="button" wire:click="close" class="btn-outline">Cancel</button>
                </div>
            </form>
        </div>
    @endif

    <div class="card" style="padding: 0">
        <div class="overflow-x-auto">
            <table class="table-clean" style="min-width: 420px">
                <thead><tr><th>Category</th><th>Machines</th><th></th></tr></thead>
                <tbody>
                    @forelse ($categories as $c)
                        <tr wire:key="ec-{{ $c->id }}">
                            <td class="font-semibold text-neutral-900">{{ $c->name }}</td>
                            <td>{{ $counts[$c->name] ?? 0 }}</td>
                            <td class="whitespace-nowrap text-right">
                                <button type="button" wire:click="edit({{ $c->id }})" class="btn-outline" style="padding: 0.3rem 0.75rem">Rename</button>
                                <button type="button" wire:click="delete({{ $c->id }})" wire:confirm="Remove {{ $c->name }}?" class="btn-outline" style="padding: 0.3rem 0.75rem">Remove</button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="text-center text-neutral-500">No categories yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if ($strays->isNotEmpty() || $none > 0)
        <p class="mt-3 text-xs text-neutral-400">
            @if ($none > 0) {{ $none }} {{ $none === 1 ? 'machine has' : 'machines have' }} no category. @endif
            @foreach ($strays as $name => $n) "{{ $name }}" is typed on {{ $n }} {{ $n === 1 ? 'machine' : 'machines' }} but is not on the list. @endforeach
        </p>
    @endif
</div>