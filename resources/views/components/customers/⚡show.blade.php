<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Models\Customer;
use App\Models\CustomerSite;
use App\Models\Branch;

new #[Layout('layouts.app', ['title' => 'Customer'])] class extends Component
{
    public Customer $customer;

    public bool $editing = false;
    public string $name = '';
    public ?int $branch_id = null;
    public string $kra_pin = '';
    public string $po_box = '';
    public string $main_contact_name = '';
    public string $main_contact_email = '';
    public string $main_contact_phone = '';

    public bool $addingSite = false;
    public string $siteName = '';
    public string $siteContact = '';
    public string $siteLat = '';
    public string $siteLng = '';

    public function mount(Customer $customer): void
    {
        $this->authorize('view', $customer);
        $this->customer = $customer->load('branch.country');
    }

    public function toggleEdit(): void
    {
        $this->authorize('update', $this->customer);

        $this->editing = ! $this->editing;

        if ($this->editing) {
            $this->name = $this->customer->name;
            $this->branch_id = $this->customer->branch_id;
            $this->kra_pin = (string) $this->customer->kra_pin;
            $this->po_box = (string) $this->customer->po_box;
            $this->main_contact_name = (string) $this->customer->main_contact_name;
            $this->main_contact_email = (string) $this->customer->main_contact_email;
            $this->main_contact_phone = (string) $this->customer->main_contact_phone;
        }
    }

    public function save(): void
    {
        $this->authorize('update', $this->customer);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'branch_id' => ['required', 'exists:branches,id'],
            'kra_pin' => ['nullable', 'string', 'max:255'],
            'po_box' => ['nullable', 'string', 'max:255'],
            'main_contact_name' => ['nullable', 'string', 'max:255'],
            'main_contact_email' => ['nullable', 'email', 'max:255'],
            'main_contact_phone' => ['nullable', 'string', 'max:255'],
        ]);

        $this->customer->update([
            'name' => $validated['name'],
            'branch_id' => $validated['branch_id'],
            'kra_pin' => $validated['kra_pin'] ?: null,
            'po_box' => $validated['po_box'] ?: null,
            'main_contact_name' => $validated['main_contact_name'] ?: null,
            'main_contact_email' => $validated['main_contact_email'] ?: null,
            'main_contact_phone' => $validated['main_contact_phone'] ?: null,
        ]);

        $this->customer->refresh()->load('branch.country');
        $this->editing = false;
    }

    public function toggleAddSite(): void
    {
        $this->authorize('update', $this->customer);
        $this->addingSite = ! $this->addingSite;
        $this->siteName = '';
        $this->siteContact = '';
        $this->siteLat = '';
        $this->siteLng = '';
    }

    public function addSite(): void
    {
        $this->authorize('update', $this->customer);

        $validated = $this->validate([
            'siteName' => ['required', 'string', 'max:255'],
            'siteContact' => ['nullable', 'string', 'max:255'],
            'siteLat' => ['nullable', 'numeric', 'between:-90,90'],
            'siteLng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        CustomerSite::create([
            'customer_id' => $this->customer->id,
            'name' => $validated['siteName'],
            'contact_name' => $validated['siteContact'] ?: null,
            'lat' => $validated['siteLat'] ?: null,
            'lng' => $validated['siteLng'] ?: null,
        ]);

        $this->addingSite = false;
    }

    public function with(): array
    {
        $customer = $this->customer;

        return [
            'branches' => Branch::orderBy('name')->get(),
            'sites' => $customer->sites()->orderBy('name')->get(),
            'equipment' => $customer->equipment()->with('site')->orderBy('next_visit_due_at')->get(),
            'contracts' => $customer->contracts()->orderByDesc('starts_at')->get(),
            'openRequests' => $customer->serviceRequests()->where('status', 'Open')->count(),
            'recentRequests' => $customer->serviceRequests()->orderByDesc('created_at')->limit(6)->get(),
            'outstandingInvoices' => $customer->invoices()->where('status', '!=', 'Paid')->orderBy('due_at')->get(),
            'portalAccounts' => $customer->users()->get(),
        ];
    }
};
?>

<div>
    <div class="mb-6 flex items-start justify-between gap-4">
        <div class="flex items-center gap-4">
            <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-neutral-100 text-lg font-semibold text-neutral-600">
                {{ collect(explode(' ', $customer->name))->map(fn ($p) => $p[0] ?? '')->take(2)->implode('') }}
            </div>
            <div>
                <div class="flex items-center gap-2">
                    <h1 class="text-xl font-semibold text-neutral-900">{{ $customer->name }}</h1>
                    @if ($customer->has_active_contract)
                        <span class="pill-success">On contract</span>
                    @else
                        <span class="pill-neutral">No contract</span>
                    @endif
                </div>
                <p class="mt-0.5 text-xs text-neutral-500">
                    {{ $customer->reference }} &middot; {{ $customer->branch->name }}, {{ $customer->branch->country->name }}
                </p>
            </div>
        </div>
        @can('update', $customer)
            <button type="button" wire:click="toggleEdit" class="btn-outline">
                {{ $editing ? 'Cancel' : 'Edit details' }}
            </button>
        @endcan
    </div>

    @if ($editing)
        <form wire:submit="save" class="card mb-5 space-y-4">
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="label">Company name</label>
                    <input wire:model="name" type="text" class="input">
                    @error('name') <p class="mt-1 text-xs text-critical-700">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label">Branch</label>
                    <select wire:model="branch_id" class="input">
                        @foreach ($branches as $branch)
                            <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                        @endforeach
                    </select>
                    @error('branch_id') <p class="mt-1 text-xs text-critical-700">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label">KRA PIN</label>
                    <input wire:model="kra_pin" type="text" class="input">
                    @error('kra_pin') <p class="mt-1 text-xs text-critical-700">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label">P.O. Box</label>
                    <input wire:model="po_box" type="text" class="input">
                    @error('po_box') <p class="mt-1 text-xs text-critical-700">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label">Main contact name</label>
                    <input wire:model="main_contact_name" type="text" class="input">
                    @error('main_contact_name') <p class="mt-1 text-xs text-critical-700">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label">Main contact email</label>
                    <input wire:model="main_contact_email" type="email" class="input">
                    @error('main_contact_email') <p class="mt-1 text-xs text-critical-700">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label">Main contact phone</label>
                    <input wire:model="main_contact_phone" type="text" class="input">
                    @error('main_contact_phone') <p class="mt-1 text-xs text-critical-700">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="flex justify-end gap-2">
                <button type="button" wire:click="toggleEdit" class="btn-ghost">Cancel</button>
                <button type="submit" class="btn-primary">Save changes</button>
            </div>
        </form>
    @else
        <div class="mb-5 grid grid-cols-4 gap-4">
            <div class="card">
                <p class="text-xs font-medium text-neutral-500">Active contracts</p>
                <p class="mt-1 text-2xl font-semibold text-neutral-900">{{ $contracts->where('status', 'Active')->count() }}</p>
            </div>
            <div class="card">
                <p class="text-xs font-medium text-neutral-500">Open requests</p>
                <p class="mt-1 text-2xl font-semibold text-neutral-900">{{ $openRequests }}</p>
            </div>
            <div class="card">
                <p class="text-xs font-medium text-neutral-500">Sites</p>
                <p class="mt-1 text-2xl font-semibold text-neutral-900">{{ $sites->count() }}</p>
            </div>
            <div class="card">
                <p class="text-xs font-medium text-neutral-500">Balance</p>
                <p class="mt-1 text-2xl font-semibold {{ $customer->balance_minor > 0 ? 'text-urgent-700' : 'text-neutral-900' }}">
                    {{ $customer->currencyCode() }} {{ $customer->balanceFormatted() }}
                </p>
            </div>
        </div>

        <div class="card mb-5">
            <h2 class="mb-3 text-sm font-semibold text-neutral-900">Company details</h2>
            <div class="grid grid-cols-3 gap-4 text-sm">
                <div>
                    <p class="text-xs text-neutral-500">KRA PIN</p>
                    <p class="font-medium text-neutral-900">{{ $customer->kra_pin ?: '&mdash;' }}</p>
                </div>
                <div>
                    <p class="text-xs text-neutral-500">P.O. Box</p>
                    <p class="font-medium text-neutral-900">{{ $customer->po_box ?: '&mdash;' }}</p>
                </div>
                <div>
                    <p class="text-xs text-neutral-500">Main contact</p>
                    <p class="font-medium text-neutral-900">{{ $customer->main_contact_name ?: '&mdash;' }}</p>
                    @if ($customer->main_contact_email)
                        <p class="text-xs text-neutral-500">{{ $customer->main_contact_email }}</p>
                    @endif
                    @if ($customer->main_contact_phone)
                        <p class="text-xs text-neutral-500">{{ $customer->main_contact_phone }}</p>
                    @endif
                </div>
            </div>
        </div>
    @endif

    <div class="grid items-start gap-4 lg:grid-cols-2">
        <div class="card">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="text-sm font-semibold text-neutral-900">Sites</h2>
                @can('update', $customer)
                    <button type="button" wire:click="toggleAddSite" class="text-xs font-semibold text-primary-700 hover:text-primary-800">
                        {{ $addingSite ? 'Cancel' : '+ Add a site' }}
                    </button>
                @endcan
            </div>

            @if ($addingSite)
                <form wire:submit="addSite" class="mb-4 space-y-3 rounded-[var(--radius-md)] border border-neutral-200 p-3">
                    <div>
                        <label class="label">Site name</label>
                        <input wire:model="siteName" type="text" class="input" placeholder="e.g. Head office, Mombasa branch">
                        @error('siteName') <p class="mt-1 text-xs text-critical-700">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label">Site contact</label>
                        <input wire:model="siteContact" type="text" class="input">
                        @error('siteContact') <p class="mt-1 text-xs text-critical-700">{{ $message }}</p> @enderror
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="label">Latitude</label>
                            <input wire:model="siteLat" type="text" class="input" placeholder="-1.2921">
                            @error('siteLat') <p class="mt-1 text-xs text-critical-700">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="label">Longitude</label>
                            <input wire:model="siteLng" type="text" class="input" placeholder="36.8219">
                            @error('siteLng') <p class="mt-1 text-xs text-critical-700">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <div class="flex justify-end">
                        <button type="submit" class="btn-primary">Add site</button>
                    </div>
                </form>
            @endif

            <div class="space-y-2">
                @forelse ($sites as $site)
                    <div class="flex items-center justify-between rounded-[var(--radius-md)] border border-neutral-100 px-3 py-2">
                        <div>
                            <p class="text-sm font-medium text-neutral-900">{{ $site->name }}</p>
                            @if ($site->contact_name)
                                <p class="text-xs text-neutral-500">{{ $site->contact_name }}</p>
                            @endif
                        </div>
                        @if ($site->hasCoordinates())
                            <span class="pill-neutral">{{ $site->lat }}, {{ $site->lng }}</span>
                        @endif
                    </div>
                @empty
                    <p class="text-sm text-neutral-500">No sites recorded yet.</p>
                @endforelse
            </div>
        </div>

        <div class="card">
            <h2 class="mb-3 text-sm font-semibold text-neutral-900">Equipment</h2>
            <div class="space-y-2">
                @forelse ($equipment as $item)
                    <div class="flex items-center justify-between rounded-[var(--radius-md)] border border-neutral-100 px-3 py-2">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium text-neutral-900">{{ $item->model }}</p>
                            <p class="text-xs text-neutral-500">
                                {{ $item->serial_number }}{{ $item->site ? ' &middot; '.$item->site->name : '' }}
                            </p>
                        </div>
                        <span class="pill-{{ match ($item->visitStatus()) { 'Overdue' => 'danger', 'Due soon' => 'amber', default => 'success' } }}">
                            {{ $item->visitStatus() }}
                        </span>
                    </div>
                @empty
                    <p class="text-sm text-neutral-500">No equipment on file.</p>
                @endforelse
            </div>
        </div>

        <div class="card">
            <h2 class="mb-3 text-sm font-semibold text-neutral-900">Contracts</h2>
            <div class="space-y-2">
                @forelse ($contracts as $contract)
                    <a href="/contracts/{{ $contract->id }}" wire:navigate
                       class="flex items-center justify-between rounded-[var(--radius-md)] border border-neutral-100 px-3 py-2 hover:border-neutral-300">
                        <div>
                            <p class="text-sm font-medium text-neutral-900">{{ $contract->reference }} &middot; {{ $contract->type }}</p>
                            <p class="text-xs text-neutral-500">
                                {{ $contract->starts_at->format('d M Y') }} &ndash; {{ $contract->ends_at->format('d M Y') }}
                            </p>
                        </div>
                        <span class="pill-{{ $contract->status === 'Active' ? 'success' : 'neutral' }}">{{ $contract->status }}</span>
                    </a>
                @empty
                    <p class="text-sm text-neutral-500">No contracts on file.</p>
                @endforelse
            </div>
        </div>

        <div class="card">
            <h2 class="mb-3 text-sm font-semibold text-neutral-900">Outstanding invoices</h2>
            <div class="space-y-2">
                @forelse ($outstandingInvoices as $invoice)
                    <a href="/invoices/{{ $invoice->id }}" wire:navigate
                       class="flex items-center justify-between rounded-[var(--radius-md)] border border-neutral-100 px-3 py-2 hover:border-neutral-300">
                        <div>
                            <p class="text-sm font-medium text-neutral-900">{{ $invoice->reference }}</p>
                            <p class="text-xs text-neutral-500">Due {{ $invoice->due_at->format('d M Y') }}</p>
                        </div>
                        <span class="pill-{{ $invoice->due_at->isPast() ? 'danger' : 'amber' }}">
                            {{ $invoice->currency_code }} {{ number_format($invoice->amount_minor / 100, 2) }}
                        </span>
                    </a>
                @empty
                    <p class="text-sm text-neutral-500">No outstanding invoices.</p>
                @endforelse
            </div>
        </div>
    </div>

    <div class="mt-4 grid items-start gap-4 lg:grid-cols-2">
        <div class="card">
            <h2 class="mb-3 text-sm font-semibold text-neutral-900">Recent service requests</h2>
            <div class="divide-y divide-neutral-50">
                @forelse ($recentRequests as $request)
                    <a href="/requests/{{ $request->id }}" wire:navigate class="flex items-center justify-between py-2.5 hover:bg-neutral-50">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium text-neutral-900">{{ $request->reference }}</p>
                            <p class="truncate text-xs text-neutral-500">{{ $request->fault_description }}</p>
                        </div>
                        <span class="pill-neutral shrink-0">{{ $request->status }}</span>
                    </a>
                @empty
                    <p class="text-sm text-neutral-500">No service requests yet.</p>
                @endforelse
            </div>
        </div>

        @can('viewAny', \App\Models\User::class)
            <div class="card">
                <h2 class="mb-3 text-sm font-semibold text-neutral-900">Portal accounts</h2>
                <div class="divide-y divide-neutral-50">
                    @forelse ($portalAccounts as $account)
                        <a href="/users/{{ $account->id }}" wire:navigate class="flex items-center justify-between py-2.5 hover:bg-neutral-50">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-neutral-900">{{ $account->name }}</p>
                                <p class="truncate text-xs text-neutral-500">{{ $account->email }}</p>
                            </div>
                            <span class="pill-{{ $account->status === 'Active' ? 'success' : 'neutral' }}">{{ $account->status }}</span>
                        </a>
                    @empty
                        <p class="text-sm text-neutral-500">No portal login for this customer yet.</p>
                    @endforelse
                </div>
            </div>
        @endcan
    </div>
</div>
