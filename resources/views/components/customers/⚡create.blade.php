<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Models\Customer;
use App\Models\Branch;

new #[Layout('layouts.app', ['title' => 'New customer'])] class extends Component
{
    public string $name = '';
    public ?int $branch_id = null;
    public string $kra_pin = '';
    public string $po_box = '';
    public string $main_contact_name = '';
    public string $main_contact_email = '';
    public string $main_contact_phone = '';

    public function mount(): void
    {
        $this->authorize('create', Customer::class);
    }

    public function with(): array
    {
        return [
            'branches' => Branch::orderBy('name')->get(),
        ];
    }

    public function save(): void
    {
        $this->authorize('create', Customer::class);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'branch_id' => ['required', 'exists:branches,id'],
            'kra_pin' => ['nullable', 'string', 'max:255'],
            'po_box' => ['nullable', 'string', 'max:255'],
            'main_contact_name' => ['nullable', 'string', 'max:255'],
            'main_contact_email' => ['nullable', 'email', 'max:255', 'unique:customers,main_contact_email'],
            'main_contact_phone' => ['nullable', 'string', 'max:255'],
        ]);

        $reference = \App\Models\ReferenceSeries::next('customer');

        $customer = Customer::create([
            'reference' => $reference,
            'name' => $validated['name'],
            'branch_id' => $validated['branch_id'],
            'kra_pin' => $validated['kra_pin'] ?: null,
            'po_box' => $validated['po_box'] ?: null,
            'main_contact_name' => $validated['main_contact_name'] ?: null,
            'main_contact_email' => $validated['main_contact_email'] ?: null,
            'main_contact_phone' => $validated['main_contact_phone'] ?: null,
        ]);

        $this->redirect('/customers/'.$customer->id, navigate: true);
    }
};
?>

<div>
    <div class="mb-6 flex items-center gap-3">
        <a href="/customers" wire:navigate class="text-neutral-400 hover:text-neutral-600">
            <x-icon name="arrow-right" class="h-4 w-4 rotate-180" />
        </a>
        <h1 class="text-xl font-semibold text-neutral-900">New customer</h1>
    </div>

    <form wire:submit="save" class="card max-w-2xl space-y-4">
        <p class="text-xs text-neutral-500">
            Most customers sign themselves up through the registration page. Use this only for a company
            that needs a record before they have a portal login of their own, for example one onboarded
            by phone or in person.
        </p>

        <div class="grid grid-cols-2 gap-4">
            <div class="col-span-2">
                <label class="label">Company name</label>
                <input wire:model="name" type="text" class="input">
                @error('name') <p class="mt-1 text-xs text-critical-700">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="label">Branch</label>
                <select wire:model="branch_id" class="input">
                    <option value="">Select branch</option>
                    @foreach ($branches as $branch)
                        <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                    @endforeach
                </select>
                @error('branch_id') <p class="mt-1 text-xs text-critical-700">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="label">KRA PIN</label>
                <input wire:model="kra_pin" type="text" class="input">
                @error('kra_pin') <p class="mt-1 text-xs text-critical-700">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="label">P.O. Box</label>
                <input wire:model="po_box" type="text" class="input">
                @error('po_box') <p class="mt-1 text-xs text-critical-700">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="label">Main contact name</label>
                <input wire:model="main_contact_name" type="text" class="input">
                @error('main_contact_name') <p class="mt-1 text-xs text-critical-700">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="label">Main contact email</label>
                <input wire:model="main_contact_email" type="email" class="input">
                @error('main_contact_email') <p class="mt-1 text-xs text-critical-700">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="label">Main contact phone</label>
                <input wire:model="main_contact_phone" type="text" class="input">
                @error('main_contact_phone') <p class="mt-1 text-xs text-critical-700">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="flex justify-end gap-2 pt-2">
            <a href="/customers" wire:navigate class="btn-ghost">Cancel</a>
            <button type="submit" class="btn-primary">Create customer</button>
        </div>
    </form>
</div>
