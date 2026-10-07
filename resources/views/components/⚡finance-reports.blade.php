<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Models\Country;
use App\Models\Customer;
use App\Services\FinanceReports;
use App\Services\ManagerExport;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;

new #[Layout('layouts.app', ['title' => 'Reports'])] class extends Component
{
    // What is typed into the filter boxes...
    public string $fromInput = '';
    public string $toInput = '';
    public string $customerInput = '';
    public string $countryInput = '';

    // ...and what has actually been applied.
    public string $from = '';
    public string $to = '';
    public string $customer = '';
    public string $country = '';

    public bool $showExport = false;
    public string $exportFrom = '';
    public string $exportTo = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->hasRole('Finance'), 403);
    }

    public function applyFilter(): void
    {
        $this->validate([
            'fromInput' => ['nullable', 'date'],
            'toInput' => ['nullable', 'date', 'after_or_equal:fromInput'],
        ], [
            'toInput.after_or_equal' => 'The end date cannot be before the start date.',
        ]);

        $this->from = $this->fromInput;
        $this->to = $this->toInput;
        $this->customer = $this->customerInput;
        $this->country = $this->countryInput;
    }

    public function resetFilter(): void
    {
        $this->reset(['fromInput', 'toInput', 'customerInput', 'countryInput', 'from', 'to', 'customer', 'country']);
        $this->resetValidation();
    }

    protected function filters(): array
    {
        return ['from' => $this->from, 'to' => $this->to, 'customer' => $this->customer, 'country' => $this->country];
    }

    protected function streamSections(array $sections, string $name, array $intro = [])
    {
        return response()->streamDownload(function () use ($sections, $intro) {
            $out = fopen('php://output', 'w');
            foreach ($intro as $line) {
                fputcsv($out, $line);
            }
            foreach ($sections as [$title, $headers, $rows]) {
                fputcsv($out, [$title]);
                fputcsv($out, $headers);
                foreach ($rows as $row) {
                    fputcsv($out, $row);
                }
                fputcsv($out, []);
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv']);
    }

    public function exportCustomers()
    {
        $d = FinanceReports::build($this->filters());

        return $this->streamSections(FinanceReports::csvSections($d, 'customers'), 'aea-revenue-by-customer-'.now()->format('Y-m-d').'.csv', [['AEA Limited, Revenue by customer', $d['label']], []]);
    }

    public function openExport(): void
    {
        $this->exportFrom = $this->from !== '' ? $this->from : now()->startOfMonth()->toDateString();
        $this->exportTo = $this->to !== '' ? $this->to : now()->toDateString();
        $this->resetValidation();
        $this->showExport = true;
    }

    public function closeExport(): void
    {
        $this->showExport = false;
        $this->resetValidation();
    }

    public function exportPreset(string $which): void
    {
        [$from, $to] = match ($which) {
            'last_month' => [now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow()->endOfMonth()],
            'last_90' => [now()->subDays(89), now()],
            'this_year' => [now()->startOfYear(), now()],
            default => [now()->startOfMonth(), now()],
        };

        $this->exportFrom = $from->toDateString();
        $this->exportTo = $to->toDateString();
        $this->resetValidation();
    }

    protected function exportData(): array
    {
        $this->validate([
            'exportFrom' => ['required', 'date'],
            'exportTo' => ['required', 'date', 'after_or_equal:exportFrom'],
        ], [
            'exportFrom.required' => 'Choose a start date.',
            'exportTo.required' => 'Choose an end date.',
            'exportTo.after_or_equal' => 'The end date cannot be before the start date.',
        ]);

        return FinanceReports::build(array_merge($this->filters(), ['from' => $this->exportFrom, 'to' => $this->exportTo]));
    }

    // The whole page as a PDF, charts included, for the chosen dates and the filters on screen.
    public function exportAllPdf()
    {
        $d = $this->exportData();

        $pdf = Pdf::loadView('pdfs.finance-report', $d + [
            'finance' => auth()->user()->name,
            'img' => [
                'status' => ManagerExport::dataUri(ManagerExport::pieSvg($d['statusMix'], 110)),
                'ageing' => ManagerExport::dataUri(ManagerExport::pieSvg($d['ageingMix'], 110)),
            ],
        ])->setPaper('a4', 'portrait');

        $name = 'aea-finance-report-'.Carbon::parse($this->exportFrom)->format('Y-m-d').'-to-'.Carbon::parse($this->exportTo)->format('Y-m-d').'.pdf';
        $this->showExport = false;

        return response()->streamDownload(fn () => print($pdf->output()), $name, ['Content-Type' => 'application/pdf']);
    }

    // Every section in one CSV, one after another.
    public function exportAllCsv()
    {
        $d = $this->exportData();
        $name = 'aea-finance-report-'.Carbon::parse($this->exportFrom)->format('Y-m-d').'-to-'.Carbon::parse($this->exportTo)->format('Y-m-d').'.csv';
        $this->showExport = false;

        return $this->streamSections(FinanceReports::csvSections($d), $name, [['AEA Limited finance report', $d['label']], []]);
    }

    public function with(): array
    {
        return FinanceReports::build($this->filters()) + [
            'customers' => Customer::orderBy('name')->get(['id', 'name']),
            'countries' => Country::orderBy('name')->pluck('name'),
        ];
    }
};
?>

@php
    $kes = fn ($m, $compact = false) => \App\Services\ManagerReports::kes((int) $m, $compact);
    $statusPill = ['Draft' => 'pill-neutral', 'Unpaid' => 'pill-info', 'Part paid' => 'pill-amber', 'Paid' => 'pill-success', 'Overdue' => 'pill-danger'];
    $mixTotal = max(array_sum(array_column($statusMix, 'value')), 1);
@endphp

<div class="mx-auto" style="max-width: 72rem">
    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-neutral-900">Reports</h1>
            <p class="text-sm text-neutral-500">{{ $label }}</p>
        </div>
        <button type="button" wire:click="openExport" wire:loading.attr="disabled" class="btn-dark">
            <x-icon name="file-earmark-text" class="h-4 w-4" />
            Export all reports
        </button>
    </div>

    {{-- Export panel --}}
    @if ($showExport)
        <div style="position: fixed; inset: 0; z-index: 60; display: flex; align-items: center; justify-content: center; padding: 1rem; background: rgba(0, 0, 0, 0.45)" wire:click.self="closeExport">
            <div class="card" style="width: 100%; max-width: 30rem; max-height: 92vh; overflow-y: auto">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h2 class="text-base font-semibold text-neutral-900">Export all reports</h2>
                        <p class="mt-1 text-sm text-neutral-500">Every section on this page. Pick the dates the figures should cover.</p>
                    </div>
                    <button type="button" wire:click="closeExport" class="btn-ghost" aria-label="Close">Close</button>
                </div>

                <div class="mt-4 flex flex-wrap gap-2">
                    <button type="button" wire:click="exportPreset('this_month')" class="btn-outline" style="padding: 0.35rem 0.75rem">This month</button>
                    <button type="button" wire:click="exportPreset('last_month')" class="btn-outline" style="padding: 0.35rem 0.75rem">Last month</button>
                    <button type="button" wire:click="exportPreset('last_90')" class="btn-outline" style="padding: 0.35rem 0.75rem">Last 90 days</button>
                    <button type="button" wire:click="exportPreset('this_year')" class="btn-outline" style="padding: 0.35rem 0.75rem">This year</button>
                </div>

                <div class="mt-4 grid gap-3" style="grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));">
                    <div>
                        <label class="label" for="ex-from">From</label>
                        <input id="ex-from" type="date" wire:model="exportFrom" class="input" style="font-size: 16px">
                        @error('exportFrom') <p class="mt-1 text-xs text-critical-700">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label" for="ex-to">To</label>
                        <input id="ex-to" type="date" wire:model="exportTo" class="input" style="font-size: 16px">
                        @error('exportTo') <p class="mt-1 text-xs text-critical-700">{{ $message }}</p> @enderror
                    </div>
                </div>

                <p class="mt-3 text-xs text-neutral-400">The customer and country filters you have applied are kept. Dates follow the invoice issue date.</p>

                <div class="mt-5 flex flex-wrap gap-2">
                    <button type="button" wire:click="exportAllPdf" wire:loading.attr="disabled" wire:target="exportAllPdf,exportAllCsv" class="btn-primary">
                        <span wire:loading.remove wire:target="exportAllPdf">Download PDF report</span>
                        <span wire:loading wire:target="exportAllPdf">Preparing...</span>
                    </button>
                    <button type="button" wire:click="exportAllCsv" wire:loading.attr="disabled" wire:target="exportAllPdf,exportAllCsv" class="btn-outline">Download CSV (data only)</button>
                </div>
            </div>
        </div>
    @endif

    {{-- Filters --}}
    <form wire:submit="applyFilter" class="card mb-4 grid items-end gap-3" style="grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));">
        <div>
            <label class="label" for="f-customer">Customer</label>
            <select id="f-customer" wire:model="customerInput" class="input" style="font-size: 16px">
                <option value="">All</option>
                @foreach ($customers as $c)
                    <option value="{{ $c->id }}">{{ $c->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="f-country">Country</label>
            <select id="f-country" wire:model="countryInput" class="input" style="font-size: 16px">
                <option value="">All</option>
                @foreach ($countries as $name)
                    <option value="{{ $name }}">{{ $name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="f-from">From</label>
            <input id="f-from" type="date" wire:model="fromInput" class="input" style="font-size: 16px">
        </div>
        <div>
            <label class="label" for="f-to">To</label>
            <input id="f-to" type="date" wire:model="toInput" class="input" style="font-size: 16px">
            @error('toInput') <p class="mt-1 text-xs text-critical-700">{{ $message }}</p> @enderror
        </div>
        <div class="flex gap-2">
            <button type="submit" class="btn-primary">Apply filter</button>
            <button type="button" wire:click="resetFilter" class="btn-outline">Reset</button>
        </div>
    </form>

    {{-- Tiles --}}
    <div class="grid gap-3" style="grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));">
        <div class="card">
            <p class="text-xs font-medium text-neutral-500">{{ $kpis['revenueLabel'] }}</p>
            <p class="mt-2 font-mono text-xl font-bold whitespace-nowrap text-neutral-900">{{ $kes($kpis['revenueMinor'], true) }}</p>
            <p class="mt-0.5 text-xs text-neutral-400">{{ $kpis['revenueCount'] }} {{ $kpis['revenueCount'] === 1 ? 'invoice' : 'invoices' }} issued</p>
        </div>
        <div class="card">
            <p class="text-xs font-medium text-neutral-500">Outstanding</p>
            <p class="mt-2 font-mono text-xl font-bold whitespace-nowrap text-neutral-900">{{ $kes($kpis['outstandingMinor'], true) }}</p>
            <p class="mt-0.5 text-xs text-neutral-400">{{ $kpis['outstandingCount'] }} {{ $kpis['outstandingCount'] === 1 ? 'invoice' : 'invoices' }} still to collect</p>
        </div>
        <div class="card">
            <p class="text-xs font-medium text-neutral-500">Overdue</p>
            <p @class(['mt-2 font-mono text-xl font-bold whitespace-nowrap', 'text-critical-700' => $kpis['overdueMinor'] > 0, 'text-neutral-900' => $kpis['overdueMinor'] === 0])>{{ $kes($kpis['overdueMinor'], true) }}</p>
            <p class="mt-0.5 text-xs text-neutral-400">{{ $kpis['overdueCount'] }} past the due date</p>
        </div>
        <div class="card">
            <p class="text-xs font-medium text-neutral-500">VAT collected</p>
            <p class="mt-2 font-mono text-xl font-bold whitespace-nowrap text-neutral-900">{{ $kes($kpis['vatMinor'], true) }}</p>
            <p class="mt-0.5 text-xs text-neutral-400">on the same invoices as revenue</p>
        </div>
    </div>
    <p class="mb-5 mt-2 text-xs text-neutral-400">Drafts are not counted as revenue or as money owed. Dates follow the invoice issue date.</p>

    {{-- Revenue by customer --}}
    <div class="mb-3 flex items-center gap-3">
        <h2 class="text-base font-semibold text-neutral-900">Revenue by customer</h2>
        <div class="flex-1 border-t border-neutral-200"></div>
        <button type="button" wire:click="exportCustomers" class="btn-outline" style="padding: 0.4rem 0.8rem">Export CSV</button>
    </div>
    <div class="card mb-6" style="padding: 0">
        <div class="overflow-x-auto">
            <table class="table-clean" style="min-width: 420px">
                <thead><tr><th>Customer</th><th>Invoices</th><th class="text-right">Revenue</th></tr></thead>
                <tbody>
                    @forelse ($customerRows as $r)
                        <tr wire:key="cr-{{ $loop->index }}">
                            <td class="font-semibold text-neutral-900">{{ $r['name'] }}</td>
                            <td>{{ $r['count'] }}</td>
                            <td class="text-right font-mono text-xs font-semibold">{{ $kes($r['minor']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="text-center text-neutral-500">No invoices match these filters.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Branch and status --}}
    <div class="mb-6 flex flex-wrap gap-4">
        <div class="card" style="flex: 2 1 420px; min-width: 0">
            <h3 class="mb-1 text-sm font-semibold text-neutral-900">Revenue by branch</h3>
            <p class="mb-4 text-xs text-neutral-400">{{ currency() }}, by the customer's branch</p>
            <x-column-chart :data="$branchRows" :height="190" />
        </div>
        <div class="card" style="flex: 1 1 300px; min-width: 0">
            <h3 class="mb-4 text-sm font-semibold text-neutral-900">Invoices by status</h3>
            <x-pie-chart :data="$statusMix" :size="150" />
        </div>
    </div>

    {{-- Revenue by technician --}}
    <div class="mb-3 flex items-center gap-3">
        <h2 class="text-base font-semibold text-neutral-900">Revenue by technician</h2>
        <div class="flex-1 border-t border-neutral-200"></div>
    </div>
    <div class="card mb-6" style="padding: 0">
        <div class="overflow-x-auto">
            <table class="table-clean" style="min-width: 420px">
                <thead><tr><th>Technician</th><th>Invoices</th><th class="text-right">Revenue</th></tr></thead>
                <tbody>
                    @forelse ($techRows as $r)
                        <tr wire:key="tr-{{ $loop->index }}">
                            <td class="font-semibold text-neutral-900">{{ $r['name'] }}</td>
                            <td>{{ $r['count'] }}</td>
                            <td class="text-right font-mono text-xs font-semibold">{{ $kes($r['minor']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="text-center text-neutral-500">No invoices match these filters.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Outstanding and overdue --}}
    <div class="mb-3 flex items-center gap-3">
        <h2 class="text-base font-semibold text-neutral-900">Outstanding and overdue invoices</h2>
        <div class="flex-1 border-t border-neutral-200"></div>
    </div>
    <div class="card mb-6" style="padding: 0">
        <div class="overflow-x-auto">
            <table class="table-clean" style="min-width: 640px">
                <thead><tr><th>Invoice</th><th>Customer</th><th>Due</th><th>Amount</th><th>Paid</th><th>Status</th></tr></thead>
                <tbody>
                    @forelse ($owingRows as $i)
                        @php $shown = \App\Services\FinanceReports::shownStatus($i); @endphp
                        <tr wire:key="ow-{{ $i->id }}">
                            <td class="whitespace-nowrap font-mono font-bold"><a href="/invoices/{{ $i->id }}" wire:navigate class="text-neutral-900 hover:underline">{{ $i->reference }}</a></td>
                            <td>{{ $i->customer?->name }}</td>
                            <td class="whitespace-nowrap">{{ $i->due_at->format('d M Y') }}</td>
                            <td class="whitespace-nowrap">{{ $kes($i->amount_minor) }}</td>
                            <td class="whitespace-nowrap">{{ $kes($i->paid_minor) }}</td>
                            <td><span class="{{ $statusPill[$shown] ?? 'pill-neutral' }}">{{ $shown }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-neutral-500">Nothing outstanding for these filters.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Aging --}}
    <div class="mb-3 flex items-center gap-3">
        <h2 class="text-base font-semibold text-neutral-900">Aging</h2>
        <div class="flex-1 border-t border-neutral-200"></div>
    </div>
    <div class="mb-6 flex flex-wrap gap-4">
        <div class="card" style="flex: 1 1 300px; min-width: 0">
            <x-pie-chart :data="collect($ageingMix)->map(fn ($a) => $a + ['value' => (int) round($a['value'] / 100), 'valueLabel' => $kes($a['value'], true)])->all()" :size="150" />
        </div>
        <div class="card" style="flex: 2 1 420px; min-width: 0; padding: 0">
            <div class="overflow-x-auto">
                <table class="table-clean" style="min-width: 380px">
                    <thead><tr><th>Bucket</th><th>Invoices</th><th class="text-right">Amount</th></tr></thead>
                    <tbody>
                        @foreach ($ageing as $a)
                            <tr wire:key="ag-{{ $loop->index }}">
                                <td><span style="display: inline-block; width: 9px; height: 9px; border-radius: 50%; margin-right: 0.5rem; background: {{ $a['color'] }}"></span>{{ $a['label'] }}</td>
                                <td>{{ $a['count'] }}</td>
                                <td class="text-right font-mono text-xs font-semibold">{{ $kes($a['value']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- VAT summary --}}
    <div class="mb-3 flex items-center gap-3">
        <h2 class="text-base font-semibold text-neutral-900">VAT summary</h2>
        <div class="flex-1 border-t border-neutral-200"></div>
    </div>
    <div class="card" style="padding: 0">
        <div class="overflow-x-auto">
            <table class="table-clean" style="min-width: 520px">
                <thead><tr><th>Period</th><th>Taxable revenue</th><th>VAT</th><th>Currency</th></tr></thead>
                <tbody>
                    @forelse ($vatRows as $r)
                        <tr wire:key="vat-{{ $loop->index }}">
                            <td class="font-semibold text-neutral-900">{{ $r['period'] }}</td>
                            <td>{{ $r['currency'] }} {{ number_format($r['taxable'] / 100) }}</td>
                            <td>{{ $r['currency'] }} {{ number_format($r['vat'] / 100) }}</td>
                            <td>{{ $r['currency'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-center text-neutral-500">No invoices match these filters.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
