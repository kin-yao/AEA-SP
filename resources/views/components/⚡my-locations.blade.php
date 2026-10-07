<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Models\CustomerSite;
use App\Models\Equipment;
use App\Models\Quotation;
use App\Models\ServiceRequest;
use App\Models\WorkOrder;

new #[Layout('layouts.app', ['title' => 'My locations'])] class extends Component
{
    public bool $showForm = false;
    public ?int $siteId = null;
    public string $siteName = '';
    public string $siteAddress = '';
    public string $siteContact = '';
    public string $siteLat = '';
    public string $siteLng = '';
    public ?string $notice = null;
    public ?string $problem = null;

    public function mount(): void
    {
        abort_unless(auth()->user()->hasRole('Customer') && auth()->user()->customer_id, 403);
    }

    protected function mine()
    {
        return CustomerSite::where('customer_id', auth()->user()->customer_id);
    }

    public function newSite(): void
    {
        $this->reset(['siteId', 'siteName', 'siteAddress', 'siteContact', 'siteLat', 'siteLng', 'notice', 'problem']);
        $this->resetValidation();
        $this->showForm = true;
    }

    public function editSite(int $id): void
    {
        $site = $this->mine()->findOrFail($id);
        $this->reset(['notice', 'problem']);
        $this->resetValidation();
        $this->siteId = $site->id;
        $this->siteName = $site->name;
        $this->siteAddress = (string) $site->address;
        $this->siteContact = (string) $site->contact_name;
        $this->siteLat = $site->lat !== null ? (string) $site->lat : '';
        $this->siteLng = $site->lng !== null ? (string) $site->lng : '';
        $this->showForm = true;
    }

    public function save(): void
    {
        $v = $this->validate([
            'siteName' => ['required', 'string', 'max:255'],
            'siteAddress' => ['nullable', 'string', 'max:500'],
            'siteContact' => ['nullable', 'string', 'max:255'],
            'siteLat' => ['required', 'numeric', 'between:-90,90'],
            'siteLng' => ['required', 'numeric', 'between:-180,180'],
        ], [
            'siteLat.required' => 'Drop a pin on the map first.',
            'siteLng.required' => 'Drop a pin on the map first.',
        ], ['siteName' => 'name']);

        $data = [
            'name' => trim($v['siteName']),
            'address' => trim((string) $v['siteAddress']) ?: null,
            'contact_name' => trim((string) $v['siteContact']) ?: null,
            'lat' => $v['siteLat'],
            'lng' => $v['siteLng'],
        ];

        if ($this->siteId) {
            $this->mine()->findOrFail($this->siteId)->update($data);
            $this->notice = 'Location saved.';
        } else {
            CustomerSite::create(['customer_id' => auth()->user()->customer_id] + $data);
            $this->notice = 'Location added.';
        }

        $this->showForm = false;
        $this->reset(['siteId', 'siteName', 'siteAddress', 'siteContact', 'siteLat', 'siteLng']);
    }

    public function remove(int $id): void
    {
        $site = $this->mine()->findOrFail($id);
        $this->reset(['notice', 'problem']);

        $used = Equipment::where('customer_site_id', $id)->exists()
            || ServiceRequest::where('customer_site_id', $id)->exists()
            || WorkOrder::where('customer_site_id', $id)->exists()
            || Quotation::where('customer_site_id', $id)->exists();

        if ($used) {
            $this->problem = 'That location is used by machines or jobs. Edit it instead.';

            return;
        }

        $site->delete();
        $this->notice = 'Location removed.';
    }

    public function cancel(): void
    {
        $this->showForm = false;
        $this->resetValidation();
    }

    public function with(): array
    {
        return ['sites' => $this->mine()->orderBy('name')->get()];
    }
};
?>

<div class="mx-auto" style="max-width: 44rem">
    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-xl font-semibold text-neutral-900">My locations</h1>
        @unless ($showForm)
            <button type="button" wire:click="newSite" class="btn-primary">Add location</button>
        @endunless
    </div>

    @if ($notice)
        <div class="mb-4 rounded-lg border border-success-200 bg-success-50 px-4 py-3 text-sm text-success-700" role="status">{{ $notice }}</div>
    @endif
    @if ($problem)
        <div class="mb-4 rounded-lg border border-critical-200 bg-critical-50 px-4 py-3 text-sm text-critical-700" role="alert">{{ $problem }}</div>
    @endif

    @if ($showForm)
        <form wire:submit="save" class="card mb-4 space-y-3">
            <h2 class="text-base font-semibold text-neutral-900">{{ $siteId ? 'Edit location' : 'Add a location' }}</h2>
            <div>
                <label class="label" for="ml-name">Name</label>
                <input id="ml-name" wire:model="siteName" type="text" class="input" placeholder="e.g. Head office, Mombasa depot">
                @error('siteName') <p class="mt-1 text-xs text-critical-700">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="label" for="ml-address">Address or landmark</label>
                <input id="ml-address" wire:model="siteAddress" type="text" class="input" placeholder="Gate, floor, nearby landmark">
            </div>
            <div>
                <label class="label" for="ml-contact">Contact on site</label>
                <input id="ml-contact" wire:model="siteContact" type="text" class="input">
            </div>
            <div>
                <label class="label">Pin on the map</label>
                <x-location-picker lat="siteLat" lng="siteLng" wire:key="ml-picker-{{ $siteId ?? 'new' }}" />
                @error('siteLat') <p class="mt-1 text-xs text-critical-700">{{ $message }}</p> @enderror
            </div>
            <div class="flex gap-2">
                <button type="submit" class="btn-primary" wire:loading.attr="disabled" wire:target="save">Save</button>
                <button type="button" wire:click="cancel" class="btn-outline">Cancel</button>
            </div>
        </form>
    @endif

    <div class="space-y-2">
        @forelse ($sites as $site)
            <div class="card flex flex-wrap items-center justify-between gap-3" wire:key="ml-{{ $site->id }}">
                <div style="min-width: 0">
                    <p class="text-sm font-semibold text-neutral-900">{{ $site->name }}</p>
                    @if ($site->address)
                        <p class="text-sm text-neutral-500">{{ $site->address }}</p>
                    @endif
                    @if ($site->contact_name)
                        <p class="text-xs text-neutral-400">{{ $site->contact_name }}</p>
                    @endif
                </div>
                <div class="flex shrink-0 items-center gap-2">
                    @if ($site->hasCoordinates())
                        <span class="pill-success">Pinned</span>
                    @else
                        <span class="pill-amber">No pin</span>
                    @endif
                    <button type="button" wire:click="editSite({{ $site->id }})" class="btn-outline" style="padding: 0.3rem 0.75rem">Edit</button>
                    <button type="button" wire:click="remove({{ $site->id }})" wire:confirm="Remove this location?" class="btn-outline" style="padding: 0.3rem 0.75rem">Remove</button>
                </div>
            </div>
        @empty
            @unless ($showForm)
                <div class="card text-center text-sm text-neutral-500">No locations yet. Add one so technicians can find you.</div>
            @endunless
        @endforelse
    </div>
</div>
