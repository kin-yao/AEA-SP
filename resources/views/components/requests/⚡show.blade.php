<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Models\Contract;
use App\Models\WorkOrder;
use App\Services\WorkflowNotifier;

new #[Layout('layouts.app', ['title' => 'Request'])] class extends Component
{
    public ServiceRequest $request;
    public string $technicianId = '';
    public string $natureOfVisit = 'Service';
    public string $dueDate = '';
    public ?string $generatedReference = null;

    public function mount(ServiceRequest $request): void
    {
        $this->authorize('view', $request);
        $this->request = $request;
        $this->dueDate = now()->addDays((int) setting('job_due_days'))->toDateString();
        $this->natureOfVisit = setting('nature_of_visit')[0] ?? 'Service';
    }

    public function getTechniciansProperty()
    {
        return User::role('Technician')->orderBy('name')->get();
    }

    public function assign(): void
    {
        $this->authorize('assign', $this->request);

        $this->validate([
            'technicianId' => ['required', 'exists:users,id'],
            'natureOfVisit' => ['required', \Illuminate\Validation\Rule::in(setting('nature_of_visit'))],
        ]);

        $technician = User::findOrFail($this->technicianId);

        $this->request->assignTechnician($technician, $this->natureOfVisit);
        $this->request->refresh();

        WorkflowNotifier::customer(
            $this->request->customer,
            'A technician has been assigned to your request',
            [
                "{$technician->name} has been assigned to {$this->request->reference}.",
                "Visit type: {$this->natureOfVisit}",
            ],
        );

        WorkflowNotifier::user(
            $technician,
            'You\'ve been assigned a service request',
            [
                "{$this->request->reference} for {$this->request->customer->name} has been assigned to you.",
                "Fault: {$this->request->fault_description}",
            ],
            url("/requests/{$this->request->id}"),
            'View request',
        );
    }

    public function decline(): void
    {
        $this->authorize('decline', $this->request);

        $this->request->update(['status' => 'Declined']);

        WorkflowNotifier::customer(
            $this->request->customer,
            'Your service request could not be proceeded',
            [
                "We're sorry, but request {$this->request->reference} has been declined.",
                'Please contact us if you have questions.',
            ],
        );
    }

    public function generateJob(): void
    {
        $this->authorize('create', WorkOrder::class);

        if ($this->request->status !== 'Assigned') {
            $this->addError('job', 'This request needs a technician assigned before a job can be generated.');
            return;
        }

        $this->validate([
            'dueDate' => ['required', 'date', 'after_or_equal:today'],
        ]);

        $reference = \App\Models\ReferenceSeries::next('work_order');

        // A contract-covered request counts as a visit against the
        // customer's current contract. Customers only ever have one
        // Active contract at a time (a new one can't be created until the
        // old one is terminated), so this lookup is unambiguous.
        $contractId = null;
        if ($this->request->cover === 'Contract') {
            $contractId = Contract::where('customer_id', $this->request->customer_id)
                ->where('status', 'Active')
                ->value('id');
        }

        $workOrder = WorkOrder::create([
            'reference' => $reference,
            'customer_id' => $this->request->customer_id,
            'contract_id' => $contractId,
            'customer_site_id' => $this->request->customer_site_id,
            'equipment_id' => $this->request->equipment_id,
            'equipment_description' => $this->request->equipment_description,
            'nature_of_visit' => $this->request->nature_of_visit,
            'assigned_technician_id' => $this->request->assigned_technician_id,
            'due_date' => $this->dueDate,
            'source_service_request_id' => $this->request->id,
            'currency_code' => \App\Models\Customer::find($this->request->customer_id)?->currencyCode() ?? currency(),
        ]);

        $this->request->update(['status' => 'Converted']);
        $this->request->refresh();

        $workOrder->load(['customer', 'technician']);

        WorkflowNotifier::customer(
            $workOrder->customer,
            'Your service visit has been scheduled',
            [
                "Job {$workOrder->reference} has been scheduled for {$this->dueDate}.",
                'Technician: '.($workOrder->technician->name ?? 'to be confirmed'),
            ],
        );

        WorkflowNotifier::user(
            $workOrder->technician,
            'New job scheduled',
            [
                "Job {$workOrder->reference} for {$workOrder->customer->name} is due {$this->dueDate}.",
            ],
            url("/jobs/{$workOrder->id}"),
            'View job',
        );

        $this->generatedReference = $workOrder->reference;
    }
};
?>

<div>
    <a href="/requests" wire:navigate class="mb-4 inline-flex items-center gap-1.5 text-sm text-neutral-500 hover:text-neutral-700">
        <x-icon name="arrow-right" class="h-3.5 w-3.5 rotate-180" />
        Back to requests
    </a>

    <div class="mb-5 flex items-start justify-between">
        <div>
            <h1 class="text-2xl font-semibold text-neutral-900">{{ $request->reference }}</h1>
            <p class="text-sm text-neutral-500">{{ $request->customer->name }}</p>
        </div>
        @php
            $pill = match (true) {
                $request->status === 'Converted' => 'pill-success',
                in_array($request->status, ['Assigned', 'Quoted']) => 'pill-info',
                $request->status === 'Declined' => 'pill-danger',
                default => 'pill-neutral',
            };
        @endphp
        <span class="{{ $pill }}">{{ $request->status }}</span>
    </div>

    @if ($generatedReference)
        <div class="card mb-4" style="background-color: var(--color-fresh-50); border-color: #bfe3c7">
            <p class="text-sm text-fresh-700">
                Job <strong>{{ $generatedReference }}</strong> created and assigned to {{ $request->technician->name }}.
            </p>
        </div>
    @endif

    <div class="card mb-4">
        <dl class="grid grid-cols-2 gap-4 text-sm">
            <div>
                <dt class="text-neutral-500">Contact</dt>
                <dd class="font-medium text-neutral-900">{{ $request->contact_name ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-neutral-500">Cover</dt>
                <dd class="font-medium text-neutral-900">{{ $request->cover }}</dd>
            </div>
            <div>
                <dt class="text-neutral-500">Priority</dt>
                <dd class="font-medium text-neutral-900">{{ $request->priority }}</dd>
            </div>
            <div>
                <dt class="text-neutral-500">Assigned to</dt>
                <dd class="font-medium text-neutral-900">{{ $request->technician->name ?? '—' }}</dd>
            </div>
        </dl>
        <div class="mt-4 border-t border-neutral-100 pt-4">
            <dt class="mb-1 text-sm text-neutral-500">Fault reported</dt>
            <dd class="text-sm text-neutral-900">{{ $request->fault_description }}</dd>
        </div>
        @if ($request->site)
            <div class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-neutral-100 pt-4">
                <div>
                    <dt class="mb-1 text-sm text-neutral-500">Location</dt>
                    <dd class="text-sm text-neutral-900">{{ $request->site->name }}@if ($request->site->address) <span class="text-neutral-500">&middot; {{ $request->site->address }}</span>@endif</dd>
                </div>
                @if ($request->site->directionsUrl())
                    <a href="{{ $request->site->directionsUrl() }}" target="_blank" rel="noopener" class="btn-outline">Directions</a>
                @endif
            </div>
        @endif
    </div>

    @can('assign', $request)
        <div class="card mb-4">
            <h2 class="mb-3 text-sm font-semibold text-neutral-900">Assign a technician</h2>

            <div class="mb-3">
                <label class="label">Technician</label>
                <select wire:model="technicianId" class="input">
                    <option value="">Select a technician</option>
                    @foreach ($this->technicians as $technician)
                        <option value="{{ $technician->id }}">{{ $technician->name }}</option>
                    @endforeach
                </select>
                @error('technicianId') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            <div class="mb-4">
                <label class="label">Nature of visit</label>
                <select wire:model="natureOfVisit" class="input">
                    @foreach (setting('nature_of_visit') as $nature)
                        <option>{{ $nature }}</option>
                    @endforeach
                </select>
                @error('natureOfVisit') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            <div class="flex gap-2">
                <button wire:click="assign" wire:loading.attr="disabled" wire:target="assign" class="btn-primary">
                    Assign technician
                </button>
                <button wire:click="decline" wire:loading.attr="disabled" wire:target="decline" class="btn-outline">
                    Decline
                </button>
            </div>
        </div>
    @endcan

    @can('create', \App\Models\WorkOrder::class)
        @if ($request->status === 'Assigned' && ! $generatedReference)
            <div class="card">
                <h2 class="mb-3 text-sm font-semibold text-neutral-900">Generate the job</h2>
                @error('job') <p class="field-error">{{ $message }}</p> @enderror

                <div class="mb-4">
                    <label class="label">Due date</label>
                    <input type="date" wire:model="dueDate" class="input">
                    @error('dueDate') <p class="field-error">{{ $message }}</p> @enderror
                </div>

                <button wire:click="generateJob" wire:loading.attr="disabled" wire:target="generateJob" class="btn-primary">
                    Generate job
                </button>
            </div>
        @endif
    @endcan
</div>
