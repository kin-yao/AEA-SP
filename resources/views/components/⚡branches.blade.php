<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Models\Branch;
use App\Models\Country;
use App\Models\Currency;
use Illuminate\Validation\Rule;

new #[Layout('layouts.app', ['title' => 'Branches and Country'])] class extends Component
{
    // Country form
    public bool $showCountry = false;
    public ?int $countryId = null;
    public string $countryName = '';
    public string $currency = '';
    public string $vat = '';
    public string $approval = '';

    // Branch form
    public bool $showBranch = false;
    public ?int $branchId = null;
    public ?int $branchCountry = null;
    public string $branchName = '';

    public ?string $notice = null;
    public ?string $problem = null;

    public function mount(): void
    {
        abort_unless(auth()->user()->hasAnyRole(['ICT', 'Super Admin']), 403);
    }

    // ---- Countries
    public function newCountry(): void
    {
        $this->reset(['countryId', 'countryName', 'currency', 'vat', 'approval', 'notice', 'problem']);
        $this->resetValidation();
        $this->showCountry = true;
    }

    public function editCountry(int $id): void
    {
        $c = Country::findOrFail($id);
        $this->reset(['notice', 'problem']);
        $this->resetValidation();
        $this->countryId = $c->id;
        $this->countryName = $c->name;
        $this->currency = $c->currency_code;
        $this->vat = $c->vat_rate !== null ? rtrim(rtrim((string) $c->vat_rate, '0'), '.') : '';
        $this->approval = $c->approval_threshold !== null ? rtrim(rtrim((string) $c->approval_threshold, '0'), '.') : '';
        $this->showCountry = true;
    }

    public function saveCountry(): void
    {
        $this->currency = strtoupper(trim($this->currency));
        $this->countryName = trim($this->countryName);

        $this->validate([
            'countryName' => ['required', 'string', 'max:100', Rule::unique('countries', 'name')->ignore($this->countryId)],
            'currency' => ['required', Rule::in(Currency::codes())],
            'vat' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'approval' => ['nullable', 'numeric', 'min:0', 'max:100000000000'],
        ], [
            'countryName.required' => 'Enter the country name.',
            'countryName.unique' => 'That country already exists.',
            'currency.required' => 'Enter the 3 letter currency code, for example KES.',
            'currency.in' => 'Pick a currency from the list. Add new ones under ICT, System settings, Money and tax.',
        ]);

        $data = [
            'name' => $this->countryName,
            'currency_code' => $this->currency,
            'vat_rate' => $this->vat !== '' ? $this->vat : null,
            'approval_threshold' => $this->approval !== '' ? $this->approval : null,
        ];
        $this->countryId ? Country::findOrFail($this->countryId)->update($data) : Country::create($data);

        $this->notice = $this->countryId ? 'Country updated.' : 'Country added.';
        $this->showCountry = false;
    }

    public function deleteCountry(int $id): void
    {
        $c = Country::withCount('branches')->findOrFail($id);
        $this->reset(['notice', 'problem']);

        if ($c->branches_count > 0) {
            $this->problem = "{$c->name} still has branches. Remove or move them first.";

            return;
        }

        $c->delete();
        $this->notice = "{$c->name} removed.";
    }

    // ---- Branches
    public function newBranch(?int $countryId = null): void
    {
        $this->reset(['branchId', 'branchName', 'notice', 'problem']);
        $this->resetValidation();
        $this->branchCountry = $countryId ?? Country::orderBy('name')->value('id');
        $this->showBranch = true;
    }

    public function editBranch(int $id): void
    {
        $b = Branch::findOrFail($id);
        $this->reset(['notice', 'problem']);
        $this->resetValidation();
        $this->branchId = $b->id;
        $this->branchCountry = $b->country_id;
        $this->branchName = $b->name;
        $this->showBranch = true;
    }

    public function saveBranch(): void
    {
        $this->branchName = trim($this->branchName);

        $this->validate([
            'branchCountry' => ['required', 'exists:countries,id'],
            'branchName' => ['required', 'string', 'max:100', Rule::unique('branches', 'name')->where('country_id', $this->branchCountry)->ignore($this->branchId)],
        ], [
            'branchCountry.required' => 'Choose the country.',
            'branchName.required' => 'Enter the branch name.',
            'branchName.unique' => 'That country already has a branch with this name.',
        ]);

        $data = ['country_id' => $this->branchCountry, 'name' => $this->branchName];
        $this->branchId ? Branch::findOrFail($this->branchId)->update($data) : Branch::create($data);

        $this->notice = $this->branchId ? 'Branch updated.' : 'Branch added.';
        $this->showBranch = false;
    }

    public function deleteBranch(int $id): void
    {
        $b = Branch::withCount(['users', 'customers', 'inventoryItems'])->findOrFail($id);
        $this->reset(['notice', 'problem']);

        if ($b->users_count + $b->customers_count + $b->inventory_items_count > 0) {
            $this->problem = "{$b->name} is in use by {$b->users_count} staff, {$b->customers_count} customers and {$b->inventory_items_count} stock items. It cannot be removed.";

            return;
        }

        $b->delete();
        $this->notice = "{$b->name} removed.";
    }

    public function closeForms(): void
    {
        $this->showCountry = false;
        $this->showBranch = false;
        $this->resetValidation();
    }

    public function with(): array
    {
        return [
            'currencies' => Currency::orderBy('code')->get(),
            'countries' => Country::withCount('branches')->orderBy('name')->get(),
            'branches' => Branch::with('country')->withCount(['users', 'customers', 'inventoryItems'])->get()->sortBy(fn ($b) => $b->country->name.' '.$b->name),
        ];
    }
};
?>

<div class="mx-auto" style="max-width: 72rem">
    <div class="mb-5">
        <h1 class="text-xl font-semibold text-neutral-900">Branches and Country</h1>
        <p class="text-sm text-neutral-500">Countries and their branches.</p>
    </div>

    @if ($notice)
        <div class="mb-4 rounded-lg border border-success-200 bg-success-50 px-4 py-3 text-sm text-success-700" role="status">{{ $notice }}</div>
    @endif
    @if ($problem)
        <div class="mb-4 rounded-lg border border-critical-200 bg-critical-50 px-4 py-3 text-sm text-critical-700" role="alert">{{ $problem }}</div>
    @endif

    {{-- Country form --}}
    @if ($showCountry)
        <div style="position: fixed; inset: 0; z-index: 60; display: flex; align-items: center; justify-content: center; padding: 1rem; background: rgba(0, 0, 0, 0.45)" wire:click.self="closeForms">
            <form wire:submit="saveCountry" class="card" style="width: 100%; max-width: 26rem">
                <h2 class="text-base font-semibold text-neutral-900">{{ $countryId ? 'Edit country' : 'Add a country' }}</h2>
                <div class="mt-4">
                    <label class="label" for="cn">Country</label>
                    <input id="cn" type="text" wire:model="countryName" class="input" style="font-size: 16px">
                    @error('countryName') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div class="mt-3">
                    <label class="label" for="cc">Currency code</label>
                    <select id="cc" wire:model="currency" class="input" style="font-size: 16px">
                        <option value="">Choose a currency</option>
                        @foreach ($currencies as $cur)
                            <option value="{{ $cur->code }}">{{ $cur->code }} - {{ $cur->name }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-neutral-400">Add new ones under System settings.</p>
                    <p class="mt-1 text-xs text-neutral-400">Used for all billing in this country.</p>
                    @error('currency') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div class="mt-3">
                    <label class="label" for="cv">VAT rate (%)</label>
                    <input id="cv" type="number" step="any" wire:model="vat" class="input" style="font-size: 16px" placeholder="Leave blank for the default">
                    @error('vat') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div class="mt-3">
                    <label class="label" for="ca">Manager approval from</label>
                    <input id="ca" type="number" step="any" wire:model="approval" class="input" style="font-size: 16px" placeholder="Leave blank for the default">
                    <p class="mt-1 text-xs text-neutral-400">Totals from here need a Manager.</p>
                    @error('approval') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div class="mt-5 flex gap-2">
                    <button type="submit" class="btn-primary">Save</button>
                    <button type="button" wire:click="closeForms" class="btn-outline">Cancel</button>
                </div>
            </form>
        </div>
    @endif

    {{-- Branch form --}}
    @if ($showBranch)
        <div style="position: fixed; inset: 0; z-index: 60; display: flex; align-items: center; justify-content: center; padding: 1rem; background: rgba(0, 0, 0, 0.45)" wire:click.self="closeForms">
            <form wire:submit="saveBranch" class="card" style="width: 100%; max-width: 26rem">
                <h2 class="text-base font-semibold text-neutral-900">{{ $branchId ? 'Edit branch' : 'Add a branch' }}</h2>
                <div class="mt-4">
                    <label class="label" for="bc">Country</label>
                    <select id="bc" wire:model="branchCountry" class="input" style="font-size: 16px">
                        @foreach ($countries as $c)
                            <option value="{{ $c->id }}">{{ $c->name }}</option>
                        @endforeach
                    </select>
                    @error('branchCountry') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div class="mt-3">
                    <label class="label" for="bn">Branch name</label>
                    <input id="bn" type="text" wire:model="branchName" class="input" style="font-size: 16px">
                    @error('branchName') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div class="mt-5 flex gap-2">
                    <button type="submit" class="btn-primary">Save</button>
                    <button type="button" wire:click="closeForms" class="btn-outline">Cancel</button>
                </div>
            </form>
        </div>
    @endif

    <div class="card mb-6" style="padding: 0">
        <div class="flex items-center justify-between gap-3" style="padding: 1.25rem 1.25rem 0">
            <h2 class="text-sm font-semibold text-neutral-900">Countries</h2>
            <button type="button" wire:click="newCountry" class="btn-primary" style="padding: 0.35rem 0.85rem">Add country</button>
        </div>
        <div class="mt-3 overflow-x-auto">
            <table class="table-clean" style="min-width: 480px">
                <thead><tr><th>Country</th><th>Currency</th><th>VAT</th><th>Manager approval from</th><th>Branches</th><th></th></tr></thead>
                <tbody>
                    @forelse ($countries as $c)
                        <tr wire:key="co-{{ $c->id }}">
                            <td class="font-semibold text-neutral-900">{{ $c->name }}</td>
                            <td class="font-mono">{{ $c->currency_code }}</td>
                            <td>{{ $c->vat_rate !== null ? rtrim(rtrim((string) $c->vat_rate, '0'), '.').'%' : 'Default' }}</td>
                            <td class="font-mono text-xs">{{ $c->approval_threshold !== null ? $c->currency_code.' '.number_format($c->approval_threshold, 0) : 'Default' }}</td>
                            <td>{{ $c->branches_count }}</td>
                            <td class="whitespace-nowrap text-right">
                                <button type="button" wire:click="editCountry({{ $c->id }})" class="btn-outline" style="padding: 0.3rem 0.75rem">Edit</button>
                                <button type="button" wire:click="deleteCountry({{ $c->id }})" wire:confirm="Remove {{ $c->name }}?" class="btn-outline" style="padding: 0.3rem 0.75rem">Remove</button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-neutral-500">No countries yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="card" style="padding: 0">
        <div class="flex items-center justify-between gap-3" style="padding: 1.25rem 1.25rem 0">
            <h2 class="text-sm font-semibold text-neutral-900">Branches</h2>
            <button type="button" wire:click="newBranch" class="btn-primary" style="padding: 0.35rem 0.85rem" @disabled($countries->isEmpty())>Add branch</button>
        </div>
        <div class="mt-3 overflow-x-auto">
            <table class="table-clean" style="min-width: 640px">
                <thead><tr><th>Branch</th><th>Country</th><th>Staff</th><th>Customers</th><th>Stock items</th><th></th></tr></thead>
                <tbody>
                    @forelse ($branches as $b)
                        <tr wire:key="br-{{ $b->id }}">
                            <td class="font-semibold text-neutral-900">{{ $b->name }}</td>
                            <td>{{ $b->country->name }}</td>
                            <td>{{ $b->users_count }}</td>
                            <td>{{ $b->customers_count }}</td>
                            <td>{{ $b->inventory_items_count }}</td>
                            <td class="whitespace-nowrap text-right">
                                <button type="button" wire:click="editBranch({{ $b->id }})" class="btn-outline" style="padding: 0.3rem 0.75rem">Edit</button>
                                <button type="button" wire:click="deleteBranch({{ $b->id }})" wire:confirm="Remove {{ $b->name }}?" class="btn-outline" style="padding: 0.3rem 0.75rem">Remove</button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-neutral-500">No branches yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
