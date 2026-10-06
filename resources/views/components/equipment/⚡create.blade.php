<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Models\Equipment;
use App\Models\Customer;
use App\Models\CustomerSite;
use Illuminate\Validation\Rule;

new #[Layout('layouts.app', ['title' => 'Register machine'])] class extends Component
{
    public string $serial_number = '';
    public string $model = '';
    public ?int $customer_id = null;
    public ?int $customer_site_id = null;
    public string $category = '';
    public string $cover = 'Chargeable';
    public ?string $installed_at = null;
    public ?string $warranty_expires_at = null;
    public ?string $next_visit_due_at = null;

    public function mount(): void
    {
        $this->authorize('create', Equipment::class);
    }

    public function updatedCustomerId(): void
    {
        $this->customer_site_id = null;
    }

    public function with(): array
    {
        return [
            'customers' => Customer::orderBy('name')->get(),
            'sites' => $this->customer_id
                ? CustomerSite::where('customer_id', $this->customer_id)->orderBy('name')->get()
                : collect(),
        ];
    }

    public function save(): void
    {
        $this->authorize('create', Equipment::class);

        $validated = $this->validate([
            'serial_number' => ['required', 'string', 'max:255', Rule::unique('equipment', 'serial_number')],
            'model' => ['required', 'string', 'max:255'],
            'customer_id' => ['required', 'exists:customers,id'],
            'customer_site_id' => ['nullable', 'exists:customer_sites,id'],
            'category' => ['nullable', 'string', 'max:255'],
            'cover' => ['required', Rule::in(['Chargeable', 'Warranty', 'Contract'])],
            'installed_at' => ['nullable', 'date'],
            'warranty_expires_at' => ['nullable', 'date'],
            'next_visit_due_at' => ['nullable', 'date'],
        ]);

        $equipment = Equipment::create($validated);

        session()->flash('status', 'Machine registered.');

        $this->redirect('/equipment/'.$equipment->id, navigate: true);
    }
};
?>

<div>
    <a href="/equipment" wire:navigate class="mb-4 inline-flex items-center gap-1.5 text-sm text-neutral-500 hover:text-neutral-900">
        <x-icon name="arrow-right" class="h-3.5 w-3.5 rotate-180" />
        Back to equipment
    </a>

    <div class="mb-6">
        <h1 class="text-xl font-semibold text-neutral-900">Register machine</h1>
        <p class="text-sm text-neutral-500">Add a customer's weighing equipment to the register.</p>
    </div>

    <form wire:submit="save" class="card space-y-4">
        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="label">Serial number</label>
                <input type="text" wire:model="serial_number" class="input">
                @error('serial_number') <p class="mt-1 text-xs text-critical-700">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="label">Model</label>
                <input type="text" wire:model="model" class="input">
                @error('model') <p class="mt-1 text-xs text-critical-700">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="label">Customer</label>
                <select wire:model.live="customer_id" class="input">
                    <option value="">Select a customer&hellip;</option>
                    @foreach ($customers as $customer)
                        <option value="{{ $customer->id }}">{{ $customer->name }}</option>
                    @endforeach
                </select>
                @error('customer_id') <p class="mt-1 text-xs text-critical-700">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="label">Site (optional)</label>
                <select wire:model="customer_site_id" class="input" @disabled(! $customer_id)>
                    <option value="">No specific site</option>
                    @foreach ($sites as $site)
                        <option value="{{ $site->id }}">{{ $site->name }}</option>
                    @endforeach
                </select>
                @error('customer_site_id') <p class="mt-1 text-xs text-critical-700">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="label">Category (optional)</label>
                <select wire:model="category" class="input">
                    <option value="">None</option>
                    @foreach (\App\Models\EquipmentCategory::orderBy('name')->pluck('name') as $cat)
                        <option value="{{ $cat }}">{{ $cat }}</option>
                    @endforeach
                    @if ($category !== '' && ! \App\Models\EquipmentCategory::where('name', $category)->exists())
                        <option value="{{ $category }}">{{ $category }}</option>
                    @endif
                </select>
                @error('category') <p class="mt-1 text-xs text-critical-700">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="label">Cover</label>
                <select wire:model="cover" class="input">
                    <option value="Chargeable">Chargeable</option>
                    <option value="Warranty">Warranty</option>
                    <option value="Contract">Contract</option>
                </select>
                @error('cover') <p class="mt-1 text-xs text-critical-700">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="grid grid-cols-3 gap-4">
            <div>
                <label class="label">Installed on (optional)</label>
                <input type="date" wire:model="installed_at" class="input">
                @error('installed_at') <p class="mt-1 text-xs text-critical-700">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="label">Warranty expires (optional)</label>
                <input type="date" wire:model="warranty_expires_at" class="input">
                @error('warranty_expires_at') <p class="mt-1 text-xs text-critical-700">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="label">Next visit due (optional)</label>
                <input type="date" wire:model="next_visit_due_at" class="input">
                @error('next_visit_due_at') <p class="mt-1 text-xs text-critical-700">{{ $message }}</p> @enderror
            </div>
        </div>

        <button type="submit" wire:loading.attr="disabled" wire:target="save" class="btn-primary w-full">
            Register machine
        </button>
    </form>
</div>
