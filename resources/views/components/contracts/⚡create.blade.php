<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use Livewire\WithFileUploads;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Equipment;
use App\Support\ServiceSchedule;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

new #[Layout('layouts.app', ['title' => 'New contract'])] class extends Component
{
    use WithFileUploads;

    public string $customer_id = '';
    public string $type = '';
    public string $frequency = '';        // a name from Settings, or '' for visits on call
    public string $starts_at = '';
    public string $ends_at = '';
    public array $machineIds = [];
    public array $dates = [];             // planned service dates, made from the frequency and then editable
    public string $visits_included = '0'; // only typed in when there is no fixed schedule
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
        $this->machineIds = [];
    }

    public function updatedFrequency(): void
    {
        $this->rebuildDates();
    }

    public function updatedStartsAt(): void
    {
        $this->rebuildDates();
    }

    public function updatedEndsAt(): void
    {
        $this->rebuildDates();
    }

    /** Make the date list from the frequency. Only runs when the inputs it depends on change. */
    public function rebuildDates(): void
    {
        $this->resetErrorBag('dates');
        $freq = Settings::maintenanceFrequencies()[$this->frequency] ?? null;

        try {
            $start = Carbon::createFromFormat('Y-m-d', $this->starts_at);
            $end = Carbon::createFromFormat('Y-m-d', $this->ends_at);
        } catch (\Throwable $e) {
            $this->dates = [];

            return;
        }

        $this->dates = ($freq && $start && $end && $end->gt($start) && $end->lt($start->copy()->addYears(15)))
            ? ServiceSchedule::generate($start, $end, $freq['every'], $freq['unit'])
            : [];
    }

    public function addDate(): void
    {
        if (count($this->dates) < ServiceSchedule::MAX_DATES) {
            $this->dates[] = '';
        }
    }

    public function removeDate(int $i): void
    {
        unset($this->dates[$i]);
        $this->dates = array_values($this->dates);
    }

    public function mount(): void
    {
        $this->authorize('create', Contract::class);
        $this->currency_code = currency();
        $this->type = Settings::contractTypes()[0] ?? '';

        $customer = request('customer');
        if ($customer) {
            $this->customer_id = (string) $customer;
            $this->currency_code = Customer::find($customer)?->currencyCode() ?? currency();
        }
    }

    public function with(): array
    {
        return [
            'customers' => Customer::orderBy('name')->get(['id', 'name']),
            'machines' => $this->customer_id
                ? Equipment::where('customer_id', $this->customer_id)->orderBy('model')->get(['id', 'model', 'serial_number'])
                : collect(),
            'types' => Settings::contractTypes(),
            'frequencies' => Settings::maintenanceFrequencies(),
        ];
    }

    public function submit(): void
    {
        $this->authorize('create', Contract::class);

        $this->machineIds = array_values(array_unique(array_map('strval', $this->machineIds)));
        $this->dates = array_values(array_filter(array_map('trim', $this->dates), fn ($d) => $d !== ''));

        $validated = $this->validate([
            'customer_id' => ['required', 'exists:customers,id'],
            'type' => ['required', Rule::in(Settings::contractTypes())],
            'frequency' => ['nullable', Rule::in(array_keys(Settings::maintenanceFrequencies()))],
            'starts_at' => ['required', 'date', 'after:2000-01-01'],
            'ends_at' => ['required', 'date', 'after:starts_at', 'before:+15 years'],
            'machineIds' => ['required', 'array', 'min:1', 'max:200'],
            'machineIds.*' => ['integer', Rule::exists('equipment', 'id')->where('customer_id', $this->customer_id)],
            'dates' => ['array', 'max:'.ServiceSchedule::MAX_DATES],
            'dates.*' => ['date_format:Y-m-d', 'distinct', 'after_or_equal:starts_at', 'before_or_equal:ends_at'],
            'visits_included' => ['nullable', 'integer', 'min:0', 'max:365'],
            'value' => \App\Support\Rules::money(false),
            'currency_code' => ['required', Rule::in($this->currencyChoices())],
            'scan' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'],
        ], [
            'machineIds.required' => 'Choose at least one machine this contract covers.',
            'machineIds.min' => 'Choose at least one machine this contract covers.',
            'machineIds.*.exists' => 'Choose only machines that belong to this customer.',
            'dates.*.distinct' => 'The same service date is listed twice.',
            'dates.*.date_format' => 'Enter each service date as a full date.',
            'dates.*.after_or_equal' => 'A service date falls before the contract starts.',
            'dates.*.before_or_equal' => 'A service date falls after the contract ends.',
            'type.in' => 'Choose one of the contract types.',
        ]);

        if ($validated['frequency'] !== '' && $validated['frequency'] !== null && count($this->dates) === 0) {
            $this->addError('dates', 'This frequency gives no service dates between the start and end dates. Add the dates by hand or choose another frequency.');

            return;
        }

        $reference = \App\Models\ReferenceSeries::next('contract');
        $path = \App\Support\Files::put($this->scan, 'contracts');
        $sorted = collect($this->dates)->sort()->values();

        $contract = DB::transaction(function () use ($validated, $reference, $path, $sorted) {
            $contract = Contract::create([
                'reference' => $reference,
                'customer_id' => $validated['customer_id'],
                'type' => $validated['type'],
                'frequency' => $validated['frequency'] ?: null,
                'status' => 'Active',
                'starts_at' => $validated['starts_at'],
                'ends_at' => $validated['ends_at'],
                'visits_included' => $sorted->isNotEmpty() ? $sorted->count() : (int) ($validated['visits_included'] ?? 0),
                'value_minor' => $validated['value'] !== '' && $validated['value'] !== null
                    ? (int) round((float) $validated['value'] * 100)
                    : null,
                'currency_code' => $validated['currency_code'],
                'scan_file_path' => $path,
            ]);

            $contract->equipment()->sync($validated['machineIds']);

            foreach ($sorted as $d) {
                $contract->serviceDates()->create(['due_on' => $d]);
            }

            return $contract;
        });

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
                Contract <strong>{{ $created->reference }}</strong> created for {{ $created->customer->name }}, with {{ $created->serviceDates()->count() }} planned service {{ \Illuminate\Support\Str::plural('date', $created->serviceDates()->count()) }}.
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
                    @error('customer_id') <p class="field-error">{{ $message }}</p> @enderror
                </div>

                <div class="mb-3 grid grid-cols-2 gap-4">
                    <div>
                        <label class="label">Contract type</label>
                        <select wire:model="type" class="input">
                            @foreach ($types as $t)
                                <option>{{ $t }}</option>
                            @endforeach
                        </select>
                        @error('type') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label">Maintenance frequency</label>
                        <select wire:model.live="frequency" class="input">
                            <option value="">No fixed schedule, visits on call</option>
                            @foreach ($frequencies as $name => $f)
                                <option value="{{ $name }}">{{ $name }} (every {{ $f['every'] }} {{ \App\Support\ServiceSchedule::unitName($f['unit'], $f['every']) }})</option>
                            @endforeach
                        </select>
                        @error('frequency') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="mb-3 grid grid-cols-2 gap-4">
                    <div>
                        <label class="label">Start date</label>
                        <input wire:model.live="starts_at" type="date" class="input">
                        @error('starts_at') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label">End date</label>
                        <input wire:model.live="ends_at" type="date" class="input">
                        @error('ends_at') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="label">Currency</label>
                            <select wire:model="currency_code" class="input">
                                @foreach ($this->currencyChoices() as $code)
                                    <option>{{ $code }}</option>
                                @endforeach
                            </select>
                            @error('currency_code') <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="label">Value (optional)</label>
                            <input wire:model="value" type="number" step="0.01" min="0" class="input">
                            @error('value') <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    @if ($frequency === '')
                        <div>
                            <label class="label">Visits included</label>
                            <input wire:model="visits_included" type="number" min="0" class="input">
                            @error('visits_included') <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                    @endif
                </div>
            </div>

            <div class="card">
                <h2 class="mb-1 text-sm font-semibold text-neutral-900">Machines covered</h2>
                <p class="mb-3 text-xs text-neutral-500">The contract is linked to each machine ticked here. It will show on the machine's page.</p>
                @if (! $customer_id)
                    <p class="text-sm text-neutral-500">Choose the customer first.</p>
                @elseif ($machines->isEmpty())
                    <p class="text-sm text-neutral-500">This customer has no registered machines yet. Register them first, then come back.</p>
                @else
                    <div class="grid gap-2" style="grid-template-columns: repeat(auto-fit, minmax(230px, 1fr))">
                        @foreach ($machines as $m)
                            <label class="flex items-center gap-2 rounded-[var(--radius-md)] border border-neutral-200 px-3 py-2 text-sm" wire:key="m-{{ $m->id }}" style="cursor: pointer">
                                <input type="checkbox" wire:model="machineIds" value="{{ $m->id }}" style="accent-color: #e31e24">
                                <span>{{ $m->model }} <span class="font-mono text-xs text-neutral-500">{{ $m->serial_number }}</span></span>
                            </label>
                        @endforeach
                    </div>
                @endif
                @error('machineIds') <p class="field-error">{{ $message }}</p> @enderror
                @error('machineIds.*') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            @if ($frequency !== '')
                <div class="card">
                    <div class="mb-1 flex items-center justify-between">
                        <h2 class="text-sm font-semibold text-neutral-900">Service dates ({{ count($dates) }})</h2>
                        <button type="button" wire:click="addDate" class="text-xs font-medium text-primary-600 hover:text-primary-700">+ Add a date</button>
                    </div>
                    <p class="mb-3 text-xs text-neutral-500">Made from the frequency and the start and end dates. Change, add or remove any date. Changing the frequency or the contract dates makes the list again.</p>
                    @if (empty($dates))
                        <p class="text-sm text-neutral-500">Enter the start and end dates and the service dates appear here.</p>
                    @endif
                    <div class="grid gap-2" style="grid-template-columns: repeat(auto-fill, minmax(190px, 1fr))">
                        @foreach ($dates as $i => $d)
                            <div class="flex items-center gap-1" wire:key="d-{{ $i }}">
                                <input wire:model="dates.{{ $i }}" type="date" class="input">
                                <button type="button" wire:click="removeDate({{ $i }})" class="px-2 text-neutral-400 hover:text-critical-700" aria-label="Remove date">&times;</button>
                            </div>
                        @endforeach
                    </div>
                    @error('dates') <p class="field-error">{{ $message }}</p> @enderror
                    @foreach ($dates as $i => $d)
                        @error("dates.$i") <p class="field-error">{{ $message }}</p> @enderror
                    @endforeach
                </div>
            @endif

            <div class="card">
                <label class="label">Signed contract (PDF or image)</label>
                <input wire:model="scan" type="file" accept=".pdf,.jpg,.jpeg,.png" class="input">
                @error('scan') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            <button type="submit" wire:loading.attr="disabled" wire:target="submit,scan" class="btn-primary w-full">
                Create contract
            </button>
        </form>
    @endif
</div>
