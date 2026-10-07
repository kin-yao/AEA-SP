<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Services\Audit;
use App\Support\Settings;
use App\Models\BankAccount;
use App\Models\Currency;
use App\Models\Country;
use App\Models\ReferenceSeries;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

new #[Layout('layouts.app', ['title' => 'System settings'])] class extends Component
{
    public string $tab = 'company';
    public array $values = [];
    public ?string $notice = null;

    // Bank account form
    public bool $showBank = false;
    public ?int $bankId = null;
    public string $bCountry = '';
    public string $bCurrency = '';
    public string $bBank = '';
    public string $bName = '';
    public string $bNumber = '';
    public string $bBranch = '';
    public string $bSwift = '';
    public bool $bActive = true;

    // Currency form
    public bool $showCur = false;
    public ?int $curId = null;
    public string $cuCode = '';
    public string $cuName = '';

    // Reference series form
    public bool $showSeries = false;
    public ?int $seriesId = null;
    public string $sLabel = '';
    public string $sPrefix = '';
    public string $sDigits = '4';
    public string $sNext = '1';

    public function mount(): void
    {
        abort_unless(auth()->user()->hasAnyRole(['ICT', 'Super Admin']), 403);
        $this->load();
    }

    protected function load(): void
    {
        $this->values = [];
        foreach (Settings::definitions() as $keys) {
            foreach ($keys as $key => $meta) {
                $this->values[$key] = Settings::raw($key);
            }
        }
    }

    public function show(string $tab): void
    {
        abort_unless(array_key_exists($tab, Settings::groups()), 404);
        $this->tab = $tab;
        $this->showBank = false;
        $this->showSeries = false;
        $this->showCur = false;
        $this->notice = null;
        $this->resetValidation();
        $this->load();
    }

    protected function rulesFor(string $key, array $meta): array
    {
        $rules = match ($meta[1]) {
            'number' => ['required', 'integer', 'min:0', 'max:100000'],
            'percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'money' => ['required', 'numeric', 'min:0', 'max:100000000000'],
            'list' => ['required', 'string', 'max:2000'],
            'timezone' => ['required', Rule::in(timezone_identifiers_list())],
            'textarea' => ['nullable', 'string', 'max:1000'],
            default => ['nullable', 'string', 'max:255'],
        };

        return match (true) {
            $key === 'currency' => ['required', Rule::in(Currency::codes())],
            str_starts_with($key, 'ref_') && $key !== 'ref_digits' => ['required', 'alpha_num', 'max:8'],
            $key === 'ref_digits' => ['required', 'integer', 'min:1', 'max:8'],
            $key === 'password_min' => ['required', 'integer', 'min:6', 'max:64'],
            in_array($key, ['invoice_due_days', 'quotation_validity_days'], true) => ['required', 'integer', 'min:1', 'max:3650'],
            in_array($key, ['stage_warn_pct', 'stage_urgent_pct', 'response_target_pct'], true) => ['required', 'integer', 'min:1', 'max:100'],
            $key === 'response_target_hours' => ['required', 'integer', 'min:1', 'max:720'],
            default => $rules,
        };
    }

    public function save(): void
    {
        abort_if(Settings::customTab($this->tab), 404);

        $defs = Settings::definitions()[$this->tab];

        $rules = [];
        $names = [];
        foreach ($defs as $key => $meta) {
            $rules['values.'.$key] = $this->rulesFor($key, $meta);
            $names['values.'.$key] = strtolower($meta[0]);
        }

        $this->validate($rules, [], $names);

        if ($this->tab === 'alerts' && (int) $this->values['stage_urgent_pct'] <= (int) $this->values['stage_warn_pct']) {
            $this->addError('values.stage_urgent_pct', 'The second warning must come after the first.');

            return;
        }

        $changes = [];
        foreach ($defs as $key => $meta) {
            $new = trim((string) $this->values[$key]);

            if ($meta[1] === 'list') {
                $new = implode("\n", Settings::lines($new));
            }
            if ($key === 'currency' || (str_starts_with($key, 'ref_') && $key !== 'ref_digits')) {
                $new = strtoupper($new);
            }

            $old = Settings::raw($key);
            if ($new === $old) {
                continue;
            }

            // Back to the built-in default means no override is needed.
            Settings::set($key, $new === (string) $meta[2] ? null : $new, auth()->id());
            $changes[$meta[0]] = [mb_substr(str_replace("\n", ', ', $old), 0, 120), mb_substr(str_replace("\n", ', ', $new), 0, 120)];
        }

        if ($changes) {
            Audit::record('updated', 'Changed system settings: '.Settings::groups()[$this->tab][0], null, $changes);
            $this->notice = 'Saved. The change applies straight away.';
        } else {
            $this->notice = 'Nothing to save, these are already the current values.';
        }

        $this->load();
    }

    public function reset_to_default(string $key): void
    {
        abort_unless(Settings::meta($key) !== null, 404);

        $old = Settings::raw($key);
        Settings::set($key, null, auth()->id());
        Audit::record('updated', 'Reset a system setting to its default: '.Settings::meta($key)[0], null, [Settings::meta($key)[0] => [mb_substr(str_replace("\n", ', ', $old), 0, 120), mb_substr(str_replace("\n", ', ', Settings::raw($key)), 0, 120)]]);
        $this->notice = 'Back to the default.';
        $this->load();
    }


    // ---- Bank accounts
    public function newBank(): void
    {
        $this->reset(['bankId', 'bCountry', 'bBank', 'bName', 'bNumber', 'bBranch', 'bSwift']);
        $this->bCurrency = Settings::currency();
        $this->bName = (string) Settings::get('company_name');
        $this->bActive = true;
        $this->resetValidation();
        $this->showBank = true;
    }

    public function editBank(int $id): void
    {
        $b = BankAccount::findOrFail($id);
        $this->resetValidation();
        $this->bankId = $b->id;
        $this->bCountry = (string) ($b->country_id ?? '');
        $this->bCurrency = $b->currency_code;
        $this->bBank = $b->bank_name;
        $this->bName = $b->account_name;
        $this->bNumber = $b->account_number;
        $this->bBranch = (string) $b->branch;
        $this->bSwift = (string) $b->swift;
        $this->bActive = $b->active;
        $this->showBank = true;
    }

    public function saveBank(): void
    {
        $this->bCurrency = strtoupper(trim($this->bCurrency));

        $v = $this->validate([
            'bCountry' => ['nullable', 'exists:countries,id'],
            'bCurrency' => ['required', Rule::in(Currency::codes())],
            'bBank' => ['required', 'string', 'max:255'],
            'bName' => ['required', 'string', 'max:255'],
            'bNumber' => ['required', 'string', 'max:255'],
            'bBranch' => ['nullable', 'string', 'max:255'],
            'bSwift' => ['nullable', 'string', 'max:255'],
        ], [], [
            'bCurrency' => 'currency', 'bBank' => 'bank', 'bName' => 'account name', 'bNumber' => 'account number',
        ]);

        $data = [
            'country_id' => $v['bCountry'] !== '' ? (int) $v['bCountry'] : null,
            'currency_code' => $v['bCurrency'],
            'bank_name' => trim($v['bBank']),
            'account_name' => trim($v['bName']),
            'account_number' => trim($v['bNumber']),
            'branch' => trim((string) $v['bBranch']) ?: null,
            'swift' => trim((string) $v['bSwift']) ?: null,
            'active' => $this->bActive,
        ];

        if ($this->bankId) {
            BankAccount::findOrFail($this->bankId)->update($data);
            Audit::record('updated', 'Changed bank account '.$data['bank_name'].' '.$data['account_number'], null);
        } else {
            BankAccount::create($data);
            Audit::record('created', 'Added bank account '.$data['bank_name'].' '.$data['account_number'], null);
        }

        $this->notice = 'Bank account saved.';
        $this->showBank = false;
    }

    public function deleteBank(int $id): void
    {
        $b = BankAccount::findOrFail($id);
        $b->delete();
        Audit::record('deleted', 'Removed bank account '.$b->bank_name.' '.$b->account_number, null);
        $this->notice = 'Bank account removed.';
    }

    // ---- Currencies
    public function newCurrency(): void
    {
        $this->reset(['curId', 'cuCode', 'cuName']);
        $this->resetValidation();
        $this->showCur = true;
    }

    public function editCurrency(int $id): void
    {
        $c = Currency::findOrFail($id);
        $this->resetValidation();
        $this->curId = $c->id;
        $this->cuCode = $c->code;
        $this->cuName = $c->name;
        $this->showCur = true;
    }

    public function saveCurrency(): void
    {
        $this->cuCode = strtoupper(trim($this->cuCode));
        $this->cuName = trim($this->cuName);

        $this->validate([
            'cuCode' => ['required', 'alpha', 'size:3', Rule::unique('currencies', 'code')->ignore($this->curId)],
            'cuName' => ['required', 'string', 'max:100'],
        ], [
            'cuCode.required' => 'Enter the 3 letter code, for example KES.',
            'cuCode.size' => 'The code is 3 letters, for example KES.',
            'cuCode.alpha' => 'The code is 3 letters, for example KES.',
            'cuCode.unique' => 'That currency is already on the list.',
            'cuName.required' => 'Enter the name, for example Kenya shilling.',
        ]);

        if ($this->curId) {
            $c = Currency::findOrFail($this->curId);
            $c->update(['name' => $this->cuName]);
            Audit::record('updated', 'Renamed currency '.$c->code.' to '.$this->cuName, null);
            $this->notice = 'Currency updated.';
        } else {
            Currency::create(['code' => $this->cuCode, 'name' => $this->cuName]);
            Audit::record('created', 'Added currency '.$this->cuCode.' '.$this->cuName, null);
            $this->notice = 'Currency added. You can now pick it for a country, a bank account or a contract.';
        }

        $this->showCur = false;
    }

    public function deleteCurrency(int $id): void
    {
        $c = Currency::findOrFail($id);
        $use = Currency::usage($c->code);

        if ($use['default'] || $use['countries'] || $use['banks'] || $use['records']) {
            $this->notice = null;
            $this->addError('currency_in_use', $c->code.' is still in use, so it cannot be removed.');

            return;
        }

        $c->delete();
        Audit::record('deleted', 'Removed currency '.$c->code.' '.$c->name, null);
        $this->notice = 'Currency removed.';
    }

    // ---- Reference series
    public function newSeries(): void
    {
        $this->reset(['seriesId', 'sLabel', 'sPrefix']);
        $this->sDigits = '4';
        $this->sNext = '1';
        $this->resetValidation();
        $this->showSeries = true;
    }

    public function editSeries(int $id): void
    {
        $r = ReferenceSeries::findOrFail($id);
        $this->resetValidation();
        $this->seriesId = $r->id;
        $this->sLabel = $r->label;
        $this->sPrefix = $r->prefix;
        $this->sDigits = (string) $r->digits;
        $this->sNext = (string) $r->next_number;
        $this->showSeries = true;
    }

    public function saveSeries(): void
    {
        $this->sPrefix = strtoupper(trim($this->sPrefix));
        $this->sLabel = trim($this->sLabel);

        $v = $this->validate([
            'sLabel' => ['required', 'string', 'max:100', Rule::unique('reference_series', 'label')->ignore($this->seriesId)],
            'sPrefix' => ['required', 'alpha_num', 'max:10'],
            'sDigits' => ['required', 'integer', 'min:1', 'max:10'],
            'sNext' => ['required', 'integer', 'min:1'],
        ], [
            'sLabel.unique' => 'There is already a series with that name.',
        ], [
            'sLabel' => 'name', 'sPrefix' => 'prefix', 'sDigits' => 'digits', 'sNext' => 'next number',
        ]);

        if ($this->seriesId) {
            $r = ReferenceSeries::findOrFail($this->seriesId);
            $old = $r->format();
            $r->update(['label' => $v['sLabel'], 'prefix' => $v['sPrefix'], 'digits' => (int) $v['sDigits'], 'next_number' => (int) $v['sNext']]);
            Audit::record('updated', 'Changed reference series '.$r->label, null, ['Next number' => [$old, $r->format()]]);
        } else {
            $key = Str::slug($v['sLabel'], '_');
            while (ReferenceSeries::where('key', $key)->exists()) {
                $key .= '_2';
            }
            $r = ReferenceSeries::create(['key' => $key, 'label' => $v['sLabel'], 'prefix' => $v['sPrefix'], 'digits' => (int) $v['sDigits'], 'next_number' => (int) $v['sNext'], 'is_system' => false]);
            Audit::record('created', 'Added reference series '.$r->label.' ('.$r->format().')', null);
        }

        $this->notice = 'Reference series saved.';
        $this->showSeries = false;
    }

    public function deleteSeries(int $id): void
    {
        $r = ReferenceSeries::findOrFail($id);
        abort_if($r->is_system, 403);
        $r->delete();
        Audit::record('deleted', 'Removed reference series '.$r->label, null);
        $this->notice = 'Reference series removed.';
    }

    public function closeForms(): void
    {
        $this->showBank = false;
        $this->showSeries = false;
        $this->showCur = false;
        $this->resetValidation();
    }

    public function with(): array
    {
        return [
            'groups' => Settings::groups(),
            'defs' => Settings::definitions()[$this->tab] ?? [],
            'zones' => timezone_identifiers_list(),
            'banks' => $this->tab === 'banks' ? BankAccount::with('country')->orderBy('id')->get() : collect(),
            'seriesList' => $this->tab === 'numbering' ? ReferenceSeries::orderByDesc('is_system')->orderBy('id')->get() : collect(),
            'countries' => Country::orderBy('name')->get(),
            'currencyCodes' => Currency::codes(),
            'currencyList' => $this->tab === 'finance' ? Currency::orderBy('code')->get() : collect(),
        ];
    }
};
?>

<div class="mx-auto" style="max-width: 60rem">
    <div class="mb-5">
        <h1 class="text-xl font-semibold text-neutral-900">System settings</h1>
        <p class="text-sm text-neutral-500">The business values the system uses everywhere. Change them here, no code needed.</p>
    </div>

    <div class="mb-4 flex flex-wrap gap-2">
        @foreach ($groups as $key => $g)
            <button type="button" wire:click="show('{{ $key }}')" class="{{ $tab === $key ? 'btn-dark' : 'btn-outline' }}" style="padding: 0.4rem 0.9rem">{{ $g[0] }}</button>
        @endforeach
    </div>

    @if ($notice)
        <div class="mb-4 rounded-lg border border-success-200 bg-success-50 px-4 py-3 text-sm text-success-700" role="status">{{ $notice }}</div>
    @endif

    @if ($tab === 'banks')
        <div class="card" style="padding: 0">
            <div class="flex flex-wrap items-center justify-between gap-3" style="padding: 1.25rem 1.25rem 0">
                <div>
                    <h2 class="text-base font-semibold text-neutral-900">{{ $groups[$tab][0] }}</h2>
                    <p class="text-sm text-neutral-500">Quotations and invoices print the active accounts that match their currency and the customer's country. If none match, every active account is shown.</p>
                </div>
                <button type="button" wire:click="newBank" class="btn-primary" style="padding: 0.4rem 0.9rem">Add bank account</button>
            </div>
            <div class="mt-3 overflow-x-auto">
                <table class="table-clean" style="min-width: 640px">
                    <thead><tr><th>Bank</th><th>Account</th><th>Currency</th><th>Country</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                        @forelse ($banks as $b)
                            <tr wire:key="bk-{{ $b->id }}">
                                <td class="font-semibold text-neutral-900">{{ $b->bank_name }}@if ($b->branch)<span class="block text-xs font-normal text-neutral-500">{{ $b->branch }}</span>@endif</td>
                                <td>{{ $b->account_name }}<span class="block font-mono text-xs text-neutral-500">{{ $b->account_number }}@if ($b->swift) , {{ $b->swift }}@endif</span></td>
                                <td class="font-mono">{{ $b->currency_code }}</td>
                                <td>{{ $b->country?->name ?? 'All countries' }}</td>
                                <td><span class="pill {{ $b->active ? 'pill-success' : 'pill-neutral' }}">{{ $b->active ? 'Active' : 'Off' }}</span></td>
                                <td class="whitespace-nowrap text-right">
                                    <button type="button" wire:click="editBank({{ $b->id }})" class="btn-outline" style="padding: 0.3rem 0.75rem">Edit</button>
                                    <button type="button" wire:click="deleteBank({{ $b->id }})" wire:confirm="Remove this bank account?" class="btn-outline" style="padding: 0.3rem 0.75rem">Remove</button>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center text-neutral-500">No bank accounts yet. Documents print "Bank details are not set up yet" until you add one.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @elseif ($tab === 'numbering')
        <div class="card" style="padding: 0">
            <div class="flex flex-wrap items-center justify-between gap-3" style="padding: 1.25rem 1.25rem 0">
                <div>
                    <h2 class="text-base font-semibold text-neutral-900">{{ $groups[$tab][0] }}</h2>
                    <p class="text-sm text-neutral-500">Each series has its own counter. Built-in series are used by their screens. A series you add is ready to use as soon as a screen is attached to it.</p>
                </div>
                <button type="button" wire:click="newSeries" class="btn-primary" style="padding: 0.4rem 0.9rem">Add reference number</button>
            </div>
            <div class="mt-3 overflow-x-auto">
                <table class="table-clean" style="min-width: 560px">
                    <thead><tr><th>Used for</th><th>Prefix</th><th>Digits</th><th>Next number</th><th>Type</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($seriesList as $r)
                            <tr wire:key="rs-{{ $r->id }}">
                                <td class="font-semibold text-neutral-900">{{ $r->label }}</td>
                                <td class="font-mono">{{ $r->prefix }}</td>
                                <td>{{ $r->digits }}</td>
                                <td class="font-mono">{{ $r->format() }}</td>
                                <td><span class="pill {{ $r->is_system ? 'pill-neutral' : 'pill-info' }}">{{ $r->is_system ? 'Built in' : 'Added by ICT' }}</span></td>
                                <td class="whitespace-nowrap text-right">
                                    <button type="button" wire:click="editSeries({{ $r->id }})" class="btn-outline" style="padding: 0.3rem 0.75rem">Edit</button>
                                    @unless ($r->is_system)
                                        <button type="button" wire:click="deleteSeries({{ $r->id }})" wire:confirm="Remove this reference series?" class="btn-outline" style="padding: 0.3rem 0.75rem">Remove</button>
                                    @endunless
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @else
    <form wire:submit="save" class="card">
        <h2 class="text-base font-semibold text-neutral-900">{{ $groups[$tab][0] }}</h2>
        <p class="mb-4 text-sm text-neutral-500">{{ $groups[$tab][1] }}</p>

        <div style="display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(16rem, 1fr))">
            @foreach ($defs as $key => $meta)
                @php $wide = in_array($meta[1], ['textarea', 'list'], true); @endphp
                <div wire:key="s-{{ $key }}" @if ($wide) style="grid-column: 1 / -1" @endif>
                    <label class="label" for="f-{{ $key }}">{{ $meta[0] }}@if ($meta[4]) <span class="text-neutral-400">({{ $meta[4] }})</span>@endif</label>

                    @if ($meta[1] === 'textarea' || $meta[1] === 'list')
                        <textarea id="f-{{ $key }}" wire:model="values.{{ $key }}" rows="{{ $meta[1] === 'list' ? 5 : 2 }}" class="input" style="font-size: 16px"></textarea>
                    @elseif ($key === 'currency')
                        <select id="f-{{ $key }}" wire:model="values.{{ $key }}" class="input">
                            @foreach ($currencyCodes as $code)
                                <option value="{{ $code }}">{{ $code }}</option>
                            @endforeach
                        </select>
                    @elseif ($meta[1] === 'timezone')
                        <select id="f-{{ $key }}" wire:model="values.{{ $key }}" class="input">
                            @foreach ($zones as $z)
                                <option value="{{ $z }}">{{ $z }}</option>
                            @endforeach
                        </select>
                    @else
                        <input id="f-{{ $key }}" type="{{ in_array($meta[1], ['number', 'percent', 'money'], true) ? 'number' : 'text' }}" @if ($meta[1] !== 'number') step="any" @endif wire:model="values.{{ $key }}" class="input" style="font-size: 16px">
                    @endif

                    @error('values.'.$key) <p class="mt-1 text-xs text-critical-700">{{ $message }}</p> @enderror
                    @if ($meta[3]) <p class="mt-1 text-xs text-neutral-400">{{ $meta[3] }}</p> @endif
                    @if (\App\Support\Settings::isCustom($key))
                        <button type="button" wire:click="reset_to_default('{{ $key }}')" wire:confirm="Go back to the built-in default for {{ $meta[0] }}?" class="mt-1 text-xs text-neutral-500 underline">Use the default</button>
                    @endif
                </div>
            @endforeach
        </div>

        <div class="mt-5">
            <button type="submit" class="btn-primary" wire:loading.attr="disabled" wire:target="save">Save {{ strtolower($groups[$tab][0]) }}</button>
        </div>
    </form>
    @endif

    @if ($tab === 'finance')
        <div class="card mt-4" style="padding: 0">
            <div class="flex flex-wrap items-center justify-between gap-3" style="padding: 1.25rem 1.25rem 0">
                <div>
                    <h2 class="text-base font-semibold text-neutral-900">Currencies</h2>
                    <p class="text-sm text-neutral-500">Every currency the system can bill in, including those of all supported countries. Countries, bank accounts, contracts and the default pick from this list.</p>
                </div>
                <button type="button" wire:click="newCurrency" class="btn-primary" style="padding: 0.4rem 0.9rem">Add currency</button>
            </div>
            @error('currency_in_use') <p class="mt-3 text-sm text-critical-700" style="padding: 0 1.25rem">{{ $message }}</p> @enderror
            <div class="mt-3 overflow-x-auto">
                <table class="table-clean" style="min-width: 560px">
                    <thead><tr><th>Code</th><th>Name</th><th>Used by</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($currencyList as $c)
                            @php $use = \App\Models\Currency::usage($c->code); @endphp
                            <tr wire:key="cur-{{ $c->id }}">
                                <td class="font-mono font-semibold text-neutral-900">{{ $c->code }}</td>
                                <td>{{ $c->name }}</td>
                                <td class="text-xs text-neutral-600">
                                    @php
                                        $bits = [];
                                        if ($use['default']) { $bits[] = 'Default currency'; }
                                        if ($use['countries']) { $bits[] = implode(', ', $use['countries']); }
                                        if ($use['banks']) { $bits[] = $use['banks'].' bank '.($use['banks'] === 1 ? 'account' : 'accounts'); }
                                    @endphp
                                    {{ $bits ? implode(' | ', $bits) : 'Not used yet' }}
                                </td>
                                <td class="whitespace-nowrap text-right">
                                    <button type="button" wire:click="editCurrency({{ $c->id }})" class="btn-outline" style="padding: 0.3rem 0.75rem">Edit</button>
                                    @if (! $use['default'] && ! $use['countries'] && ! $use['banks'] && ! $use['records'])
                                        <button type="button" wire:click="deleteCurrency({{ $c->id }})" wire:confirm="Remove {{ $c->code }} from the list?" class="btn-outline" style="padding: 0.3rem 0.75rem">Remove</button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    @if ($showCur)
        <div style="position: fixed; inset: 0; z-index: 60; display: flex; align-items: center; justify-content: center; padding: 1rem; background: rgba(0, 0, 0, 0.45)" wire:click.self="closeForms">
            <form wire:submit="saveCurrency" class="card" style="width: 100%; max-width: 24rem">
                <h2 class="text-base font-semibold text-neutral-900">{{ $curId ? 'Edit currency' : 'Add a currency' }}</h2>
                <div class="mt-4">
                    <label class="label" for="cu-code">Code</label>
                    <input id="cu-code" type="text" wire:model="cuCode" maxlength="3" @if ($curId) readonly @endif class="input" style="font-size: 16px; text-transform: uppercase" placeholder="e.g. AOA">
                    @error('cuCode') <p class="mt-1 text-xs text-critical-700">{{ $message }}</p> @enderror
                </div>
                <div class="mt-3">
                    <label class="label" for="cu-name">Name</label>
                    <input id="cu-name" type="text" wire:model="cuName" class="input" style="font-size: 16px" placeholder="e.g. Angolan kwanza">
                    @error('cuName') <p class="mt-1 text-xs text-critical-700">{{ $message }}</p> @enderror
                </div>
                <div class="mt-5 flex gap-2">
                    <button type="submit" class="btn-primary">Save</button>
                    <button type="button" wire:click="closeForms" class="btn-outline">Cancel</button>
                </div>
            </form>
        </div>
    @endif

    @if ($showBank)
        <div style="position: fixed; inset: 0; z-index: 60; display: flex; align-items: center; justify-content: center; padding: 1rem; background: rgba(0, 0, 0, 0.45); overflow-y: auto" wire:click.self="closeForms">
            <form wire:submit="saveBank" class="card" style="width: 100%; max-width: 30rem">
                <h2 class="text-base font-semibold text-neutral-900">{{ $bankId ? 'Edit bank account' : 'Add a bank account' }}</h2>
                <div style="display: grid; gap: 0.8rem; margin-top: 1rem; grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr))">
                    <div>
                        <label class="label" for="b-bank">Bank</label>
                        <input id="b-bank" type="text" wire:model="bBank" class="input" style="font-size: 16px">
                        @error('bBank') <p class="mt-1 text-xs text-critical-700">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label" for="b-branch">Branch</label>
                        <input id="b-branch" type="text" wire:model="bBranch" class="input" style="font-size: 16px">
                    </div>
                    <div>
                        <label class="label" for="b-name">Account name</label>
                        <input id="b-name" type="text" wire:model="bName" class="input" style="font-size: 16px">
                        @error('bName') <p class="mt-1 text-xs text-critical-700">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label" for="b-number">Account number</label>
                        <input id="b-number" type="text" wire:model="bNumber" class="input" style="font-size: 16px">
                        @error('bNumber') <p class="mt-1 text-xs text-critical-700">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label" for="b-cur">Currency</label>
                        <select id="b-cur" wire:model="bCurrency" class="input" style="font-size: 16px">
                            @foreach ($currencyCodes as $code)
                                <option value="{{ $code }}">{{ $code }}</option>
                            @endforeach
                        </select>
                        @error('bCurrency') <p class="mt-1 text-xs text-critical-700">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label" for="b-swift">SWIFT code</label>
                        <input id="b-swift" type="text" wire:model="bSwift" class="input" style="font-size: 16px">
                    </div>
                    <div style="grid-column: 1 / -1">
                        <label class="label" for="b-country">Country</label>
                        <select id="b-country" wire:model="bCountry" class="input">
                            <option value="">All countries</option>
                            @foreach ($countries as $c)
                                <option value="{{ $c->id }}">{{ $c->name }} ({{ $c->currency_code }})</option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-xs text-neutral-400">Pick a country if only its customers should be asked to pay into this account.</p>
                    </div>
                </div>
                <label class="mt-3 flex items-center gap-2 text-sm text-neutral-700"><input type="checkbox" wire:model="bActive" class="rounded border-neutral-300"> Active, print it on documents</label>
                <div class="mt-5 flex gap-2">
                    <button type="submit" class="btn-primary">Save</button>
                    <button type="button" wire:click="closeForms" class="btn-outline">Cancel</button>
                </div>
            </form>
        </div>
    @endif

    @if ($showSeries)
        <div style="position: fixed; inset: 0; z-index: 60; display: flex; align-items: center; justify-content: center; padding: 1rem; background: rgba(0, 0, 0, 0.45)" wire:click.self="closeForms">
            <form wire:submit="saveSeries" class="card" style="width: 100%; max-width: 26rem">
                <h2 class="text-base font-semibold text-neutral-900">{{ $seriesId ? 'Edit reference number' : 'Add a reference number' }}</h2>
                <div class="mt-4">
                    <label class="label" for="s-label">Used for</label>
                    <input id="s-label" type="text" wire:model="sLabel" class="input" style="font-size: 16px" placeholder="e.g. Purchase orders">
                    @error('sLabel') <p class="mt-1 text-xs text-critical-700">{{ $message }}</p> @enderror
                </div>
                <div style="display: grid; gap: 0.8rem; margin-top: 0.8rem; grid-template-columns: repeat(3, 1fr)">
                    <div>
                        <label class="label" for="s-prefix">Prefix</label>
                        <input id="s-prefix" type="text" wire:model.live.debounce.300ms="sPrefix" maxlength="10" class="input" style="font-size: 16px; text-transform: uppercase" placeholder="PO">
                        @error('sPrefix') <p class="mt-1 text-xs text-critical-700">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label" for="s-digits">Digits</label>
                        <input id="s-digits" type="number" wire:model.live.debounce.300ms="sDigits" class="input" style="font-size: 16px">
                        @error('sDigits') <p class="mt-1 text-xs text-critical-700">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label" for="s-next">Next number</label>
                        <input id="s-next" type="number" wire:model.live.debounce.300ms="sNext" class="input" style="font-size: 16px">
                        @error('sNext') <p class="mt-1 text-xs text-critical-700">{{ $message }}</p> @enderror
                    </div>
                </div>
                @if ($sPrefix !== '' && ctype_digit($sDigits) && ctype_digit($sNext) && (int) $sDigits > 0)
                    <p class="mt-3 text-sm text-neutral-500">The next one will read <span class="font-mono font-semibold text-neutral-900">{{ strtoupper($sPrefix) }}-{{ str_pad($sNext, (int) $sDigits, '0', STR_PAD_LEFT) }}</span></p>
                @endif
                <div class="mt-5 flex gap-2">
                    <button type="submit" class="btn-primary">Save</button>
                    <button type="button" wire:click="closeForms" class="btn-outline">Cancel</button>
                </div>
            </form>
        </div>
    @endif
</div>
