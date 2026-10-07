<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Models\ServiceRequest;
use App\Models\Customer;
use App\Models\CustomerSite;
use App\Models\Equipment;
use App\Models\Branch;
use App\Services\WorkflowNotifier;

new #[Layout('layouts.app', ['title' => 'Log a request'])] class extends Component
{
    public string $customer_id = '';
    public string $customer_site_id = '';

    // A new location pinned on the map while logging the request.
    public bool $addingSite = false;
    public string $siteName = '';
    public string $siteAddress = '';
    public string $siteLat = '';
    public string $siteLng = '';
    public string $equipment_id = '';
    public string $equipment_description = '';
    public string $contact_name = '';
    public string $fault_description = '';
    public string $cover = 'Chargeable';
    public string $priority = 'Medium';

    public ?ServiceRequest $created = null;

    // Only used by Service Admin, for a brand-new company that isn't in
    // the system yet. A Customer-role user already has their own
    // customer_id, so this never applies to them.
    public bool $addingNewCustomer = false;
    public string $newCustomerName = '';
    public string $newCustomerBranchId = '';
    public string $newCustomerContactName = '';
    public string $newCustomerContactPhone = '';

    public function mount(): void
    {
        $this->authorize('create', ServiceRequest::class);

        if (auth()->user()->hasRole('Customer')) {
            $this->customer_id = (string) auth()->user()->customer_id;
            $this->contact_name = auth()->user()->name;

            $customer = Customer::find(auth()->user()->customer_id);
            $this->cover = $customer && $customer->has_active_contract ? 'Contract' : 'Chargeable';
        }
    }

    public function toggleNewCustomer(): void
    {
        abort_unless(auth()->user()->hasRole('Service Admin'), 403);

        $this->addingNewCustomer = ! $this->addingNewCustomer;
    }

    public function createCustomer(): void
    {
        abort_unless(auth()->user()->hasRole('Service Admin'), 403);

        $validated = $this->validate([
            'newCustomerName' => ['required', 'string', 'max:255'],
            'newCustomerBranchId' => ['required', 'exists:branches,id'],
            'newCustomerContactName' => ['nullable', 'string', 'max:255'],
            'newCustomerContactPhone' => ['nullable', 'string', 'max:255'],
        ]);

        $reference = \App\Models\ReferenceSeries::next('customer');

        $customer = Customer::create([
            'reference' => $reference,
            'name' => $validated['newCustomerName'],
            'branch_id' => $validated['newCustomerBranchId'],
            'main_contact_name' => $validated['newCustomerContactName'] ?: null,
            'main_contact_phone' => $validated['newCustomerContactPhone'] ?: null,
        ]);

        $this->customer_id = (string) $customer->id;
        $this->addingNewCustomer = false;
        $this->newCustomerName = '';
        $this->newCustomerBranchId = '';
        $this->newCustomerContactName = '';
        $this->newCustomerContactPhone = '';
    }

    public function toggleSite(): void
    {
        $this->addingSite = ! $this->addingSite;
        $this->reset(['siteName', 'siteAddress', 'siteLat', 'siteLng']);
        $this->resetValidation();
    }

    public function saveSite(): void
    {
        $this->authorize('create', ServiceRequest::class);

        if (auth()->user()->hasRole('Customer')) {
            $this->customer_id = (string) auth()->user()->customer_id;
        }

        $v = $this->validate([
            'customer_id' => ['required', 'exists:customers,id'],
            'siteName' => ['required', 'string', 'max:255'],
            'siteAddress' => ['nullable', 'string', 'max:500'],
            'siteLat' => ['required', 'numeric', 'between:-90,90'],
            'siteLng' => ['required', 'numeric', 'between:-180,180'],
        ], [
            'siteLat.required' => 'Drop a pin on the map first.',
            'siteLng.required' => 'Drop a pin on the map first.',
        ], ['siteName' => 'location name']);

        $site = CustomerSite::create([
            'customer_id' => $v['customer_id'],
            'name' => $v['siteName'],
            'address' => $v['siteAddress'] ?: null,
            'lat' => $v['siteLat'],
            'lng' => $v['siteLng'],
        ]);

        $this->customer_site_id = (string) $site->id;
        $this->addingSite = false;
        $this->reset(['siteName', 'siteAddress', 'siteLat', 'siteLng']);
    }

    public function updatedCustomerId(): void
    {
        $this->customer_site_id = '';
        $this->addingSite = false;
        $this->equipment_id = '';
    }

    public function getSitesProperty()
    {
        return $this->customer_id
            ? CustomerSite::where('customer_id', $this->customer_id)->orderBy('name')->get()
            : collect();
    }

    public function getEquipmentListProperty()
    {
        return $this->customer_id
            ? Equipment::where('customer_id', $this->customer_id)->orderBy('model')->get()
            : collect();
    }

    public function with(): array
    {
        return [
            'customers' => Customer::orderBy('name')->get(),
            'branches' => Branch::orderBy('name')->get(),
        ];
    }

    public function submit(): void
    {
        $this->authorize('create', ServiceRequest::class);

        $validated = $this->validate([
            'customer_id' => ['required', 'exists:customers,id'],
            'customer_site_id' => ['nullable', 'exists:customer_sites,id'],
            'equipment_id' => ['nullable', 'exists:equipment,id'],
            'equipment_description' => ['nullable', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'fault_description' => ['required', 'string'],
            'cover' => ['required', 'in:Chargeable,Contract'],
            'priority' => ['required', 'in:Low,Medium,High'],
        ]);

        $reference = \App\Models\ReferenceSeries::next('request');

        $request = ServiceRequest::create([
            ...$validated,
            'reference' => $reference,
            'customer_site_id' => $validated['customer_site_id'] ?: null,
            'equipment_id' => $validated['equipment_id'] ?: null,
            'logged_by_id' => auth()->id(),
        ]);

        $request->load('customer');

        WorkflowNotifier::customer(
            $request->customer,
            'We\'ve received your service request',
            [
                "Your request {$request->reference} has been logged and will be assigned to a technician shortly.",
                "Fault reported: {$request->fault_description}",
            ],
        );

        WorkflowNotifier::role(
            'Supervisor',
            'New service request logged',
            [
                "{$request->reference} for {$request->customer->name} needs a technician assigned.",
                "Fault: {$request->fault_description}",
            ],
            url("/requests/{$request->id}"),
            'View request',
        );

        $this->created = $request;
    }
};
?>

<div>
    <a href="/requests" wire:navigate class="mb-4 inline-flex items-center gap-1.5 text-sm text-neutral-500 hover:text-neutral-900">
        <x-icon name="arrow-right" class="h-3.5 w-3.5 rotate-180" />
        Back to requests
    </a>

    <h1 class="mb-6 text-xl font-semibold text-neutral-900">Log a request</h1>

    @if ($created)
        <div class="card mb-4" style="background-color: var(--color-fresh-50); border-color: #bfe3c7">
            <p class="mb-3 text-sm text-fresh-700">
                Request <strong>{{ $created->reference }}</strong> logged for {{ $created->customer->name }}.
            </p>
            <div class="flex gap-2">
                <a href="/requests/{{ $created->id }}" wire:navigate class="btn-outline flex-1 text-center">View request</a>
                <a href="/requests/create" wire:navigate class="btn-primary flex-1 text-center">Log another</a>
            </div>
        </div>
    @else
        <form wire:submit="submit" class="space-y-4">
            @if ($addingNewCustomer)
                <div class="card" style="border-left: 4px solid var(--color-primary-500)">
                    <h2 class="mb-3 text-sm font-semibold text-neutral-900">New customer</h2>
                    <div class="mb-3 grid grid-cols-2 gap-4">
                        <div>
                            <label class="label">Company name</label>
                            <input wire:model="newCustomerName" type="text" class="input">
                            @error('newCustomerName') <p class="mt-1 text-xs text-critical-700">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="label">Branch</label>
                            <select wire:model="newCustomerBranchId" class="input">
                                <option value="">Select a branch</option>
                                @foreach ($branches as $branch)
                                    <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                                @endforeach
                            </select>
                            @error('newCustomerBranchId') <p class="mt-1 text-xs text-critical-700">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <div class="mb-3 grid grid-cols-2 gap-4">
                        <div>
                            <label class="label">Contact name (optional)</label>
                            <input wire:model="newCustomerContactName" type="text" class="input">
                        </div>
                        <div>
                            <label class="label">Contact phone (optional)</label>
                            <input wire:model="newCustomerContactPhone" type="text" class="input">
                        </div>
                    </div>
                    <button type="button" wire:click="createCustomer" class="btn-primary">
                        Create customer
                    </button>
                    <p class="mt-2 text-xs text-neutral-400">
                        This creates the company record. KRA PIN, contract, and billing details can be filled in later by Finance or a Manager.
                    </p>
                </div>
            @endif

            <div class="card">
                <div class="mb-3 grid grid-cols-2 gap-4">
                    <div>
                        <label class="label">Customer</label>
                        @if (auth()->user()->hasRole('Customer'))
                            <div class="input flex items-center bg-neutral-50 text-neutral-600">
                                {{ auth()->user()->customer->name }}
                            </div>
                        @else
                            <select wire:model.live="customer_id" class="input">
                                <option value="">Select a customer</option>
                                @foreach ($customers as $customer)
                                    <option value="{{ $customer->id }}">{{ $customer->name }}</option>
                                @endforeach
                            </select>
                            @error('customer_id') <p class="mt-1 text-xs text-critical-700">{{ $message }}</p> @enderror
                            <button type="button" wire:click="toggleNewCustomer" class="mt-1.5 text-xs font-semibold text-primary-600 hover:text-primary-700">
                                {{ $addingNewCustomer ? 'Cancel' : '+ Add a new customer' }}
                            </button>
                        @endif
                    </div>
                    <div>
                        <label class="label">Location (optional)</label>
                        <select wire:model="customer_site_id" class="input">
                            <option value="">No specific location</option>
                            @foreach ($this->sites as $site)
                                <option value="{{ $site->id }}">{{ $site->name }}{{ $site->hasCoordinates() ? '' : ' (no pin)' }}</option>
                            @endforeach
                        </select>
                        @if ($customer_id)
                            <button type="button" wire:click="toggleSite" class="mt-1.5 text-xs font-semibold text-primary-600 hover:text-primary-700">
                                {{ $addingSite ? 'Cancel' : '+ Pin a new location' }}
                            </button>
                        @endif
                    </div>
                </div>

                @if ($addingSite)
                    <div class="mb-3 space-y-3 rounded-[var(--radius-md)] border border-neutral-200 p-3">
                        <div>
                            <label class="label">Location name</label>
                            <input wire:model="siteName" type="text" class="input" placeholder="e.g. Head office, Mombasa depot">
                            @error('siteName') <p class="mt-1 text-xs text-critical-700">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="label">Address or landmark</label>
                            <input wire:model="siteAddress" type="text" class="input" placeholder="Gate, floor, nearby landmark">
                        </div>
                        <div>
                            <label class="label">Pin on the map</label>
                            <x-location-picker lat="siteLat" lng="siteLng" />
                            @error('siteLat') <p class="mt-1 text-xs text-critical-700">{{ $message }}</p> @enderror
                        </div>
                        <button type="button" wire:click="saveSite" wire:loading.attr="disabled" wire:target="saveSite" class="btn-dark">Save location</button>
                    </div>
                @endif

                <div class="mb-3 grid grid-cols-2 gap-4">
                    <div>
                        <label class="label">Equipment (optional)</label>
                        <select wire:model="equipment_id" class="input">
                            <option value="">Not on record</option>
                            @foreach ($this->equipmentList as $equipment)
                                <option value="{{ $equipment->id }}">{{ $equipment->model }} &middot; {{ $equipment->serial_number }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="label">Equipment description (optional)</label>
                        <input wire:model="equipment_description" type="text" placeholder="If not on record" class="input">
                    </div>
                </div>

                <div class="mb-3">
                    <label class="label">Contact name (optional)</label>
                    <input wire:model="contact_name" type="text" class="input">
                </div>

                <div>
                    <label class="label">Fault reported</label>
                    <textarea wire:model="fault_description" rows="3" class="input"></textarea>
                    @error('fault_description') <p class="mt-1 text-xs text-critical-700">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="card">
                <div class="grid grid-cols-2 gap-4">
                    @unless (auth()->user()->hasRole('Customer'))
                        <div>
                            <label class="label">Cover</label>
                            <select wire:model="cover" class="input">
                                <option>Chargeable</option>
                                <option>Contract</option>
                            </select>
                        </div>
                    @endunless
                    <div>
                        <label class="label">Priority</label>
                        <select wire:model="priority" class="input">
                            <option>Low</option>
                            <option>Medium</option>
                            <option>High</option>
                        </select>
                    </div>
                </div>
            </div>

            <button type="submit" wire:loading.attr="disabled" wire:target="submit" class="btn-primary w-full">
                Log request
            </button>
        </form>
    @endif
</div>
