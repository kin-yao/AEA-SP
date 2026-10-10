<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Models\Quotation;
use App\Models\Customer;
use App\Services\WorkflowNotifier;

new #[Layout('layouts.app', ['title' => 'New quotation'])] class extends Component
{
    public string $customerId = '';
    public string $siteId = '';
    public string $scope = '';
    public string $labour = '';
    public string $validityDays = '';
    public array $items = [];
    #[\Livewire\Attributes\Locked]
    public ?int $requestId = null;
    public string $currency = '';
    public string $revising = '';
    public string $revisionNote = '';

    public function mount(): void
    {
        $this->authorize('create', Quotation::class);
        $this->validityDays = (string) setting('quotation_validity_days');
        $this->items = [['description' => '', 'quantity' => 1, 'rate' => '']];

        // Correcting a rejected quotation: start from its lines so only the problem needs fixing.
        $revise = (int) request()->query('revise');
        $rejected = $revise ? Quotation::with('items')->find($revise) : null;
        if ($rejected && $rejected->status === 'Rejected' && auth()->user()->can('view', $rejected)) {
            $this->customerId = (string) $rejected->customer_id;
            $this->siteId = (string) ($rejected->customer_site_id ?? '');
            $this->scope = $rejected->scope;
            $this->currency = (string) $rejected->currency_code;
            $this->labour = $rejected->labour_minor ? number_format($rejected->labour_minor / 100, 2, '.', '') : '';
            $this->requestId = $rejected->source_service_request_id;
            $this->items = $rejected->items->map(fn ($i) => [
                'description' => $i->description,
                'quantity' => $i->quantity,
                'rate' => number_format($i->rate_minor / 100, 2, '.', ''),
            ])->all() ?: $this->items;
            $this->revising = $rejected->reference;
            $this->revisionNote = (string) $rejected->rejection_reason;

            return;
        }

        // Coming from a service request: carry its details over so nothing is typed twice.
        $rid = (int) request()->query('request');
        $source = $rid ? \App\Models\ServiceRequest::with('quotation')->find($rid) : null;
        if ($source && auth()->user()->can('view', $source) && ! $source->quotation) {
            $this->requestId = $source->id;
            $this->customerId = (string) $source->customer_id;
            $this->siteId = (string) ($source->customer_site_id ?? '');
            $this->scope = $source->fault_description;
            $this->currency = $source->customer?->currencyCode() ?? '';
        }
    }

    protected function customerRecord(): ?Customer
    {
        return $this->customerId ? Customer::find($this->customerId) : null;
    }

    public function getCurrencyCodeProperty(): string
    {
        return $this->currency !== '' ? $this->currency : ($this->customerRecord()?->currencyCode() ?? currency());
    }

    // Picking a customer proposes their usual currency. It can still be changed.
    public function updatedCustomerId(): void
    {
        $this->currency = $this->customerRecord()?->currencyCode() ?? '';
        $this->siteId = '';
    }

    public function getCurrenciesProperty(): array
    {
        return \App\Models\Currency::codes();
    }

    public function getVatFractionProperty(): float
    {
        return round(($this->customerRecord()?->vatPercent() ?? (float) setting('vat_rate')) / 100, 3);
    }

    public function getApprovalLimitProperty(): float
    {
        return $this->customerRecord()?->approvalLimit() ?? (float) setting('approval_threshold');
    }

    public function getSitesProperty()
    {
        if (! $this->customerId) {
            return collect();
        }

        return Customer::find($this->customerId)?->sites ?? collect();
    }

    public function addItem(): void
    {
        $this->items[] = ['description' => '', 'quantity' => 1, 'rate' => ''];
    }

    public function removeItem(int $index): void
    {
        unset($this->items[$index]);
        $this->items = array_values($this->items);

        if (empty($this->items)) {
            $this->items = [['description' => '', 'quantity' => 1, 'rate' => '']];
        }
    }

    // Live totals as the form's being filled in, same VAT math the model
    // itself uses, computed here from raw input before anything's saved.
    public function getSubtotalProperty(): float
    {
        $itemsTotal = collect($this->items)->sum(fn ($item) => (float) ($item['quantity'] ?: 0) * (float) ($item['rate'] ?: 0));

        return $itemsTotal + (float) ($this->labour ?: 0);
    }

    public function getVatProperty(): float
    {
        return round($this->subtotal * $this->vatFraction, 2);
    }

    public function getTotalProperty(): float
    {
        return $this->subtotal + $this->vat;
    }

    public function submit(): void
    {
        if ($this->currency === '') {
            $this->currency = $this->currencyCode;
        }

        $this->validate([
            'customerId' => ['required', 'exists:customers,id'],
            'siteId' => ['nullable', \Illuminate\Validation\Rule::exists('customer_sites', 'id')->where('customer_id', $this->customerId)],
            'currency' => ['required', \Illuminate\Validation\Rule::in(\App\Models\Currency::codes())],
            'scope' => ['required', 'string', 'min:5', 'max:5000'],
            'labour' => \App\Support\Rules::money(false),
            'validityDays' => ['required', 'integer', 'min:1', 'max:365'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.description' => ['required_with:items.*.rate', 'nullable', 'string', 'max:500'],
            'items.*.quantity' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'items.*.rate' => \App\Support\Rules::money(false),
        ], [
            'items.*.quantity.integer' => 'Quantity must be a whole number.',
            'items.*.description.required_with' => 'Describe this item or clear its price.',
        ]);

        if (collect($this->items)->every(fn ($i) => blank($i['description'] ?? null))) {
            $this->addError('items.0.description', 'Add at least one item with a description.');

            return;
        }

        if ($this->total * 100 > \App\Support\Rules::MAX_TOTAL_MINOR) {
            $this->addError('items', 'The quotation total is too large. Keep it under '.number_format(\App\Support\Rules::MAX_TOTAL_MINOR / 100, 2).' or split it into more than one quotation.');

            return;
        }

        // The link to a service request only holds while the quotation is for that request's customer,
        // and the request has no live quotation yet (a rejected one can be replaced).
        if ($this->requestId) {
            $linked = \App\Models\ServiceRequest::with('quotation')->find($this->requestId);

            if (! $linked || $linked->customer_id !== (int) $this->customerId || ($linked->quotation && $linked->quotation->status !== 'Rejected')) {
                $this->requestId = null;
            }
        }

        $quotation = Quotation::create([
            'reference' => \App\Models\ReferenceSeries::next('quotation'),
            'customer_id' => $this->customerId,
            'customer_site_id' => $this->siteId ?: null,
            'source_service_request_id' => $this->requestId,
            'scope' => $this->scope,
            'labour_minor' => (int) round(((float) ($this->labour ?: 0)) * 100),
            'validity_days' => $this->validityDays,
            'currency_code' => $this->currencyCode,
            'vat_rate' => $this->vatFraction,
            'created_by' => auth()->id(),
        ]);

        foreach ($this->items as $item) {
            if (blank($item['description'])) {
                continue;
            }

            $quotation->items()->create([
                'description' => $item['description'],
                'quantity' => (int) ($item['quantity'] ?: 1),
                'rate_minor' => (int) round(((float) ($item['rate'] ?: 0)) * 100),
            ]);
        }

        if ($this->requestId) {
            \App\Models\ServiceRequest::whereKey($this->requestId)->where('status', 'Open')->update(['status' => 'Quoted']);
        }

        $quotation->load(['items', 'customer']);
        $quotation->routeApproval();
        $quotation->save();

        // approval_threshold is 'Manager' or 'Supervisor', the same string
        // as the role that needs to approve it.
        WorkflowNotifier::role(
            $quotation->approval_threshold,
            'Quotation awaiting your approval',
            [
                "Quotation {$quotation->reference} for {$quotation->customer->name} (".$quotation->currency_code." ".number_format($quotation->totalMinor() / 100, 2).') needs your approval.',
            ],
            url("/quotations/{$quotation->id}"),
            'Review quotation',
        );

        $this->redirect('/quotations/'.$quotation->id, navigate: true);
    }
};
?>

<div>
    <a href="/quotations" wire:navigate class="mb-4 inline-flex items-center gap-1.5 text-sm text-neutral-500 hover:text-neutral-900">
        <x-icon name="arrow-right" class="h-3.5 w-3.5 rotate-180" />
        Back to quotations
    </a>

    <h1 class="mb-2 text-xl font-semibold text-neutral-900">New quotation</h1>
    @if ($revising)
        <div class="mb-4 rounded-[var(--radius-md)] border px-4 py-3 text-sm" style="background: #fef2f2; border-color: #fecaca; color: #7f1d1d">
            Correcting <strong>{{ $revising }}</strong>, which was rejected. Reason given: {{ $revisionNote ?: 'none recorded' }}
        </div>
    @elseif ($requestId)
        <p class="mb-6 text-sm text-neutral-500">Pre-filled from the service request. Check the details, add the prices and submit.</p>
    @else
        <div class="mb-4"></div>
    @endif

    <form wire:submit="submit" class="space-y-4">
        <div class="card">
            <div class="mb-3 grid grid-cols-2 gap-4">
                <div>
                    <label class="label">Customer</label>
                    <select wire:model.live="customerId" class="input">
                        <option value="">Select a customer</option>
                        @foreach (\App\Models\Customer::orderBy('name')->get() as $customer)
                            <option value="{{ $customer->id }}">{{ $customer->name }}</option>
                        @endforeach
                    </select>
                    @error('customerId') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label">Site</label>
                    <select wire:model="siteId" class="input">
                        <option value="">No specific site</option>
                        @foreach ($this->sites as $site)
                            <option value="{{ $site->id }}">{{ $site->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="mb-3">
                <label class="label">Currency</label>
                <select wire:model.live="currency" class="input">
                    <option value="">{{ $this->customerId ? 'Customer default ('.$this->currencyCode.')' : 'Pick a customer first, or choose here' }}</option>
                    @foreach ($this->currencies as $code)
                        <option value="{{ $code }}">{{ $code }}</option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-neutral-400">Prices, totals, the PDF and the invoice all use this currency.</p>
                @error('currency') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="label">Scope of work</label>
                <input wire:model="scope" type="text" placeholder="e.g. Weighbridge load cell replacement" class="input">
                @error('scope') <p class="field-error">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="card">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="text-sm font-semibold text-neutral-900">Line items</h2>
                <button type="button" wire:click="addItem" class="text-xs font-medium text-primary-600 hover:text-primary-700">
                    Add item
                </button>
            </div>

            @foreach ($items as $index => $item)
                <div class="mb-2 flex items-center gap-2">
                    <input wire:model="items.{{ $index }}.description" type="text" placeholder="Description" class="input flex-[2]">
                    <input wire:model.live="items.{{ $index }}.quantity" type="number" min="1" placeholder="Qty" class="input w-16">
                    <input wire:model.live="items.{{ $index }}.rate" type="text" inputmode="decimal" placeholder="Rate, {{ $this->currencyCode }}" class="input w-28">
                    @if (count($items) > 1)
                        <button type="button" wire:click="removeItem({{ $index }})" class="shrink-0 text-neutral-400 hover:text-critical-700">
                            &times;
                        </button>
                    @endif
                </div>
                @foreach (['description', 'quantity', 'rate'] as $col)
                    @error("items.$index.$col") <p class="field-error" data-for="items.{{ $index }}.{{ $col }}" style="margin:-0.25rem 0 0.5rem">{{ $message }}</p> @enderror
                @endforeach
            @endforeach

            @error('items') <p class="field-error">{{ $message }}</p> @enderror

            <div class="mt-4 grid grid-cols-2 gap-4 border-t border-neutral-100 pt-4">
                <div>
                    <label class="label">Labour, {{ $this->currencyCode }}</label>
                    <input wire:model.live="labour" type="text" inputmode="decimal" placeholder="0.00" class="input">
                    @error('labour') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label">Validity, days</label>
                    <input wire:model="validityDays" type="number" min="1" class="input">
                    @error('validityDays') <p class="field-error">{{ $message }}</p> @enderror
                </div>
            </div>
        </div>

        <div class="card">
            <div class="flex justify-between py-1 text-sm">
                <span class="text-neutral-500">Subtotal</span>
                <span class="text-neutral-900">{{ $this->currencyCode }} {{ number_format($this->subtotal, 2) }}</span>
            </div>
            <div class="flex justify-between py-1 text-sm">
                <span class="text-neutral-500">VAT, {{ round($this->vatFraction * 100, 2) }}%</span>
                <span class="text-neutral-900">{{ $this->currencyCode }} {{ number_format($this->vat, 2) }}</span>
            </div>
            <div class="mt-1 flex justify-between border-t border-neutral-100 py-2 text-sm font-semibold">
                <span class="text-neutral-900">Total</span>
                <span class="text-neutral-900">{{ $this->currencyCode }} {{ number_format($this->total, 2) }}</span>
            </div>
            <p class="mt-2 text-xs text-neutral-500">
                {{ $this->total >= $this->approvalLimit ? 'At or above '.$this->currencyCode.' '.number_format($this->approvalLimit).', this will route to the Manager for approval.' : 'Under '.$this->currencyCode.' '.number_format($this->approvalLimit).', this will route to the Supervisor for approval.' }}
            </p>
        </div>

        <button type="submit" wire:loading.attr="disabled" wire:target="submit" class="btn-primary w-full">
            Create quotation
        </button>
    </form>
</div>
