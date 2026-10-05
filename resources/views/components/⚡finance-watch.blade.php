<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Models\Invoice;
use App\Models\Quotation;
use App\Models\WorkOrder;
use App\Services\ManagerReports;

new #[Layout('layouts.app', ['title' => 'Finance watch'])] class extends Component
{
    public string $tab = 'invoices';
    public string $search = '';
    public string $status = 'All';

    public function mount(): void
    {
        abort_unless(auth()->user()->hasRole('Supervisor'), 403);
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab === 'quotations' ? 'quotations' : 'invoices';
        $this->status = 'All';
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    protected function invoiceOverdue(Invoice $i): bool
    {
        return in_array($i->status, ['Unpaid', 'Part paid']) && $i->due_at !== null && $i->due_at->lt(today());
    }

    public function with(): array
    {
        $open = Invoice::whereIn('status', ['Unpaid', 'Part paid'])->get(['id', 'amount_minor', 'paid_minor', 'due_at']);

        $notBilled = WorkOrder::whereDoesntHave('invoices')
            ->where(fn ($q) => $q->where('status', 'Approved')
                ->orWhereHas('documents', fn ($d) => $d->where('type', 'rep')->where('status', 'Released')))
            ->get(['id', 'value_minor']);

        $term = trim($this->search);

        $invoices = collect();
        $quotations = collect();

        if ($this->tab === 'invoices') {
            $q = Invoice::with(['customer', 'workOrder', 'contract'])->latest('issued_at')->latest('id');
            if ($term !== '') {
                $q->where(fn ($w) => $w->where('reference', 'like', "%{$term}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$term}%")));
            }
            if ($this->status === 'Overdue') {
                $q->whereIn('status', ['Unpaid', 'Part paid'])->where('due_at', '<', today());
            } elseif ($this->status !== 'All') {
                $q->where('status', $this->status);
            }
            $invoices = $q->take(100)->get();
        } else {
            $q = Quotation::with(['customer', 'items'])->latest();
            if ($term !== '') {
                $q->where(fn ($w) => $w->where('reference', 'like', "%{$term}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$term}%")));
            }
            if ($this->status === 'Awaiting') {
                $q->where('status', 'like', 'Awaiting%');
            } elseif ($this->status === 'Approved') {
                $q->whereIn('status', ['Approved', 'Accepted', 'Converted']);
            } elseif ($this->status !== 'All') {
                $q->where('status', $this->status);
            }
            $quotations = $q->take(100)->get();
        }

        return [
            'notBilledMinor' => (int) $notBilled->sum('value_minor'),
            'notBilledCount' => $notBilled->count(),
            'unpaidMinor' => (int) $open->sum(fn ($i) => $i->amount_minor - $i->paid_minor),
            'overdueMinor' => (int) $open->filter(fn ($i) => $i->due_at !== null && $i->due_at->lt(today()))->sum(fn ($i) => $i->amount_minor - $i->paid_minor),
            'monthRevenue' => ManagerReports::revenueMinor(now()->startOfMonth(), now()->endOfMonth()),
            'invoiceTotal' => Invoice::count(),
            'quotationTotal' => Quotation::count(),
            'invoices' => $invoices,
            'quotations' => $quotations,
            'statuses' => $this->tab === 'invoices'
                ? ['All', 'Overdue', 'Unpaid', 'Part paid', 'Draft', 'Paid']
                : ['All', 'Awaiting', 'Approved', 'Sent back'],
        ];
    }
};
?>

<div class="mx-auto" style="max-width: 72rem">
    <div class="mb-5 flex flex-wrap items-baseline gap-x-4 gap-y-1">
        <h1 class="text-xl font-semibold text-neutral-900">Finance watch</h1>
        <div class="hidden flex-1 border-t border-neutral-200 sm:block"></div>
        <p class="text-sm text-neutral-500">Read only. Finance raises and settles invoices, the Service Admin raises quotations.</p>
    </div>

    <div class="mb-4 grid gap-3" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));">
        <div class="card">
            <p class="text-sm text-neutral-500">Approved, not billed</p>
            <p class="mt-2 font-mono text-2xl font-bold text-neutral-900">{{ \App\Services\ManagerReports::kes($notBilledMinor, true) }}</p>
            <p class="mt-0.5 text-xs text-neutral-400">{{ $notBilledCount }} {{ $notBilledCount === 1 ? 'job' : 'jobs' }} waiting for an invoice</p>
        </div>
        <div class="card">
            <p class="text-sm text-neutral-500">Unpaid</p>
            <p class="mt-2 font-mono text-2xl font-bold text-neutral-900">{{ \App\Services\ManagerReports::kes($unpaidMinor, true) }}</p>
            <p class="mt-0.5 text-xs text-neutral-400">balance still to collect</p>
        </div>
        <div class="card">
            <p class="text-sm text-neutral-500">Overdue</p>
            <p @class(['mt-2 font-mono text-2xl font-bold', 'text-critical-700' => $overdueMinor > 0, 'text-neutral-900' => $overdueMinor === 0])>{{ \App\Services\ManagerReports::kes($overdueMinor, true) }}</p>
            <p class="mt-0.5 text-xs text-neutral-400">past the due date</p>
        </div>
        <div class="card">
            <p class="text-sm text-neutral-500">{{ now()->format('F') }} revenue</p>
            <p class="mt-2 font-mono text-2xl font-bold text-neutral-900">{{ \App\Services\ManagerReports::kes($monthRevenue, true) }}</p>
            <p class="mt-0.5 text-xs text-neutral-400">invoices issued this month</p>
        </div>
    </div>

    <div class="card mb-4 flex flex-wrap items-center gap-x-5 gap-y-2" style="padding: 0.75rem 1.25rem">
        <span class="text-xs font-bold uppercase tracking-wider text-neutral-500">Key</span>
        <span class="inline-flex items-center gap-2 text-sm text-neutral-800"><span style="width: 14px; height: 14px; border-radius: 4px; background: #15803d"></span>Paid, settled</span>
        <span class="inline-flex items-center gap-2 text-sm text-neutral-800"><span style="width: 14px; height: 14px; border-radius: 4px; background: #d9a21b"></span>Draft or part paid</span>
        <span class="inline-flex items-center gap-2 text-sm text-neutral-800"><span style="width: 14px; height: 14px; border-radius: 4px; background: #d62828"></span>Overdue</span>
    </div>

    {{-- Tabs, search and status chips --}}
    <div class="mb-3 flex flex-wrap items-center gap-x-2 border-b border-neutral-200">
        <button type="button" wire:click="setTab('invoices')" @class([
            'border-b-2 px-3 py-2 text-sm font-medium transition-colors',
            'border-primary-500 text-primary-600' => $tab === 'invoices',
            'border-transparent text-neutral-500 hover:text-neutral-900' => $tab !== 'invoices',
        ])>Invoices <span class="text-xs text-neutral-400">{{ $invoiceTotal }}</span></button>
        <button type="button" wire:click="setTab('quotations')" @class([
            'border-b-2 px-3 py-2 text-sm font-medium transition-colors',
            'border-primary-500 text-primary-600' => $tab === 'quotations',
            'border-transparent text-neutral-500 hover:text-neutral-900' => $tab !== 'quotations',
        ])>Quotations <span class="text-xs text-neutral-400">{{ $quotationTotal }}</span></button>
    </div>

    <div class="mb-4 flex flex-wrap items-center gap-3">
        <input wire:model.live.debounce.300ms="search" type="text" placeholder="Search reference or customer" class="input" style="flex: 1 1 240px; font-size: 16px; min-height: 46px">
        <select wire:model.live="status" class="input" style="width: auto; font-size: 16px; min-height: 46px">
            @foreach ($statuses as $s)
                <option value="{{ $s }}">{{ $s === 'All' ? 'All statuses' : $s }}</option>
            @endforeach
        </select>
    </div>

    <div class="space-y-3">
        @if ($tab === 'invoices')
            @forelse ($invoices as $i)
                @php
                    $overdue = in_array($i->status, ['Unpaid', 'Part paid']) && $i->due_at !== null && $i->due_at->lt(today());
                    $stripe = match (true) {
                        $overdue => '#d62828',
                        $i->status === 'Paid' => '#15803d',
                        in_array($i->status, ['Draft', 'Part paid']) => '#d9a21b',
                        default => '#d4d4d8',
                    };
                    $pill = match ($i->status) {
                        'Paid' => 'pill-success',
                        'Unpaid' => 'pill-danger',
                        default => 'pill-amber',
                    };
                    $sub = $i->workOrder?->reference ?? ($i->contract ? 'Contract '.$i->contract->reference : '-');
                @endphp
                <a href="/invoices/{{ $i->id }}" wire:navigate wire:key="inv-{{ $i->id }}" class="card block hover:border-neutral-300"
                   style="border-left: 5px solid {{ $stripe }}; padding: 1rem 1.25rem">
                    <div class="flex flex-wrap items-center gap-x-6 gap-y-3">
                        <div style="flex: 0 0 7.5rem">
                            <p class="text-base font-bold text-neutral-900">{{ $i->reference }}</p>
                            <p class="text-sm text-neutral-500">{{ $sub }}</p>
                        </div>
                        <p class="text-base font-semibold text-neutral-900" style="flex: 1 1 11rem">{{ $i->customer->name }}</p>
                        <div class="flex items-center gap-6">
                            <div style="min-width: 7.5rem">
                                <p class="text-xs text-neutral-500">Amount</p>
                                <p class="font-mono text-sm font-bold text-neutral-900">KES {{ number_format($i->amount_minor / 100, 0) }}</p>
                            </div>
                            <div style="min-width: 7.5rem">
                                <p class="text-xs text-neutral-500">Paid</p>
                                <p class="font-mono text-sm font-bold text-neutral-900">KES {{ number_format($i->paid_minor / 100, 0) }}</p>
                            </div>
                        </div>
                        <div class="text-right" style="flex: 0 0 6.5rem">
                            <span class="{{ $pill }}"><span style="width: 6px; height: 6px; border-radius: 50%; background: currentColor"></span>{{ $i->status }}</span>
                            @if ($overdue)
                                <p class="mt-1 text-xs font-semibold text-critical-700">{{ $i->due_at->diffInDays(today()) }}d late</p>
                            @endif
                        </div>
                    </div>
                </a>
            @empty
                <div class="card text-center text-sm text-neutral-500">No invoices match this filter.</div>
            @endforelse
        @else
            @forelse ($quotations as $q)
                @php
                    $stripe = match (true) {
                        str_starts_with($q->status, 'Awaiting') => '#d9a21b',
                        $q->status === 'Sent back' => '#d62828',
                        default => '#15803d',
                    };
                    $pill = match (true) {
                        str_starts_with($q->status, 'Awaiting') => 'pill-amber',
                        $q->status === 'Sent back' => 'pill-danger',
                        default => 'pill-success',
                    };
                @endphp
                <a href="/quotations/{{ $q->id }}" wire:navigate wire:key="qt-{{ $q->id }}" class="card block hover:border-neutral-300"
                   style="border-left: 5px solid {{ $stripe }}; padding: 1rem 1.25rem">
                    <div class="flex flex-wrap items-center gap-x-6 gap-y-3">
                        <div style="flex: 0 0 7.5rem">
                            <p class="text-base font-bold text-neutral-900">{{ $q->reference }}</p>
                            <p class="text-sm text-neutral-500">{{ $q->created_at->format('d M Y') }}</p>
                        </div>
                        <p class="text-base font-semibold text-neutral-900" style="flex: 1 1 11rem">{{ $q->customer->name }}</p>
                        <div class="flex items-center gap-6">
                            <div style="min-width: 7.5rem">
                                <p class="text-xs text-neutral-500">Amount</p>
                                <p class="font-mono text-sm font-bold text-neutral-900">KES {{ number_format($q->totalMinor() / 100, 0) }}</p>
                            </div>
                            <div style="min-width: 7.5rem">
                                <p class="text-xs text-neutral-500">Approval</p>
                                <p class="text-sm font-semibold text-neutral-900">{{ $q->approval_threshold }}</p>
                            </div>
                        </div>
                        <div class="text-right" style="flex: 0 0 9rem">
                            <span class="{{ $pill }}"><span style="width: 6px; height: 6px; border-radius: 50%; background: currentColor"></span>{{ $q->status }}</span>
                        </div>
                    </div>
                </a>
            @empty
                <div class="card text-center text-sm text-neutral-500">No quotations match this filter.</div>
            @endforelse
        @endif
    </div>
</div>
