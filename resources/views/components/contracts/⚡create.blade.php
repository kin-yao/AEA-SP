<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use Livewire\WithFileUploads;
use App\Models\Contract;
use App\Models\Customer;

new #[Layout('layouts.app', ['title' => 'New contract'])] class extends Component
{
    use WithFileUploads;

    public string $customer_id = '';
    public string $type = 'Full service';
    public string $starts_at = '';
    public string $ends_at = '';
    public string $visits_included = '8';
    public string $value = '';
    public string $currency_code = '';
    public $scan = null;

    public ?Contract $created = null;

    /** Every country's currency, plus the default. */
    public function currencyChoices(): array
    {
        return \App\Models\Currency::codes();
    }

    public function updatedCustomerId($value): void
    {
        $this->currency_code = Customer::find($value)?->currencyCode() ?? currency();
    }

    public function mount(): void
    {
        $this->authorize('create', Contract::class);
        $this->currency_code = currency();

        $customer = request('customer');
        if ($customer) {
            $this->customer_id = (string) $customer;
            $this->currency_code = Customer::find($customer)?->currencyCode() ?? currency();
        }
    }

    public function with(): array
    {
        return [
            'customers' => Customer::orderBy('name')->get(),
        ];
    }

    public function submit(): void
    {
        $this->authorize('create', Contract::class);

        $validated = $this->validate([
            'customer_id' => ['required', 'exists:customers,id'],
            'type' => ['required', 'in:Full service,Call out,Maintenance only'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'visits_included' => ['required', 'integer', 'min:0'],
            'value' => ['nullable', 'numeric', 'min:0'],
            'currency_code' => ['required', \Illuminate\Validation\Rule::in($this->currencyChoices())],
            'scan' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'],
        ]);

        $reference = \App\Models\ReferenceSeries::next('contract');

        $path = $this->scan->store('contracts', 'public');

        $contract = Contract::create([
            'reference' => $reference,
            'customer_id' => $validated['customer_id'],
            'type' => $validated['type'],
            'status' => 'Active',
            'starts_at' => $validated['starts_at'],
            'ends_at' => $validated['ends_at'],
            'visits_included' => $validated['visits_included'],
            'value_minor' => $validated['value'] !== '' && $validated['value'] !== null
                ? (int) round((float) $validated['value'] * 100)
                : null,
            'currency_code' => $validated['currency_code'],
            'scan_file_path' => $path,
        ]);

        $this->created = $contract;
    }
};
?>

<div>
    <a href="/contracts" wire:navigate class="mb-4 inline-flex items-center gap-1.5 text-sm text-neutral-500 hover:text-neutral-900">
        <x-icon name="arrow-right" class="h-3.5 w-3.5 rotate-180" />
        Back to contracts
    </a>

    <h1 class="mb-6 text-xl font-semibold text-neutral-900">New contract</h1>

    @if ($created)
        <div class="card mb-4" style="background-color: var(--color-fresh-50); border-color: #bfe3c7">
            <p class="mb-3 text-sm text-fresh-700">
                Contract <strong>{{ $created->reference }}</strong> created for {{ $created->customer->name }}.
            </p>
            <div class="flex gap-2">
                <a href="/contracts/{{ $created->id }}" wire:navigate class="btn-outline flex-1 text-center">View contract</a>
                <a href="/contracts" wire:navigate class="btn-primary flex-1 text-center">Back to contracts</a>
            </div>
        </div>
    @else
        <form wire:submit="submit" class="space-y-4">
            <div class="card">
                <div class="mb-3">
                    <label class="label">Customer</label>
                    <select wire:model.live="customer_id" class="input">
                        <option value="">Select a customer</option>
                        @foreach ($customers as $customer)
                            <option value="{{ $customer->id }}">{{ $customer->name }}</option>
                        @endforeach
                    </select>
                    @error('customer_id') <p class="mt-1 text-xs text-critical-700">{{ $message }}</p> @enderror
                </div>

                <div class="mb-3">
                    <label class="label">Contract type</label>
                    <select wire:model="type" class="input">
                        <option>Full service</option>
                        <option>Call out</option>
                        <option>Maintenance only</option>
                    </select>
                </div>

                <div class="mb-3 grid grid-cols-2 gap-4">
                    <div>
                        <label class="label">Start date</label>
                        <input wire:model="starts_at" type="date" class="input">
                        @error('starts_at') <p class="mt-1 text-xs text-critical-700">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label">End date</label>
                        <input wire:model="ends_at" type="date" class="input">
                        @error('ends_at') <p class="mt-1 text-xs text-critical-700">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="label">Visits included</label>
                        <input wire:model="visits_included" type="number" min="0" class="input">
                        @error('visits_included') <p class="mt-1 text-xs text-critical-700">{{ $message }}</p> @enderror
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="label">Currency</label>
                            <select wire:model="currency_code" class="input">
                                @foreach ($this->currencyChoices() as $code)
                                    <option>{{ $code }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="label">Value (optional)</label>
                            <input wire:model="value" type="number" step="0.01" min="0" class="input">
                        </div>
                    </div>
                </div>
            </div>

            <div class="card">
                <label class="label">Signed contract (PDF or image)</label>
                <input wire:model="scan" type="file" accept=".pdf,.jpg,.jpeg,.png" class="input">
                @error('scan') <p class="mt-1 text-xs text-critical-700">{{ $message }}</p> @enderror
            </div>

            <button type="submit" wire:loading.attr="disabled" wire:target="submit,scan" class="btn-primary w-full">
                Create contract
            </button>
        </form>
    @endif
</div>
