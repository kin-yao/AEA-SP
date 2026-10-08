<?php

use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Models\Document;
use App\Models\Quotation;

new #[Layout('layouts.app', ['title' => 'LPOs'])] class extends Component
{
    public const ON_FILE = 'On file';
    public const AWAITED = 'Awaited from customer';

    // What the person is typing, and what has actually been applied.
    public string $search = '';
    public string $status = '';
    public string $via = '';
    public string $from = '';
    public string $to = '';
    public array $applied = ['search' => '', 'status' => '', 'via' => '', 'from' => '', 'to' => ''];

    // The row open in the detail window, "d12" for a logged LPO, "p5" for a
    // quotation still waiting on its LPO.
    public ?string $open = null;

    public function mount(): void
    {
        $role = auth()->user()->roles->first()?->name ?? '';
        abort_unless(in_array(Document::TYPE_LPO, Document::scopeForRole($role), true), 403);
    }

    public function applyFilter(): void
    {
        $this->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $this->applied = [
            'search' => trim($this->search),
            'status' => $this->status,
            'via' => $this->via,
            'from' => $this->from,
            'to' => $this->to,
        ];
    }

    public function resetFilter(): void
    {
        $this->reset(['search', 'status', 'via', 'from', 'to']);
        $this->resetValidation();
        $this->applied = ['search' => '', 'status' => '', 'via' => '', 'from' => '', 'to' => ''];
    }

    public function show(string $key): void
    {
        $this->open = $key;
    }

    public function close(): void
    {
        $this->open = null;
    }

    // One list out of two sources: LPOs already logged, and approved
    // quotations whose LPO has not arrived yet.
    private function rows()
    {
        $logged = Document::where('type', Document::TYPE_LPO)
            ->with(['customer', 'workOrder', 'filedBy', 'lpoDetail.quotation.workOrder', 'lpoDetail.quotation.items'])
            ->get()
            ->map(function (Document $d) {
                $q = $d->lpoDetail?->quotation;
                $job = $d->workOrder?->reference ?? $q?->workOrder?->reference;

                return [
                    'key' => 'd'.$d->id,
                    'kind' => 'doc',
                    'no' => $d->reference,
                    'type' => 'LPO, customer-supplied',
                    'customer' => $d->customer->name,
                    'job' => $job,
                    'date' => $d->created_at,
                    'by' => $d->filedBy?->name,
                    'status' => self::ON_FILE,
                    'via' => $d->lpoDetail?->received_via,
                    'doc' => $d,
                    'quotation' => $q,
                    'search' => strtolower(implode(' ', array_filter([
                        $d->reference, $d->customer->name, $q?->reference, $q?->scope, $job,
                        $d->lpoDetail?->received_via, $q?->items->pluck('description')->implode(' '),
                    ]))),
                ];
            });

        $awaited = Quotation::where('status', 'Approved')
            ->whereDoesntHave('lpoDetail')
            ->with(['customer', 'items'])
            ->get()
            ->map(fn (Quotation $q) => [
                'key' => 'p'.$q->id,
                'kind' => 'pending',
                'no' => $q->reference,
                'type' => 'LPO, awaited',
                'customer' => $q->customer->name,
                'job' => null,
                'date' => $q->updated_at,
                'by' => null,
                'status' => self::AWAITED,
                'via' => null,
                'doc' => null,
                'quotation' => $q,
                'search' => strtolower(implode(' ', array_filter([
                    $q->reference, $q->customer->name, $q->scope, $q->items->pluck('description')->implode(' '),
                ]))),
            ]);

        return $logged->concat($awaited)->sortByDesc(fn ($r) => $r['date']->timestamp)->values();
    }

    public function with(): array
    {
        $all = $this->rows();
        $a = $this->applied;

        $rows = $all->filter(function ($r) use ($a) {
            if ($a['search'] !== '' && ! str_contains($r['search'], strtolower($a['search']))) {
                return false;
            }
            if ($a['status'] !== '' && $r['status'] !== $a['status']) {
                return false;
            }
            if ($a['via'] !== '' && $r['via'] !== $a['via']) {
                return false;
            }
            if ($a['from'] !== '' && $r['date']->lt(Carbon::parse($a['from'])->startOfDay())) {
                return false;
            }
            if ($a['to'] !== '' && $r['date']->gt(Carbon::parse($a['to'])->endOfDay())) {
                return false;
            }

            return true;
        })->values();

        return [
            'rows' => $rows,
            'total' => $all->count(),
            'channels' => $all->pluck('via')->filter()->unique()->sort()->values(),
            'detail' => $this->open ? $all->firstWhere('key', $this->open) : null,
        ];
    }
};
?>
@php
    $kes = fn (int $minor) => currency().' '.number_format($minor / 100, 0);
@endphp
<div class="mx-auto" style="max-width: 72rem">
    <div class="mb-3 flex items-center gap-3">
        <h1 class="text-xl font-semibold text-neutral-900">LPOs</h1>
        <div class="flex-1 border-t border-neutral-200"></div>
        <span class="text-xs text-neutral-400">{{ $rows->count() }} of {{ $total }}</span>
    </div>

    {{-- Filters --}}
    <form wire:submit="applyFilter" class="card mb-4" style="padding: 1rem">
        <div class="flex flex-wrap items-end gap-3">
            <div style="flex: 2 1 220px; min-width: 0">
                <input type="text" wire:model="search" class="input" placeholder="Search customer or machine">
            </div>
            <div style="flex: 1 1 160px; min-width: 0">
                <select wire:model="status" class="input">
                    <option value="">All statuses</option>
                    <option value="{{ $this::ON_FILE }}">{{ $this::ON_FILE }}</option>
                    <option value="{{ $this::AWAITED }}">{{ $this::AWAITED }}</option>
                </select>
            </div>
            <div style="flex: 1 1 140px; min-width: 0">
                <select wire:model="via" class="input">
                    <option value="">All channels</option>
                    @foreach ($channels as $c)
                        <option value="{{ $c }}">{{ $c }}</option>
                    @endforeach
                </select>
            </div>
            <div style="flex: 1 1 140px; min-width: 0">
                <label class="label">From</label>
                <input type="date" wire:model="from" class="input">
            </div>
            <div style="flex: 1 1 140px; min-width: 0">
                <label class="label">To</label>
                <input type="date" wire:model="to" class="input">
            </div>
            <div class="flex gap-2">
                <button type="submit" class="btn-primary">Filter</button>
                @if (array_filter($applied) || $search || $status || $via || $from || $to)
                    <button type="button" wire:click="resetFilter" class="btn-outline">Reset</button>
                @endif
            </div>
        </div>
        @error('from') <p class="field-error">{{ $message }}</p> @enderror
        @error('to') <p class="field-error">{{ $message }}</p> @enderror
    </form>

    {{-- List --}}
    <div class="card" style="padding: 0; overflow: hidden">
        <div style="overflow-x: auto">
            <table class="table-clean" style="min-width: 760px">
                <thead>
                    <tr>
                        <th>No.</th>
                        <th>Type</th>
                        <th>Customer</th>
                        <th>Job</th>
                        <th>Date</th>
                        <th>Logged by</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $r)
                        <tr wire:key="lpo-{{ $r['key'] }}">
                            <td class="whitespace-nowrap font-mono font-bold text-neutral-900">{{ $r['no'] }}</td>
                            <td>{{ $r['type'] }}</td>
                            <td>{{ $r['customer'] }}</td>
                            <td>{{ $r['job'] ?? '-' }}</td>
                            <td class="whitespace-nowrap">{{ $r['date']->format('d M') }}</td>
                            <td>{{ $r['by'] ?? '-' }}</td>
                            <td><span class="{{ $r['kind'] === 'doc' ? 'pill-neutral' : 'pill-amber' }}">{{ $r['status'] }}</span></td>
                            <td class="text-right">
                                <button type="button" wire:click="show('{{ $r['key'] }}')" class="btn-outline" style="padding: 0.35rem 0.9rem">Open</button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-center text-neutral-400" style="padding: 2rem">No LPOs match these filters.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Detail window --}}
    @if ($detail)
        @php
            $q = $detail['quotation'];
            $cust = $q?->customer ?? $detail['doc']?->customer;
            $isDoc = $detail['kind'] === 'doc';
            $file = $isDoc ? $detail['doc']->lpoDetail?->file_path : null;
        @endphp
        <div style="position: fixed; inset: 0; z-index: 60; display: flex; align-items: center; justify-content: center; padding: 1rem; background: rgba(0, 0, 0, 0.45)" wire:click.self="close">
            <div class="card" style="width: 100%; max-width: 56rem; max-height: 92vh; overflow-y: auto; padding: 0">
                <div class="flex items-center justify-between gap-3" style="padding: 1rem 1.25rem; border-bottom: 1px solid #e4e4e7">
                    <h2 class="text-base font-semibold text-neutral-900">
                        {{ $isDoc ? $detail['type'].', No. '.$detail['no'] : 'LPO awaited, '.$detail['no'] }}
                    </h2>
                    <button type="button" wire:click="close" class="btn-outline" style="padding: 0.3rem 0.7rem" aria-label="Close">&times;</button>
                </div>

                <div style="padding: 1.25rem">
                    <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1rem 2rem">
                        <div>
                            <p class="text-xs text-neutral-500">{{ $isDoc ? 'Issued by' : 'Customer' }}</p>
                            <p class="text-sm font-semibold text-neutral-900">{{ $cust?->name }}</p>
                            @if ($cust?->po_box)<p class="text-xs text-neutral-500">{{ $cust->po_box }}</p>@endif
                            @if ($cust?->kra_pin)<p class="text-xs text-neutral-400">KRA {{ $cust->kra_pin }}</p>@endif
                        </div>
                        @if ($isDoc)
                            <div>
                                <p class="text-xs text-neutral-500">Issued to</p>
                                <p class="text-sm font-semibold text-neutral-900">AEA Limited</p>
                            </div>
                        @endif
                        <div>
                            <p class="text-xs text-neutral-500">Against quotation</p>
                            <p class="text-sm font-semibold text-neutral-900">{{ $q?->reference ?? '-' }}</p>
                        </div>
                        @if ($isDoc)
                            <div>
                                <p class="text-xs text-neutral-500">Job</p>
                                <p class="text-sm font-semibold text-neutral-900">{{ $detail['job'] ?? '-' }}</p>
                            </div>
                            <div>
                                <p class="text-xs text-neutral-500">Received via</p>
                                <p class="text-sm font-semibold text-neutral-900">{{ $detail['via'] ?? '-' }}</p>
                            </div>
                            <div>
                                <p class="text-xs text-neutral-500">Date on file</p>
                                <p class="text-sm font-semibold text-neutral-900">{{ $detail['date']->format('d M Y') }}</p>
                            </div>
                        @endif
                    </div>

                    @if ($q?->scope)
                        <div class="mt-4">
                            <p class="text-xs text-neutral-500">Scope {{ $isDoc ? 'authorised' : 'quoted' }}</p>
                            <p class="text-sm font-semibold text-neutral-900">{{ $q->scope }}</p>
                        </div>
                    @endif

                    @if ($q)
                        <div style="overflow-x: auto; margin-top: 1rem">
                            <table class="table-clean" style="min-width: 520px">
                                <thead>
                                    <tr><th>Item</th><th>Qty</th><th>Rate</th><th>Amount</th></tr>
                                </thead>
                                <tbody>
                                    @foreach ($q->items as $item)
                                        <tr>
                                            <td>{{ $item->description }}</td>
                                            <td>{{ $item->quantity }}</td>
                                            <td>{{ $kes($item->rate_minor) }}</td>
                                            <td>{{ $kes($item->amountMinor()) }}</td>
                                        </tr>
                                    @endforeach
                                    @if ($q->labour_minor > 0)
                                        <tr>
                                            <td>Labour</td>
                                            <td>1</td>
                                            <td>{{ $kes($q->labour_minor) }}</td>
                                            <td>{{ $kes($q->labour_minor) }}</td>
                                        </tr>
                                    @endif
                                </tbody>
                            </table>
                        </div>
                        <div class="flex items-center justify-end gap-4" style="padding: 0.75rem 1rem 0">
                            <span class="text-xs font-semibold text-neutral-700">{{ $isDoc ? 'Authorised total' : 'Quoted total' }} (incl. VAT)</span>
                            <span class="font-mono text-lg font-bold text-neutral-900">{{ $kes($q->totalMinor()) }}</span>
                        </div>
                    @endif

                    <div class="mt-5 grid" style="grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1rem 2rem">
                        @if ($isDoc)
                            <div>
                                <p class="text-xs text-neutral-500">Uploaded document</p>
                                @if ($file)
                                    <a href="{{ \App\Support\Files::url($file) }}" target="_blank" class="text-sm font-semibold text-neutral-900 underline">{{ basename($file) }}</a>
                                @else
                                    <p class="text-sm text-neutral-400">Not attached yet</p>
                                @endif
                            </div>
                        @endif
                        <div>
                            <p class="text-xs text-neutral-500">Status</p>
                            <span class="{{ $isDoc ? 'pill-neutral' : 'pill-amber' }}">{{ $detail['status'] }}</span>
                        </div>
                    </div>

                    <div class="mt-5 flex flex-wrap gap-2">
                        <button type="button" wire:click="close" class="btn-outline">Close</button>
                        @if ($q)
                            <a href="/quotations/{{ $q->id }}" wire:navigate class="btn-outline">{{ $isDoc ? 'Open quotation' : 'Open quotation to log the LPO' }}</a>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
