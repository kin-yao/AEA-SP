<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Models\Contract;
use App\Models\WorkOrder;
use App\Models\ContractServiceDate;
use App\Models\User;
use App\Services\WorkflowNotifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

new #[Layout('layouts.app', ['title' => 'Contract'])] class extends Component
{
    public Contract $contract;

    public bool $editingDates = false;
    public array $dateEdits = [];      // service date id => new date
    public string $newDate = '';
    public string $technicianId = '';

    public function mount(Contract $contract): void
    {
        $this->authorize('view', $contract);
        $contract->load('customer');
        $this->contract = $contract;
    }

    public function startEditing(): void
    {
        $this->authorize('manageSchedule', $this->contract);
        $this->dateEdits = $this->contract->serviceDates()->whereNull('work_order_id')->get()->mapWithKeys(fn ($d) => [$d->id => $d->due_on->toDateString()])->all();
        $this->editingDates = true;
        $this->resetErrorBag();
    }

    public function stopEditing(): void
    {
        $this->editingDates = false;
        $this->newDate = '';
        $this->resetErrorBag();
    }

    protected function termRules(): array
    {
        return ['date_format:Y-m-d', 'after_or_equal:'.$this->contract->starts_at->toDateString(), 'before_or_equal:'.$this->contract->ends_at->toDateString()];
    }

    public function saveDates(): void
    {
        $this->authorize('manageSchedule', $this->contract);

        $this->validate([
            'dateEdits.*' => [...$this->termRules(), 'distinct'],
        ], [
            'dateEdits.*.after_or_equal' => 'A service date cannot be before the contract starts.',
            'dateEdits.*.before_or_equal' => 'A service date cannot be after the contract ends.',
            'dateEdits.*.distinct' => 'The same date is listed twice.',
            'dateEdits.*.date_format' => 'Enter a full date.',
        ]);

        $taken = $this->contract->serviceDates()->whereNotIn('id', array_keys($this->dateEdits))->pluck('due_on')->map->toDateString()->all();
        if (array_intersect($taken, $this->dateEdits)) {
            $this->addError('dateEdits', 'One of those dates is already in the schedule.');

            return;
        }

        DB::transaction(function () {
            foreach ($this->dateEdits as $id => $date) {
                ContractServiceDate::where('contract_id', $this->contract->id)->whereNull('work_order_id')->whereKey($id)->update(['due_on' => $date]);
            }
        });

        $this->editingDates = false;
    }

    public function addDate(): void
    {
        $this->authorize('manageSchedule', $this->contract);

        $this->validate(['newDate' => ['required', ...$this->termRules()]], [
            'newDate.required' => 'Pick the date to add.',
            'newDate.after_or_equal' => 'The date cannot be before the contract starts.',
            'newDate.before_or_equal' => 'The date cannot be after the contract ends.',
        ]);

        if ($this->contract->serviceDates()->whereDate('due_on', $this->newDate)->exists()) {
            $this->addError('newDate', 'That date is already in the schedule.');

            return;
        }

        $this->contract->serviceDates()->create(['due_on' => $this->newDate]);
        $this->newDate = '';

        if ($this->editingDates) {
            $this->startEditing();
        }
    }

    public function removeDate(int $id): void
    {
        $this->authorize('manageSchedule', $this->contract);
        $this->contract->serviceDates()->whereNull('work_order_id')->whereKey($id)->delete();
        $this->startEditing();
    }

    /** Turn a planned date into a job for a technician, in one step. */
    public function scheduleJob(int $id): void
    {
        $this->authorize('manageSchedule', $this->contract);

        $this->validate(['technicianId' => ['required', 'exists:users,id']], ['technicianId.required' => 'Choose the technician for this visit first.']);
        $technician = User::role('Technician')->findOrFail($this->technicianId);

        $slot = $this->contract->serviceDates()->whereNull('work_order_id')->findOrFail($id);
        $machines = $this->contract->equipment;

        $job = DB::transaction(function () use ($slot, $technician, $machines) {
            $job = \App\Models\WorkOrder::create([
                'reference' => \App\Models\ReferenceSeries::next('work_order'),
                'customer_id' => $this->contract->customer_id,
                'contract_id' => $this->contract->id,
                'equipment_id' => $machines->count() === 1 ? $machines->first()->id : null,
                'equipment_description' => $machines->count() === 1 ? null : $machines->count().' machines under '.$this->contract->reference,
                'nature_of_visit' => 'Planned maintenance',
                'assigned_technician_id' => $technician->id,
                'due_date' => $slot->due_on,
                'currency_code' => $this->contract->customer?->currencyCode() ?? currency(),
            ]);
            $slot->update(['work_order_id' => $job->id]);

            return $job;
        });

        WorkflowNotifier::user($technician, 'A maintenance visit has been assigned to you', ["Job {$job->reference} for {$this->contract->customer->name} is due ".$slot->due_on->format('d M Y').'.'], url("/jobs/{$job->id}"), 'View job');
        WorkflowNotifier::customer($this->contract->customer, 'Your maintenance visit has been scheduled', ["Job {$job->reference} is booked for ".$slot->due_on->format('d M Y').".", 'Technician: '.$technician->name]);
    }

    public function with(): array
    {
        $dates = $this->contract->serviceDates()->with('workOrder')->get();

        return [
            'machines' => $this->contract->equipment()->orderBy('model')->get(),
            'dates' => $dates,
            'technicians' => auth()->user()->can('manageSchedule', $this->contract) ? User::role('Technician')->orderBy('name')->get(['id', 'name']) : collect(),
            'canSchedule' => auth()->user()->can('manageSchedule', $this->contract),
            'staff' => ! auth()->user()->hasRole('Customer'),
            'nextVisit' => $this->contract->nextVisit(),
            'visitHistory' => $this->contract->workOrders()
                ->with('technician')
                ->orderByDesc('due_date')
                ->get(),
        ];
    }

    public function terminate(): void
    {
        $this->authorize('terminate', $this->contract);

        $this->contract->update(['status' => 'Terminated']);
        $this->contract->refresh();
    }
};
?>

<div>
    <a href="/contracts" wire:navigate class="mb-4 inline-flex items-center gap-1.5 text-sm text-neutral-500 hover:text-neutral-900">
        <x-icon name="arrow-right" class="h-3.5 w-3.5 rotate-180" />
        Back to contracts
    </a>

    <div class="mb-6 flex items-center justify-between">
        <div class="flex items-center gap-3">
            <h1 class="text-xl font-semibold text-neutral-900">{{ $contract->reference }}</h1>
            <span class="pill-{{ $contract->status === 'Active' ? 'success' : 'neutral' }}">{{ $contract->status }}</span>
        </div>

        <div class="flex gap-2">
            @if ($contract->status === 'Terminated' && auth()->user()->can('create', \App\Models\Contract::class))
                <a href="/contracts/create?customer={{ $contract->customer_id }}" wire:navigate class="btn-outline">
                    Create new contract
                </a>
            @endif
            @can('terminate', $contract)
                <button type="button" wire:click="terminate" wire:confirm="Terminate this contract?" class="btn-outline">
                    Terminate
                </button>
            @endcan
        </div>
    </div>

    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
        <div class="card flex flex-col items-center justify-center text-center">
            <x-expiry-ring
                :percent="$contract->percentOfTermUsedCapped()"
                :stage="$contract->status === 'Terminated' ? 'fresh' : $contract->expiryStage()"
                :title="$contract->value_minor ? $contract->currency_code.' '.number_format($contract->value_minor / 100, 2) : 'No value on file'"
                :expiresAt="$contract->ends_at->format('d M Y')"
            />
        </div>

        <div class="card">
            <dl class="space-y-3 text-sm">
                <div class="flex justify-between">
                    <dt class="text-neutral-500">Customer</dt>
                    <dd class="font-medium text-neutral-900">{{ $contract->customer->name }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-neutral-500">Type</dt>
                    <dd class="font-medium text-neutral-900">{{ $contract->type }}</dd>
                </div>
                @if ($contract->frequency)
                    <div class="flex justify-between">
                        <dt class="text-neutral-500">Maintenance</dt>
                        <dd class="font-medium text-neutral-900">{{ $contract->frequency }}</dd>
                    </div>
                @endif
                <div class="flex justify-between">
                    <dt class="text-neutral-500">Start date</dt>
                    <dd class="font-medium text-neutral-900">{{ $contract->starts_at->format('d M Y') }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-neutral-500">End date</dt>
                    <dd class="font-medium text-neutral-900">{{ $contract->ends_at->format('d M Y') }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-neutral-500">Visits used</dt>
                    <dd class="font-medium text-neutral-900">{{ $contract->visits_included - $contract->visitsRemaining() }}/{{ $contract->visits_included }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-neutral-500">Visits remaining</dt>
                    <dd class="font-medium text-neutral-900">{{ $contract->visitsRemaining() }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-neutral-500">Value</dt>
                    <dd class="font-medium text-neutral-900">
                        {{ $contract->value_minor ? $contract->currency_code.' '.number_format($contract->value_minor / 100, 2) : '—' }}
                    </dd>
                </div>
            </dl>
        </div>
    </div>

    <div class="card mt-4">
        <p class="mb-2 text-sm font-medium text-neutral-700">Machines covered ({{ $machines->count() }})</p>
        @forelse ($machines as $m)
            <div class="flex items-center justify-between border-b border-neutral-100 py-2 text-sm last:border-0" wire:key="cm-{{ $m->id }}">
                <div>
                    <p class="font-medium text-neutral-900">{{ $m->model }}</p>
                    <p class="font-mono text-xs text-neutral-500">{{ $m->serial_number }}</p>
                </div>
                @if ($staff)
                    <a href="/equipment/{{ $m->id }}" wire:navigate class="text-xs font-medium text-info-700 hover:text-info-800">View machine</a>
                @endif
            </div>
        @empty
            <p class="text-sm text-neutral-500">No machines are linked to this contract.</p>
        @endforelse
    </div>

    <div class="card mt-4">
        <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
            <p class="text-sm font-medium text-neutral-700">Planned service dates ({{ $dates->count() }})</p>
            @if ($canSchedule && ! $editingDates)
                <button type="button" wire:click="startEditing" class="btn-outline" style="padding: 0.3rem 0.8rem">Edit dates</button>
            @endif
        </div>

        @if ($canSchedule)
            <div class="mb-3">
                <label class="label">Technician for the visits you schedule</label>
                <select wire:model="technicianId" class="input" style="max-width: 20rem">
                    <option value="">Choose a technician</option>
                    @foreach ($technicians as $t)
                        <option value="{{ $t->id }}">{{ $t->name }}</option>
                    @endforeach
                </select>
                @error('technicianId') <p class="field-error">{{ $message }}</p> @enderror
            </div>
        @endif

        @forelse ($dates as $d)
            @php
                $job = $d->workOrder;
                [$label, $pill] = match (true) {
                    $job && $job->status === 'Closed' => ['Done', 'pill-success'],
                    (bool) $job => ['Job '.$job->reference, 'pill-info'],
                    $d->due_on->lt(today()) => ['Missed', 'pill-danger'],
                    $d->due_on->lte(today()->addDays(14)) => ['Coming up', 'pill-amber'],
                    default => ['Planned', 'pill-neutral'],
                };
            @endphp
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-neutral-100 py-2 text-sm last:border-0" wire:key="sd-{{ $d->id }}">
                @if ($editingDates && ! $job)
                    <div class="flex items-center gap-2">
                        <input wire:model="dateEdits.{{ $d->id }}" type="date" class="input" style="width: auto">
                        <button type="button" wire:click="removeDate({{ $d->id }})" wire:confirm="Remove this service date?" class="px-2 text-neutral-400 hover:text-critical-700" aria-label="Remove date">&times;</button>
                    </div>
                    @error("dateEdits.{$d->id}") <p class="field-error w-full">{{ $message }}</p> @enderror
                @else
                    <span class="font-medium text-neutral-900">{{ $d->due_on->format('D, d M Y') }}</span>
                @endif
                <div class="flex items-center gap-2">
                    @if ($job && $staff)
                        <a href="/jobs/{{ $job->id }}" wire:navigate class="text-xs font-medium text-info-700 hover:text-info-800">Open job</a>
                    @endif
                    @if ($canSchedule && ! $job && ! $editingDates)
                        <button type="button" wire:click="scheduleJob({{ $d->id }})" wire:loading.attr="disabled" wire:target="scheduleJob" class="btn-outline" style="padding: 0.25rem 0.7rem; font-size: 0.75rem">Schedule job</button>
                    @endif
                    <span class="{{ $pill }}">{{ $label }}</span>
                </div>
            </div>
        @empty
            <p class="text-sm text-neutral-500">{{ $contract->frequency ? 'No service dates are planned.' : 'This contract has no fixed schedule. Visits are made on call.' }}</p>
        @endforelse

        @if ($editingDates)
            @error('dateEdits') <p class="field-error">{{ $message }}</p> @enderror
            <div class="mt-3 flex flex-wrap items-end gap-2 border-t border-neutral-100 pt-3">
                <div>
                    <label class="label">Add a date</label>
                    <input wire:model="newDate" type="date" class="input" style="width: auto">
                </div>
                <button type="button" wire:click="addDate" class="btn-outline">Add</button>
            </div>
            @error('newDate') <p class="field-error">{{ $message }}</p> @enderror
            <div class="mt-3 flex gap-2">
                <button type="button" wire:click="saveDates" class="btn-primary" wire:loading.attr="disabled" wire:target="saveDates">Save dates</button>
                <button type="button" wire:click="stopEditing" class="btn-outline">Cancel</button>
            </div>
        @endif
    </div>

    <div class="card mt-4">
        <p class="mb-2 text-sm font-medium text-neutral-700">Next visit</p>
        @if ($nextVisit)
            <p class="text-sm text-neutral-900">
                {{ $nextVisit->due_date->format('d M Y') }} &middot; {{ $nextVisit->technician->name ?? 'Technician to be confirmed' }}
                <span class="text-neutral-500">&middot; {{ $nextVisit->nature_of_visit }}</span>
            </p>
        @else
            <p class="text-sm text-neutral-500">No visit currently scheduled.</p>
        @endif
    </div>

    <div class="card mt-4">
        <p class="mb-2 text-sm font-medium text-neutral-700">Visit history</p>
        @forelse ($visitHistory as $visit)
            <div class="flex items-center justify-between border-b border-neutral-100 py-2 text-sm last:border-0">
                <div>
                    <p class="font-medium text-neutral-900">{{ $visit->due_date->format('d M Y') }} &middot; {{ $visit->technician->name ?? 'Unassigned' }}</p>
                    <p class="text-xs text-neutral-500">{{ $visit->reference }} &middot; {{ $visit->nature_of_visit }}</p>
                </div>
                <span class="pill-{{ $visit->status === 'Closed' ? 'success' : 'info' }}">{{ $visit->status }}</span>
            </div>
        @empty
            <p class="text-sm text-neutral-500">No visits logged against this contract yet.</p>
        @endforelse
    </div>

    <div class="card mt-4">
        <p class="mb-2 text-sm font-medium text-neutral-700">Signed contract</p>
        @if ($contract->scan_file_path)
            <a href="{{ \App\Support\Files::url($contract->scan_file_path) }}" target="_blank" class="inline-flex items-center gap-1.5 text-sm font-medium text-primary-700 hover:underline">
                <x-icon name="file-earmark-pdf" class="h-4 w-4" />
                View document
            </a>
        @else
            <p class="text-sm text-neutral-500">No document on file.</p>
        @endif
    </div>
</div>
