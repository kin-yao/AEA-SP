<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Models\WorkOrder;
use App\Models\Document;

new #[Layout('layouts.app', ['title' => 'Service report'])] class extends Component
{
    public WorkOrder $job;

    public string $contactName = '';
    public string $address = '';
    public string $faultDescription = '';
    public string $cause = '';
    public string $correction = '';
    public string $finalResult = '';
    public string $incidentType = 'None';
    public string $incidentDescription = '';
    public string $customerSignoffName = '';

    public array $parts = [];

    public function mount(WorkOrder $job): void
    {
        $this->authorize('updateStatus', $job);

        if ($job->assigned_technician_id !== auth()->id()) {
            abort(403, 'This job is not assigned to you.');
        }

        if ($job->status !== 'On site') {
            abort(403, 'A report can only be filed while the job is On site.');
        }

        $this->job = $job;
        $this->faultDescription = $job->sourceRequest?->fault_description ?? '';
        $this->parts = [['item' => '', 'partNumber' => '', 'quantity' => 1, 'source' => '']];
    }

    public function addPart(): void
    {
        $this->parts[] = ['item' => '', 'partNumber' => '', 'quantity' => 1, 'source' => ''];
    }

    public function removePart(int $index): void
    {
        unset($this->parts[$index]);
        $this->parts = array_values($this->parts);

        if (empty($this->parts)) {
            $this->parts = [['item' => '', 'partNumber' => '', 'quantity' => 1, 'source' => '']];
        }
    }

    public function submit(): void
    {
        $this->validate([
            'contactName' => ['nullable', 'string'],
            'address' => ['nullable', 'string'],
            'faultDescription' => ['required', 'string'],
            'cause' => ['required', 'string'],
            'correction' => ['required', 'string'],
            'finalResult' => ['required', 'string'],
            'incidentType' => ['required', 'in:None,Near miss,Damage,Safety issue'],
            'incidentDescription' => ['required_unless:incidentType,None', 'nullable', 'string'],
            'customerSignoffName' => ['nullable', 'string'],
        ]);

        $reference = (string) (Document::max('id') + 1);

        $document = Document::create([
            'reference' => $reference,
            'type' => Document::TYPE_REPORT,
            'work_order_id' => $this->job->id,
            'customer_id' => $this->job->customer_id,
            'status' => 'Awaiting review',
            'filed_by' => auth()->id(),
        ]);

        $detail = $document->reportDetail()->create([
            'vehicle' => $this->job->vehicle,
            'contact_name' => $this->contactName,
            'address' => $this->address,
            'nature_of_visit' => $this->job->nature_of_visit,
            'fault_description' => $this->faultDescription,
            'cause' => $this->cause,
            'correction' => $this->correction,
            'final_result' => $this->finalResult,
            'incident_type' => $this->incidentType,
            'incident_description' => $this->incidentType === 'None' ? null : $this->incidentDescription,
            'repairer_name' => auth()->user()->name,
            'customer_signoff_name' => $this->customerSignoffName,
        ]);

        foreach ($this->parts as $part) {
            if (blank($part['item'])) {
                continue;
            }

            $detail->parts()->create([
                'item' => $part['item'],
                'part_number' => $part['partNumber'],
                'quantity' => (int) ($part['quantity'] ?: 1),
                'source' => $part['source'],
            ]);
        }

        // Only this, a real filed report, moves the job to Awaiting review,
        // not the generic status button on the job's own page anymore.
        $this->job->update(['status' => 'Awaiting review']);

        $this->redirect('/jobs/'.$this->job->id, navigate: true);
    }
};
?>

<div>
    <a href="/jobs/{{ $job->id }}" wire:navigate class="mb-4 inline-flex items-center gap-1.5 text-sm text-gray-500 hover:text-gray-700">
        <x-icon name="arrow-right" class="h-3.5 w-3.5 rotate-180" />
        Back to {{ $job->reference }}
    </a>

    <h1 class="mb-1 text-xl font-semibold text-gray-900">Service report</h1>
    <p class="mb-6 text-sm text-gray-500">{{ $job->reference }} &middot; {{ $job->customer->name }}</p>

    <form wire:submit="submit" class="space-y-4">
        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <h2 class="mb-3 text-sm font-medium text-gray-900">Customer</h2>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="mb-1.5 block text-xs font-medium text-gray-700">Contact person</label>
                    <input wire:model="contactName" type="text" class="w-full rounded-lg border border-gray-300 py-2 px-3 text-sm focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500">
                </div>
                <div>
                    <label class="mb-1.5 block text-xs font-medium text-gray-700">Address</label>
                    <input wire:model="address" type="text" class="w-full rounded-lg border border-gray-300 py-2 px-3 text-sm focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500">
                </div>
            </div>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <h2 class="mb-3 text-sm font-medium text-gray-900">Job details</h2>

            <div class="mb-3">
                <label class="mb-1.5 block text-xs font-medium text-gray-700">Fault reported</label>
                <textarea wire:model="faultDescription" rows="2" class="w-full rounded-lg border border-gray-300 py-2 px-3 text-sm focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500"></textarea>
                @error('faultDescription') <p class="mt-1 text-xs text-primary-600">{{ $message }}</p> @enderror
            </div>

            <div class="mb-3">
                <label class="mb-1.5 block text-xs font-medium text-gray-700">Cause</label>
                <textarea wire:model="cause" rows="2" class="w-full rounded-lg border border-gray-300 py-2 px-3 text-sm focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500"></textarea>
                @error('cause') <p class="mt-1 text-xs text-primary-600">{{ $message }}</p> @enderror
            </div>

            <div class="mb-3">
                <label class="mb-1.5 block text-xs font-medium text-gray-700">Correction</label>
                <textarea wire:model="correction" rows="3" class="w-full rounded-lg border border-gray-300 py-2 px-3 text-sm focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500"></textarea>
                @error('correction') <p class="mt-1 text-xs text-primary-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="mb-1.5 block text-xs font-medium text-gray-700">Final result</label>
                <textarea wire:model="finalResult" rows="2" class="w-full rounded-lg border border-gray-300 py-2 px-3 text-sm focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500"></textarea>
                @error('finalResult') <p class="mt-1 text-xs text-primary-600">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="text-sm font-medium text-gray-900">Parts used</h2>
                <button type="button" wire:click="addPart" class="text-xs font-medium text-primary-600 hover:text-primary-700">
                    Add part
                </button>
            </div>

            @foreach ($parts as $index => $part)
                <div class="mb-2 flex items-center gap-2">
                    <input wire:model="parts.{{ $index }}.item" type="text" placeholder="Item" class="flex-[2] rounded-lg border border-gray-300 py-2 px-3 text-sm focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500">
                    <input wire:model="parts.{{ $index }}.partNumber" type="text" placeholder="Part no." class="flex-1 rounded-lg border border-gray-300 py-2 px-3 text-sm focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500">
                    <input wire:model="parts.{{ $index }}.quantity" type="number" min="1" placeholder="Qty" class="w-16 rounded-lg border border-gray-300 py-2 px-3 text-sm focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500">
                    @if (count($parts) > 1)
                        <button type="button" wire:click="removePart({{ $index }})" class="shrink-0 text-gray-400 hover:text-primary-600">
                            &times;
                        </button>
                    @endif
                </div>
            @endforeach
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <h2 class="mb-3 text-sm font-medium text-gray-900">Incident</h2>

            <div class="mb-3">
                <label class="mb-1.5 block text-xs font-medium text-gray-700">Did anything happen on site worth logging?</label>
                <select wire:model.live="incidentType" class="w-full rounded-lg border border-gray-300 py-2 px-3 text-sm focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500">
                    <option>None</option>
                    <option>Near miss</option>
                    <option>Damage</option>
                    <option>Safety issue</option>
                </select>
            </div>

            @if ($incidentType !== 'None')
                <div>
                    <label class="mb-1.5 block text-xs font-medium text-gray-700">What happened, when, where and who was involved</label>
                    <textarea wire:model="incidentDescription" rows="3" class="w-full rounded-lg border border-gray-300 py-2 px-3 text-sm focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500"></textarea>
                    @error('incidentDescription') <p class="mt-1 text-xs text-primary-600">{{ $message }}</p> @enderror
                </div>
            @endif
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <h2 class="mb-3 text-sm font-medium text-gray-900">Sign off</h2>
            <div class="grid grid-cols-2 gap-4 text-sm">
                <div>
                    <p class="mb-1 text-xs text-gray-500">Repairer</p>
                    <p class="text-gray-900">{{ auth()->user()->name }}</p>
                </div>
                <div>
                    <label class="mb-1.5 block text-xs font-medium text-gray-700">Customer name and title</label>
                    <input wire:model="customerSignoffName" type="text" placeholder="e.g. Evans Mutiso, Stores Manager" class="w-full rounded-lg border border-gray-300 py-2 px-3 text-sm focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500">
                </div>
            </div>
        </div>

        <button type="submit" wire:loading.attr="disabled" wire:target="submit"
                class="w-full rounded-lg bg-primary-500 py-2.5 text-sm font-medium text-white hover:bg-primary-600">
            Submit report
        </button>
    </form>
</div>
