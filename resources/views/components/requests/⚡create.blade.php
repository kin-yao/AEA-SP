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
    public string $newCustomerContactEmail = '';
    public string $newCustomerKraPin = '';
    public string $newCustomerPoBox = '';
    public string $newCustomerSiteName = 'Head office';
    public string $newCustomerSiteAddress = '';
    public string $ncLat = '';
    public string $ncLng = '';

    // A machine that is not on record yet, added while logging the request.
    public string $newEquipModel = '';
    public string $newEquipSerial = '';
    public string $newEquipCategory = '';

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

        // A new company is saved complete, so nobody has to chase the missing details later.
        $validated = $this->validate([
            'newCustomerName' => \App\Support\Rules::company(),
            'newCustomerBranchId' => ['required', 'exists:branches,id'],
            'newCustomerKraPin' => \App\Support\Rules::kraPin(true),
            'newCustomerPoBox' => \App\Support\Rules::text(60, true, 2),
            'newCustomerContactName' => \App\Support\Rules::person(true),
            'newCustomerContactEmail' => array_merge(\App\Support\Rules::email(true), ['unique:customers,main_contact_email']),
            'newCustomerContactPhone' => \App\Support\Rules::phone(true),
            'newCustomerSiteName' => ['required', 'string', 'min:2', 'max:100'],
            'newCustomerSiteAddress' => ['required', 'string', 'min:3', 'max:500'],
            'ncLat' => ['required', 'numeric', 'between:-90,90'],
            'ncLng' => ['required', 'numeric', 'between:-180,180'],
        ], [
            'ncLat.required' => 'Drop a pin on the map first.',
            'ncLng.required' => 'Drop a pin on the map first.',
            'newCustomerContactEmail.unique' => 'A customer with this email is already on record. Pick them from the list instead.',
        ], [
            'newCustomerName' => 'company name',
            'newCustomerBranchId' => 'branch',
            'newCustomerKraPin' => 'KRA PIN',
            'newCustomerPoBox' => 'P.O. Box',
            'newCustomerContactName' => 'contact name',
            'newCustomerContactEmail' => 'contact email',
            'newCustomerContactPhone' => 'contact phone',
            'newCustomerSiteName' => 'location name',
            'newCustomerSiteAddress' => 'address',
        ]);

        $customer = \Illuminate\Support\Facades\DB::transaction(function () use ($validated) {
            $customer = Customer::create([
                'reference' => \App\Models\ReferenceSeries::next('customer'),
                'name' => $validated['newCustomerName'],
                'branch_id' => $validated['newCustomerBranchId'],
                'kra_pin' => $validated['newCustomerKraPin'],
                'po_box' => $validated['newCustomerPoBox'],
                'main_contact_name' => $validated['newCustomerContactName'],
                'main_contact_email' => $validated['newCustomerContactEmail'],
                'main_contact_phone' => $validated['newCustomerContactPhone'],
            ]);

            $site = CustomerSite::create([
                'customer_id' => $customer->id,
                'name' => $validated['newCustomerSiteName'],
                'address' => $validated['newCustomerSiteAddress'],
                'lat' => $validated['ncLat'],
                'lng' => $validated['ncLng'],
            ]);

            $this->customer_site_id = (string) $site->id;

            return $customer;
        });

        $this->customer_id = (string) $customer->id;
        $this->contact_name = $this->contact_name !== '' ? $this->contact_name : $validated['newCustomerContactName'];
        $this->addingNewCustomer = false;
        $this->reset([
            'newCustomerName', 'newCustomerBranchId', 'newCustomerContactName', 'newCustomerContactPhone', 'newCustomerContactEmail',
            'newCustomerKraPin', 'newCustomerPoBox', 'newCustomerSiteAddress', 'ncLat', 'ncLng',
        ]);
        $this->newCustomerSiteName = 'Head office';
        $this->equipment_id = '';
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
            'siteName' => ['required', 'string', 'min:2', 'max:100'],
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
        $this->reset(['newEquipModel', 'newEquipSerial', 'newEquipCategory']);
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

        $isNewMachine = $this->equipment_id === '__new' && auth()->user()->can('create', Equipment::class);
        if ($this->equipment_id === '__new' && ! $isNewMachine) {
            $this->equipment_id = '';
        }

        $validated = $this->validate([
            'customer_id' => ['required', 'exists:customers,id'],
            'customer_site_id' => ['nullable', \Illuminate\Validation\Rule::exists('customer_sites', 'id')->where('customer_id', $this->customer_id)],
            'equipment_id' => $isNewMachine ? [] : ['nullable', \Illuminate\Validation\Rule::exists('equipment', 'id')->where('customer_id', $this->customer_id)],
            'newEquipModel' => $isNewMachine ? ['required', 'string', 'min:2', 'max:100'] : [],
            'newEquipSerial' => $isNewMachine ? ['required', 'string', 'min:2', 'max:100', 'regex:/^[A-Za-z0-9][A-Za-z0-9 .\/_-]*$/', \Illuminate\Validation\Rule::unique('equipment', 'serial_number')] : [],
            'newEquipCategory' => ['nullable', 'string', 'max:100'],
            'equipment_description' => ['nullable', 'string', 'max:255'],
            'contact_name' => \App\Support\Rules::person(false),
            'fault_description' => ['required', 'string', 'min:10', 'max:2000'],
            'cover' => ['required', 'in:Chargeable,Contract'],
            'priority' => ['required', 'in:Low,Medium,High'],
        ], [
            'newEquipSerial.regex' => 'Use letters, numbers, spaces and . / _ - only.',
            'newEquipSerial.unique' => 'A machine with this serial number is already registered. Pick it from the list.',
        ], [
            'newEquipModel' => 'model',
            'newEquipSerial' => 'serial number',
        ]);

        $reference = \App\Models\ReferenceSeries::next('request');

        $machine = null;
        if ($isNewMachine) {
            $machine = Equipment::create([
                'serial_number' => trim($this->newEquipSerial),
                'model' => trim($this->newEquipModel),
                'customer_id' => $validated['customer_id'],
                'customer_site_id' => $validated['customer_site_id'] ?: null,
                'category' => $this->newEquipCategory ?: null,
                'cover' => $validated['cover'] === 'Contract' ? 'Contract' : 'Chargeable',
            ]);
        }

        $request = ServiceRequest::create([
            ...\Illuminate\Support\Arr::except($validated, ['newEquipModel', 'newEquipSerial', 'newEquipCategory']),
            'reference' => $reference,
            'customer_site_id' => $validated['customer_site_id'] ?: null,
            'equipment_id' => $machine?->id ?? ($validated['equipment_id'] ?: null),
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
            <div class="flex flex-wrap gap-2">
                <a href="/requests/{{ $created->id }}" wire:navigate class="btn-primary flex-1 text-center">
                    {{ $created->cover === 'Chargeable' ? 'Next: prepare the quotation' : 'Next: assign a technician' }}
                </a>
                <a href="/requests/create" wire:navigate class="btn-outline flex-1 text-center">Log another</a>
            </div>
        </div>
    @else
        <form wire:submit="submit" class="space-y-4">
            @if ($addingNewCustomer)
                <div class="card" style="border-left: 4px solid var(--color-primary-500)">
                    <h2 class="mb-1 text-sm font-semibold text-neutral-900">New customer</h2>
                    <p class="mb-3 text-xs text-neutral-500">Fill in everything now, so nothing has to be chased later.</p>
                    <div class="mb-3 grid grid-cols-2 gap-4">
                        <div>
                            <label class="label">Company name</label>
                            <input wire:model="newCustomerName" type="text" class="input">
                            @error('newCustomerName') <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="label">Branch</label>
                            <select wire:model="newCustomerBranchId" class="input">
                                <option value="">Select a branch</option>
                                @foreach ($branches as $branch)
                                    <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                                @endforeach
                            </select>
                            @error('newCustomerBranchId') <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <div class="mb-3 grid grid-cols-2 gap-4">
                        <div>
                            <label class="label">KRA PIN</label>
                            <input wire:model="newCustomerKraPin" type="text" class="input" autocomplete="off">
                            @error('newCustomerKraPin') <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="label">P.O. Box</label>
                            <input wire:model="newCustomerPoBox" type="text" class="input" autocomplete="off">
                            @error('newCustomerPoBox') <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <div class="mb-3 grid grid-cols-2 gap-4">
                        <div>
                            <label class="label">Contact name</label>
                            <input wire:model="newCustomerContactName" type="text" class="input">
                            @error('newCustomerContactName') <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="label">Contact phone</label>
                            <input wire:model="newCustomerContactPhone" type="text" class="input" inputmode="tel">
                            @error('newCustomerContactPhone') <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <div class="mb-4">
                        <label class="label">Contact email</label>
                        <input wire:model="newCustomerContactEmail" type="email" class="input" autocomplete="off">
                        @error('newCustomerContactEmail') <p class="field-error">{{ $message }}</p> @enderror
                    </div>

                    <p class="mb-2 text-sm font-semibold text-neutral-900">Main location</p>
                    <div class="mb-3 grid grid-cols-2 gap-4">
                        <div>
                            <label class="label">Location name</label>
                            <input wire:model="newCustomerSiteName" type="text" class="input">
                            @error('newCustomerSiteName') <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="label">Address or landmark</label>
                            <input wire:model="newCustomerSiteAddress" type="text" class="input" placeholder="Street, building, nearby landmark">
                            @error('newCustomerSiteAddress') <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <div class="mb-4">
                        <label class="label">Pin on the map</label>
                        <x-location-picker lat="ncLat" lng="ncLng" />
                        @error('ncLat') <p class="field-error">{{ $message }}</p> @enderror
                    </div>

                    <button type="button" wire:click="createCustomer" wire:loading.attr="disabled" wire:target="createCustomer" class="btn-primary">
                        Save customer
                    </button>
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
                            @error('customer_id') <p class="field-error">{{ $message }}</p> @enderror
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
                        @if ($customer_id && ! $addingNewCustomer)
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
                            @error('siteName') <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="label">Address or landmark</label>
                            <input wire:model="siteAddress" type="text" class="input" placeholder="Gate, floor, nearby landmark">
                            @error('siteAddress') <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="label">Pin on the map</label>
                            <x-location-picker lat="siteLat" lng="siteLng" />
                            @error('siteLat') <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                        <button type="button" wire:click="saveSite" wire:loading.attr="disabled" wire:target="saveSite" class="btn-dark">Save location</button>
                    </div>
                @endif

                <div class="mb-3">
                    <label class="label">Machine</label>
                    <select wire:model.live="equipment_id" class="input">
                        <option value="">Not on record, I will describe it</option>
                        @foreach ($this->equipmentList as $equipment)
                            <option value="{{ $equipment->id }}">{{ $equipment->model }} &middot; {{ $equipment->serial_number }}</option>
                        @endforeach
                        @can('create', \App\Models\Equipment::class)
                            <option value="__new">+ Add a new machine</option>
                        @endcan
                    </select>
                    @error('equipment_id') <p class="field-error">{{ $message }}</p> @enderror
                </div>

                @if ($equipment_id === '__new')
                    <div class="mb-3 grid grid-cols-2 gap-4 rounded-[var(--radius-md)] border border-neutral-200 p-3">
                        <div>
                            <label class="label">Model</label>
                            <input wire:model="newEquipModel" type="text" class="input">
                            @error('newEquipModel') <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="label">Serial number</label>
                            <input wire:model="newEquipSerial" type="text" class="input" autocomplete="off">
                            @error('newEquipSerial') <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                        <div class="col-span-2">
                            <label class="label">Category (optional)</label>
                            <select wire:model="newEquipCategory" class="input">
                                <option value="">None</option>
                                @foreach (\App\Models\EquipmentCategory::orderBy('name')->pluck('name') as $cat)
                                    <option value="{{ $cat }}">{{ $cat }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                @elseif ($equipment_id === '')
                    <div class="mb-3">
                        <label class="label">Describe the machine</label>
                        <input wire:model="equipment_description" type="text" placeholder="Make, model, where it stands" class="input">
                        @error('equipment_description') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                @endif

                <div class="mb-3">
                    <label class="label">Contact name (optional)</label>
                    <input wire:model="contact_name" type="text" class="input">
                    @error('contact_name') <p class="field-error">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="label">Fault reported</label>
                    <textarea wire:model="fault_description" rows="3" class="input"></textarea>
                    @error('fault_description') <p class="field-error">{{ $message }}</p> @enderror
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
