<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Models\WorkOrder;

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
        $this->dueDate = now()->addDays(3)->toDateString();
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
            'natureOfVisit' => ['required', 'string'],
        ]);

        $technician = User::findOrFail($this->technicianId);

        $this->request->assignTechnician($technician, $this->natureOfVisit);
        $this->request->refresh();
    }

    public function decline(): void
    {
        $this->authorize('decline', $this->request);

        $this->request->update(['status' => 'Declined']);
    }

    public function generateJob(): void
    {
        $this->authorize('create', WorkOrder::class);

        if ($this->request->status !== 'Assigned') {
            $this->addError('job', 'This request needs a technician assigned before a job can be generated.');
            return;
        }

        $this->validate([
            'dueDate' => ['required', 'date'],
        ]);

        $reference = 'WO-'.str_pad((string) (WorkOrder::max('id') + 1), 4, '0', STR_PAD_LEFT);

        $workOrder = WorkOrder::create([
            'reference' => $reference,
            'customer_id' => $this->request->customer_id,
            'customer_site_id' => $this->request->customer_site_id,
            'equipment_id' => $this->request->equipment_id,
            'equipment_description' => $this->request->equipment_description,
            'nature_of_visit' => $this->request->nature_of_visit,
            'assigned_technician_id' => $this->request->assigned_technician_id,
            'due_date' => $this->dueDate,
            'source_service_request_id' => $this->request->id,
        ]);

        $this->request->update(['status' => 'Converted']);
        $this->request->refresh();

        $this->generatedReference = $workOrder->reference;
    }
};
?>

<div>
    <a href="/requests" wire:navigate class="mb-4 inline-flex items-center gap-1.5 text-sm text-gray-500 hover:text-gray-700">
        <x-icon name="arrow-right" class="h-3.5 w-3.5 rotate-180" />
        Back to requests
    </a>

    <div class="mb-4 flex items-start justify-between">
        <div>
            <h1 class="text-xl font-semibold text-gray-900">{{ $request->reference }}</h1>
            <p class="text-sm text-gray-500">{{ $request->customer->name }}</p>
        </div>
        <span @class([
            'shrink-0 rounded-full px-2.5 py-1 text-xs font-medium',
            'bg-amber-50 text-amber-700' => $request->status === 'Open',
            'bg-blue-50 text-blue-700' => $request->status === 'Assigned',
            'bg-primary-50 text-primary-700' => $request->status === 'Quoted',
            'bg-green-50 text-green-700' => $request->status === 'Converted',
            'bg-gray-100 text-gray-600' => $request->status === 'Declined',
        ])>
            {{ $request->status }}
        </span>
    </div>

    @if ($generatedReference)
        <div class="mb-4 rounded-xl border border-green-200 bg-green-50 p-4 text-sm text-green-800">
            Job <strong>{{ $generatedReference }}</strong> created and assigned to {{ $request->technician->name }}.
            The Jobs screen to view it directly isn't built yet, the record itself is real.
        </div>
    @endif

    <div class="mb-4 rounded-xl border border-gray-200 bg-white p-5">
        <dl class="grid grid-cols-2 gap-4 text-sm">
            <div>
                <dt class="text-gray-500">Contact</dt>
                <dd class="text-gray-900">{{ $request->contact_name ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-gray-500">Cover</dt>
                <dd class="text-gray-900">{{ $request->cover }}</dd>
            </div>
            <div>
                <dt class="text-gray-500">Priority</dt>
                <dd class="text-gray-900">{{ $request->priority }}</dd>
            </div>
            <div>
                <dt class="text-gray-500">Assigned to</dt>
                <dd class="text-gray-900">{{ $request->technician->name ?? '—' }}</dd>
            </div>
        </dl>
        <div class="mt-4 border-t border-gray-100 pt-4">
            <dt class="mb-1 text-sm text-gray-500">Fault reported</dt>
            <dd class="text-sm text-gray-900">{{ $request->fault_description }}</dd>
        </div>
    </div>

    @can('assign', $request)
        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <h2 class="mb-3 text-sm font-medium text-gray-900">Assign a technician</h2>

            <div class="mb-3">
                <label class="mb-1.5 block text-xs font-medium text-gray-700">Technician</label>
                <select wire:model="technicianId" class="w-full rounded-lg border border-gray-300 py-2 px-3 text-sm focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500">
                    <option value="">Select a technician</option>
                    @foreach ($this->technicians as $technician)
                        <option value="{{ $technician->id }}">{{ $technician->name }}</option>
                    @endforeach
                </select>
                @error('technicianId') <p class="mt-1 text-xs text-primary-600">{{ $message }}</p> @enderror
            </div>

            <div class="mb-4">
                <label class="mb-1.5 block text-xs font-medium text-gray-700">Nature of visit</label>
                <select wire:model="natureOfVisit" class="w-full rounded-lg border border-gray-300 py-2 px-3 text-sm focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500">
                    <option>Service</option>
                    <option>Repairs</option>
                    <option>Planned maintenance</option>
                    <option>Calibration</option>
                </select>
            </div>

            <div class="flex gap-2">
                <button wire:click="assign" wire:loading.attr="disabled" wire:target="assign"
                        class="rounded-lg bg-primary-500 px-4 py-2 text-sm font-medium text-white hover:bg-primary-600">
                    Assign technician
                </button>
                <button wire:click="decline" wire:loading.attr="disabled" wire:target="decline"
                        class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    Decline
                </button>
            </div>
        </div>
    @endcan

    @can('create', \App\Models\WorkOrder::class)
        @if ($request->status === 'Assigned' && ! $generatedReference)
            <div class="mt-4 rounded-xl border border-gray-200 bg-white p-5">
                <h2 class="mb-3 text-sm font-medium text-gray-900">Generate the job</h2>
                @error('job') <p class="mb-3 text-xs text-primary-600">{{ $message }}</p> @enderror

                <div class="mb-4">
                    <label class="mb-1.5 block text-xs font-medium text-gray-700">Due date</label>
                    <input type="date" wire:model="dueDate"
                           class="w-full rounded-lg border border-gray-300 py-2 px-3 text-sm focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500">
                    @error('dueDate') <p class="mt-1 text-xs text-primary-600">{{ $message }}</p> @enderror
                </div>

                <button wire:click="generateJob" wire:loading.attr="disabled" wire:target="generateJob"
                        class="rounded-lg bg-primary-500 px-4 py-2 text-sm font-medium text-white hover:bg-primary-600">
                    Generate job
                </button>
            </div>
        @endif
    @endcan
</div>