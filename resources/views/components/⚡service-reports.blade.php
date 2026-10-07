<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use App\Models\Country;
use App\Models\Customer;
use App\Models\User;
use App\Services\ManagerExport;
use App\Services\ManagerReports;
use App\Services\ServiceAdminReports;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;

new #[Layout('layouts.app', ['title' => 'Reports'])] class extends Component
{
    public const TABS = [
        'overview' => 'Overview',
        'quotations' => 'Quotations',
        'finance' => 'Finance',
        'technicians' => 'Technicians',
        'inventory' => 'Inventory',
        'contracts' => 'Contracts',
        'history' => 'Job history',
    ];

    // Which report sections each tab's "Export CSV" button writes out.
    public const CSV_TABS = [
        'overview' => 'summary,requests,nature,branches,pipeline',
        'quotations' => 'quotations,value,lpo,needs',
        'finance' => 'invoices,ageing,revenue',
        'technicians' => 'reports,techdocs,revenue',
        'inventory' => 'lowstock,category,used',
        'contracts' => 'contracts',
        'history' => 'history',
    ];

    #[Url]
    public string $tab = 'overview';

    // What is typed into the filter boxes...
    public string $fromInput = '';
    public string $toInput = '';
    public string $customerInput = '';
    public string $countryInput = '';
    public string $technicianInput = '';

    // ...and what has actually been applied.
    public string $from = '';
    public string $to = '';
    public string $customer = '';
    public string $country = '';
    public string $technician = '';

    public int $rowsShown = 15;

    public bool $showExport = false;
    public string $exportFrom = '';
    public string $exportTo = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->hasRole('Service Admin'), 403);

        if (! array_key_exists($this->tab, self::TABS)) {
            $this->tab = 'overview';
        }
    }

    public function setTab(string $tab): void
    {
        if (array_key_exists($tab, self::TABS)) {
            $this->tab = $tab;
        }
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
        $this->technician = $this->technicianInput;
        $this->rowsShown = 15;
    }

    public function resetFilter(): void
    {
        $this->reset(['fromInput', 'toInput', 'customerInput', 'countryInput', 'technicianInput', 'from', 'to', 'customer', 'country', 'technician']);
        $this->resetValidation();
        $this->rowsShown = 15;
    }

    public function showMore(): void
    {
        $this->rowsShown += 15;
    }

    protected function filters(): array
    {
        return ['from' => $this->from, 'to' => $this->to, 'customer' => $this->customer, 'country' => $this->country, 'technician' => $this->technician];
    }

    protected function streamSections(array $sections, string $name, bool $titles = true, array $intro = [])
    {
        return response()->streamDownload(function () use ($sections, $titles, $intro) {
            $out = fopen('php://output', 'w');
            foreach ($intro as $line) {
                fputcsv($out, $line);
            }
            foreach ($sections as [$title, $headers, $rows]) {
                if ($titles) {
                    fputcsv($out, [$title]);
                }
                fputcsv($out, $headers);
                foreach ($rows as $row) {
                    fputcsv($out, $row);
                }
                if ($titles) {
                    fputcsv($out, []);
                }
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv']);
    }

    // One tab's data as a CSV, for whatever filters are applied right now.
    public function exportCsv(string $tab)
    {
        abort_unless(isset(self::CSV_TABS[$tab]), 404);

        $d = ServiceAdminReports::build($this->filters());
        $sections = ServiceAdminReports::csvSections($d, self::CSV_TABS[$tab]);

        return $this->streamSections($sections, 'aea-'.$tab.'-'.now()->format('Y-m-d').'.csv', $tab !== 'history', $tab === 'history' ? [] : [['AEA Limited, '.self::TABS[$tab], $d['label']], []]);
    }

    public function exportPdf()
    {
        $d = ServiceAdminReports::build($this->filters());

        $pdf = Pdf::loadView('pdfs.manager-jobs', [
            'rows' => $d['history'],
            'label' => $d['label'],
            'manager' => auth()->user()->name,
        ])->setPaper('a4', 'landscape');

        return response()->streamDownload(fn () => print($pdf->output()), 'aea-job-history-'.now()->format('Y-m-d').'.pdf', ['Content-Type' => 'application/pdf']);
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

        return ServiceAdminReports::build(array_merge($this->filters(), ['from' => $this->exportFrom, 'to' => $this->exportTo]));
    }

    // The whole page as a PDF, charts included, for the chosen dates and the filters on screen.
    public function exportAllPdf()
    {
        $d = $this->exportData();

        $col = fn (array $rows, int $w = 330, int $h = 190) => ManagerExport::dataUri(ServiceAdminReports::columnSvg($rows, $w, $h));
        $pie = fn (array $mix) => ManagerExport::dataUri(ManagerExport::pieSvg($mix, 110));

        $pdf = Pdf::loadView('pdfs.service-admin-report', $d + [
            'admin' => auth()->user()->name,
            'img' => [
                'requestMix' => $pie($d['requestMix']),
                'quoteMix' => $pie($d['quoteMix']),
                'lpoMix' => $pie($d['lpoMix']),
                'reportMix' => $pie($d['reportMix']),
                'pipeline' => $col($d['pipelineRows']),
                'nature' => $col($d['natureRows']),
                'branches' => $col($d['branchRows']),
                'value' => $col($d['valueRows']),
                'ageing' => $col($d['ageing']),
                'revenue' => $col($d['revenueRows']),
                'workload' => $col($d['workloadRows']),
                'low' => $col($d['lowRows']),
                'category' => $col($d['catRows']),
                'used' => $col($d['usedRows']),
                'contracts' => $col($d['contractChart']),
            ],
        ])->setPaper('a4', 'portrait');

        $name = 'aea-service-report-'.Carbon::parse($this->exportFrom)->format('Y-m-d').'-to-'.Carbon::parse($this->exportTo)->format('Y-m-d').'.pdf';
        $this->showExport = false;

        return response()->streamDownload(fn () => print($pdf->output()), $name, ['Content-Type' => 'application/pdf']);
    }

    // Every section in one CSV, one after another.
    public function exportAllCsv()
    {
        $d = $this->exportData();
        $name = 'aea-service-report-'.Carbon::parse($this->exportFrom)->format('Y-m-d').'-to-'.Carbon::parse($this->exportTo)->format('Y-m-d').'.csv';
        $this->showExport = false;

        return $this->streamSections(ServiceAdminReports::csvSections($d), $name, true, [['AEA Limited service report', $d['label']], []]);
    }

    public function with(): array
    {
        $d = ServiceAdminReports::build($this->filters());

        return $d + [
            'historyShown' => $d['history']->take($this->rowsShown),
            'historyTotal' => ManagerReports::jobHistory([])->count(),
            'customers' => Customer::orderBy('name')->get(['id', 'name']),
            'countries' => Country::orderBy('name')->pluck('name'),
            'technicianList' => User::role('Technician')->orderBy('name')->get(['id', 'name']),
        ];
    }
};
?>

@php
    $kes = fn ($m, $compact = false) => \App\Services\ManagerReports::kes((int) $m, $compact);
    $btn = 'padding: 0.4rem 0.8rem';
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

                <p class="mt-3 text-xs text-neutral-400">
                    The customer, country and technician filters you have applied are kept. Live items (pipeline, LPOs awaited, overdue jobs, reorder list, flagged documents, contracts) are always as of today.
                </p>

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
            <label class="label" for="f-tech">Technician</label>
            <select id="f-tech" wire:model="technicianInput" class="input" style="font-size: 16px">
                <option value="">All</option>
                @foreach ($technicianList as $t)
                    <option value="{{ $t->id }}">{{ $t->name }}</option>
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

    {{-- KPIs --}}
    <div class="grid gap-3" style="grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));">
        <div class="card">
            <p class="text-xs font-medium text-neutral-500">Requests received</p>
            <p class="mt-2 font-mono text-xl font-bold whitespace-nowrap text-neutral-900">{{ $kpis['requests'] }}</p>
            <p class="mt-0.5 text-xs text-neutral-400">{{ $kpis['requestsOpen'] }} still open</p>
        </div>
        <div class="card">
            <p class="text-xs font-medium text-neutral-500">Request to job rate</p>
            <p class="mt-2 font-mono text-xl font-bold whitespace-nowrap text-neutral-900">{{ $kpis['winRate'] !== null ? $kpis['winRate'].'%' : '-' }}</p>
            <p class="mt-0.5 text-xs text-neutral-400">{{ $kpis['converted'] }} of {{ $kpis['requests'] }} became jobs</p>
        </div>
        <div class="card">
            <p class="text-xs font-medium text-neutral-500">Quotation pipeline</p>
            <p class="mt-2 font-mono text-xl font-bold whitespace-nowrap text-neutral-900">{{ $kes($kpis['pipelineMinor'], true) }}</p>
            <p class="mt-0.5 text-xs text-neutral-400">{{ $kpis['pipelineCount'] }} {{ $kpis['pipelineCount'] === 1 ? 'quotation' : 'quotations' }} in flight</p>
        </div>
        <div class="card">
            <p class="text-xs font-medium text-neutral-500">LPOs awaited</p>
            <p @class(['mt-2 font-mono text-xl font-bold whitespace-nowrap', 'text-amber-700' => $kpis['lpoAwaited'] > 0, 'text-neutral-900' => $kpis['lpoAwaited'] === 0])>{{ $kpis['lpoAwaited'] }}</p>
            <p class="mt-0.5 text-xs text-neutral-400">{{ $kes($kpis['lpoAwaitedMinor'], true) }} waiting on customers</p>
        </div>
        <div class="card">
            <p class="text-xs font-medium text-neutral-500">Jobs overdue</p>
            <p @class(['mt-2 font-mono text-xl font-bold whitespace-nowrap', 'text-critical-700' => $kpis['overdueJobs'] > 0, 'text-neutral-900' => $kpis['overdueJobs'] === 0])>{{ $kpis['overdueJobs'] }}</p>
            <p class="mt-0.5 text-xs text-neutral-400">of {{ $kpis['openJobs'] }} open jobs</p>
        </div>
        <div class="card">
            <p class="text-xs font-medium text-neutral-500">Items to reorder</p>
            <p @class(['mt-2 font-mono text-xl font-bold whitespace-nowrap', 'text-amber-700' => $kpis['reorder'] > 0, 'text-neutral-900' => $kpis['reorder'] === 0])>{{ $kpis['reorder'] }}</p>
            <p class="mt-0.5 text-xs text-neutral-400">of {{ $kpis['stockItems'] }} stock items</p>
        </div>
    </div>
    <div class="mb-5"></div>

    {{-- Tabs --}}
    <div class="mb-5 flex flex-wrap gap-2">
        @foreach ($this::TABS as $key => $name)
            <button type="button" wire:click="setTab('{{ $key }}')" wire:key="tab-{{ $key }}"
                    class="{{ $tab === $key ? 'btn-dark' : 'btn-outline' }}" style="padding: 0.5rem 1rem">{{ $name }}</button>
        @endforeach
    </div>

    {{-- ================= OVERVIEW ================= --}}
    @if ($tab === 'overview')
        <div class="mb-3 flex items-center gap-3">
            <h2 class="text-base font-semibold text-neutral-900">Intake and conversion</h2>
            <div class="flex-1 border-t border-neutral-200"></div>
            <button type="button" wire:click="exportCsv('overview')" class="btn-outline" style="{{ $btn }}">Export CSV</button>
        </div>
        <div class="flex flex-wrap gap-4">
            <div class="card" style="flex: 1 1 260px; min-width: 0">
                <h3 class="mb-4 text-sm font-semibold text-neutral-900">Requests by status</h3>
                <x-pie-chart :data="$requestMix" :size="150" />
            </div>
            <div class="card" style="flex: 2 1 420px; min-width: 0">
                <h3 class="mb-4 text-sm font-semibold text-neutral-900">Work pipeline</h3>
                <x-column-chart :data="$pipelineRows" :height="190" />
            </div>
            <div class="card" style="flex: 2 1 420px; min-width: 0">
                <h3 class="mb-4 text-sm font-semibold text-neutral-900">Jobs by nature of visit</h3>
                <x-column-chart :data="$natureRows" :height="190" />
            </div>
            <div class="card" style="flex: 1 1 260px; min-width: 0">
                <h3 class="mb-4 text-sm font-semibold text-neutral-900">Jobs by branch</h3>
                <x-column-chart :data="$branchRows" :height="190" />
            </div>
        </div>
    @endif

    {{-- ================= QUOTATIONS ================= --}}
    @if ($tab === 'quotations')
        <div class="mb-3 flex items-center gap-3">
            <h2 class="text-base font-semibold text-neutral-900">Quotations status</h2>
            <div class="flex-1 border-t border-neutral-200"></div>
            <button type="button" wire:click="exportCsv('quotations')" class="btn-outline" style="{{ $btn }}">Export CSV</button>
        </div>
        <div class="mb-4 grid gap-3" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));">
            <div class="card">
                <p class="text-xs font-medium text-neutral-500">Quotations raised</p>
                <p class="mt-2 font-mono text-2xl font-bold text-neutral-900">{{ $quoteTiles['raised'] }}</p>
            </div>
            <div class="card">
                <p class="text-xs font-medium text-neutral-500">Value won</p>
                <p class="mt-2 font-mono text-2xl font-bold text-neutral-900">{{ $kes($quoteTiles['wonMinor'], true) }}</p>
            </div>
            <div class="card">
                <p class="text-xs font-medium text-neutral-500">Approved quotes with an LPO</p>
                <p class="mt-2 font-mono text-2xl font-bold text-neutral-900">{{ $quoteTiles['lpoRate'] !== null ? $quoteTiles['lpoRate'].'%' : '-' }}</p>
            </div>
        </div>
        <div class="mb-4 flex flex-wrap gap-4">
            <div class="card" style="flex: 1 1 260px; min-width: 0">
                <h3 class="mb-4 text-sm font-semibold text-neutral-900">By status</h3>
                <x-pie-chart :data="$quoteMix" :size="150" />
            </div>
            <div class="card" style="flex: 1 1 260px; min-width: 0">
                <h3 class="mb-4 text-sm font-semibold text-neutral-900">LPO status</h3>
                <x-pie-chart :data="$lpoMix" :size="150" />
            </div>
            <div class="card" style="flex: 2 1 420px; min-width: 0">
                <h3 class="mb-1 text-sm font-semibold text-neutral-900">Value by status</h3>
                <p class="mb-4 text-xs text-neutral-400">{{ currency() }}, k is thousand and M is million</p>
                <x-column-chart :data="$valueRows" :height="190" />
            </div>
        </div>

        <div class="card" style="padding: 0">
            <h3 class="px-5 pt-5 text-sm font-semibold text-neutral-900">Quotations that need action</h3>
            <div class="mt-3 overflow-x-auto">
                <table class="table-clean" style="min-width: 640px">
                    <thead><tr><th>Quotation</th><th>Customer</th><th class="text-right">Amount</th><th>Waiting for</th><th class="text-right">Days</th></tr></thead>
                    <tbody>
                        @forelse ($needs->take(10) as $n)
                            <tr wire:key="need-{{ $n['q']->id }}">
                                <td><a href="/quotations/{{ $n['q']->id }}" wire:navigate class="font-mono text-xs font-bold text-neutral-900 hover:text-primary-600">{{ $n['q']->reference }}</a></td>
                                <td>{{ $n['q']->customer?->name }}</td>
                                <td class="whitespace-nowrap text-right font-mono text-xs">{{ $kes($n['q']->totalMinor()) }}</td>
                                <td><span class="{{ $n['q']->status === 'Sent back' ? 'pill-danger' : ($n['q']->status === 'Approved' ? 'pill-info' : 'pill-amber') }}">{{ $n['what'] }}</span></td>
                                <td class="text-right font-bold">{{ $n['days'] }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-center text-neutral-500">Nothing is waiting. Every quotation has moved on.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- ================= FINANCE ================= --}}
    @if ($tab === 'finance')
        <div class="mb-3 flex items-center gap-3">
            <h2 class="text-base font-semibold text-neutral-900">Invoices</h2>
            <div class="flex-1 border-t border-neutral-200"></div>
            <button type="button" wire:click="exportCsv('finance')" class="btn-outline" style="{{ $btn }}">Export CSV</button>
        </div>
        <div class="mb-4 grid gap-3" style="grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));">
            <div class="card" style="border-left: 5px solid #15803d">
                <p class="font-mono text-3xl font-bold" style="color: #15803d">{{ $finance['paidCount'] }}</p>
                <p class="mt-1 text-sm text-neutral-500">Paid, {{ $kes($finance['paidMinor']) }}</p>
            </div>
            <div class="card" style="border-left: 5px solid #d9a21b">
                <p class="font-mono text-3xl font-bold" style="color: #b8860b">{{ $finance['draftPartCount'] }}</p>
                <p class="mt-1 text-sm text-neutral-500">Draft or part paid, {{ $kes($finance['draftPartMinor']) }}</p>
            </div>
            <div class="card" style="border-left: 5px solid #d62828">
                <p class="font-mono text-3xl font-bold" style="color: #d62828">{{ $finance['overdueCount'] }}</p>
                <p class="mt-1 text-sm text-neutral-500">Overdue, {{ $kes($finance['overdueMinor']) }}</p>
            </div>
            <div class="card" style="border-left: 5px solid #1f2937">
                <p class="font-mono text-3xl font-bold text-neutral-900">{{ $finance['owingCount'] }}</p>
                <p class="mt-1 text-sm text-neutral-500">Still to collect, {{ $kes($finance['owingMinor']) }}</p>
            </div>
        </div>

        <div class="mb-4 flex flex-wrap gap-4">
            <div class="card" style="flex: 1 1 380px; min-width: 0">
                <h3 class="mb-1 text-sm font-semibold text-neutral-900">Money still to collect, by age</h3>
                <p class="mb-4 text-xs text-neutral-400">Balance in {{ currency() }}, grouped by days past the due date</p>
                <x-column-chart :data="$ageing" :height="190" />
            </div>
            <div class="card" style="flex: 1 1 380px; min-width: 0">
                <h3 class="mb-1 text-sm font-semibold text-neutral-900">Revenue by technician</h3>
                <p class="mb-4 text-xs text-neutral-400">{{ currency() }}, invoices issued in the chosen dates</p>
                <x-column-chart :data="$revenueRows" :height="190" />
            </div>
        </div>

        <div class="card" style="padding: 0">
            <h3 class="px-5 pt-5 text-sm font-semibold text-neutral-900">Revenue by technician</h3>
            <div class="mt-3 overflow-x-auto">
                <table class="table-clean" style="min-width: 600px">
                    <thead><tr><th>Technician</th><th>Branch</th><th>Jobs closed</th><th>Open jobs</th><th class="text-right">Revenue</th></tr></thead>
                    <tbody>
                        @forelse ($techRows as $t)
                            <tr wire:key="rv-{{ $t['user']->id }}">
                                <td class="font-semibold text-neutral-900">{{ $t['user']->name }}</td>
                                <td class="text-neutral-600">{{ $t['location'] ?: '-' }}</td>
                                <td>{{ $t['closed'] }}</td>
                                <td>{{ $t['open'] }}@if ($t['overdue'] > 0) <span class="text-xs font-semibold text-critical-700">&middot; {{ $t['overdue'] }} overdue</span>@endif</td>
                                <td class="text-right font-mono text-xs font-semibold">{{ $kes($t['revenueMinor']) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-center text-neutral-500">No technicians match these filters.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- ================= TECHNICIANS ================= --}}
    @if ($tab === 'technicians')
        <div class="mb-3 flex items-center gap-3">
            <h2 class="text-base font-semibold text-neutral-900">Technician reports</h2>
            <div class="flex-1 border-t border-neutral-200"></div>
            <button type="button" wire:click="exportCsv('technicians')" class="btn-outline" style="{{ $btn }}">Export CSV</button>
        </div>
        <div class="mb-4 grid gap-3" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));">
            <div class="card">
                <p class="text-xs font-medium text-neutral-500">Reports waiting for you to post</p>
                <p @class(['mt-2 font-mono text-2xl font-bold', 'text-amber-700' => $toPost > 0, 'text-neutral-900' => $toPost === 0])>{{ $toPost }}</p>
            </div>
            <div class="card">
                <p class="text-xs font-medium text-neutral-500">Technician documents flagged</p>
                <p @class(['mt-2 font-mono text-2xl font-bold', 'text-critical-700' => $techDocs->count() > 0, 'text-neutral-900' => $techDocs->count() === 0])>{{ $techDocs->count() }}</p>
                <p class="mt-0.5 text-xs text-neutral-400">expired or due within {{ setting('document_warn_days') }} days</p>
            </div>
        </div>
        <div class="mb-4 flex flex-wrap gap-4">
            <div class="card" style="flex: 1 1 300px; min-width: 0">
                <h3 class="mb-4 text-sm font-semibold text-neutral-900">Reports by status</h3>
                <x-pie-chart :data="$reportMix" :size="150" />
            </div>
            <div class="card" style="flex: 2 1 420px; min-width: 0">
                <h3 class="mb-4 text-sm font-semibold text-neutral-900">Open jobs per technician</h3>
                <x-column-chart :data="$workloadRows" :height="190" />
            </div>
        </div>

        <div class="card" style="padding: 0">
            <h3 class="px-5 pt-5 text-sm font-semibold text-neutral-900">Technician documents and certificates</h3>
            <div class="mt-3 overflow-x-auto">
                <table class="table-clean" style="min-width: 560px">
                    <thead><tr><th>Technician</th><th>Document</th><th>Due</th><th>Status</th></tr></thead>
                    <tbody>
                        @forelse ($techDocs as $doc)
                            <tr wire:key="td-{{ $doc->id }}">
                                <td class="font-semibold text-neutral-900">{{ $doc->technician->name }}</td>
                                <td>{{ $doc->document_type }}</td>
                                <td>{{ $doc->dueLabel() }}</td>
                                <td>
                                    @if ($doc->expiresAt()->lt(today()))
                                        <span class="pill-danger">Overdue</span>
                                    @else
                                        <span class="pill-amber">Due soon</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-center text-neutral-500">No document is expired or close to expiry.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- ================= INVENTORY ================= --}}
    @if ($tab === 'inventory')
        <div class="mb-3 flex items-center gap-3">
            <h2 class="text-base font-semibold text-neutral-900">Inventory</h2>
            <div class="flex-1 border-t border-neutral-200"></div>
            <button type="button" wire:click="exportCsv('inventory')" class="btn-outline" style="{{ $btn }}">Export CSV</button>
        </div>
        <div class="mb-4 grid gap-3" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));">
            <div class="card">
                <p class="text-xs font-medium text-neutral-500">Items to reorder</p>
                <p @class(['mt-2 font-mono text-2xl font-bold', 'text-amber-700' => $kpis['reorder'] > 0, 'text-neutral-900' => $kpis['reorder'] === 0])>{{ $kpis['reorder'] }}</p>
            </div>
            <div class="card">
                <p class="text-xs font-medium text-neutral-500">Units in stock</p>
                <p class="mt-2 font-mono text-2xl font-bold text-neutral-900">{{ number_format($inventory['units']) }}</p>
            </div>
            <div class="card">
                <p class="text-xs font-medium text-neutral-500">Stock value at cost</p>
                <p class="mt-2 font-mono text-2xl font-bold text-neutral-900">{{ $kes($inventory['valueMinor'], true) }}</p>
            </div>
        </div>
        <div class="flex flex-wrap gap-4">
            <div class="card" style="flex: 1 1 260px; min-width: 0">
                <h3 class="mb-1 text-sm font-semibold text-neutral-900">Stock at or below reorder level</h3>
                <p class="mb-4 text-xs text-neutral-400">Bar is what is in stock, the dashed line is the reorder level</p>
                <x-column-chart :data="$lowRows" :height="190" />
            </div>
            <div class="card" style="flex: 1 1 330px; min-width: 0">
                <h3 class="mb-4 text-sm font-semibold text-neutral-900">Units in stock by category</h3>
                <x-column-chart :data="$catRows" :height="190" />
            </div>
            <div class="card" style="flex: 1 1 350px; min-width: 0">
                <h3 class="mb-4 text-sm font-semibold text-neutral-900">Stock used {{ $inventory['usedLabel'] }}</h3>
                <x-column-chart :data="$usedRows" :height="190" />
            </div>
        </div>
    @endif

    {{-- ================= CONTRACTS ================= --}}
    @if ($tab === 'contracts')
        @php
            $expiring = $contractRows->whereIn('state', ['Expiring soon', 'Expired'])->count();
            $outOfVisits = $contractRows->where('left', 0)->count();
        @endphp
        <div class="mb-3 flex items-center gap-3">
            <h2 class="text-base font-semibold text-neutral-900">Contracts, renewal risk</h2>
            <div class="flex-1 border-t border-neutral-200"></div>
            <button type="button" wire:click="exportCsv('contracts')" class="btn-outline" style="{{ $btn }}">Export CSV</button>
        </div>
        <div class="mb-4 grid gap-3" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));">
            <div class="card">
                <p class="text-xs font-medium text-neutral-500">Active contracts</p>
                <p class="mt-2 font-mono text-2xl font-bold text-neutral-900">{{ $contractRows->count() }}</p>
            </div>
            <div class="card">
                <p class="text-xs font-medium text-neutral-500">Ending within {{ setting('contract_warn_days') }} days</p>
                <p @class(['mt-2 font-mono text-2xl font-bold', 'text-amber-700' => $expiring > 0, 'text-neutral-900' => $expiring === 0])>{{ $expiring }}</p>
            </div>
            <div class="card">
                <p class="text-xs font-medium text-neutral-500">Out of visits</p>
                <p @class(['mt-2 font-mono text-2xl font-bold', 'text-critical-700' => $outOfVisits > 0, 'text-neutral-900' => $outOfVisits === 0])>{{ $outOfVisits }}</p>
            </div>
        </div>
        <div class="flex flex-wrap gap-4">
            <div class="card" style="flex: 1 1 320px; min-width: 0">
                <h3 class="mb-4 text-sm font-semibold text-neutral-900">Visits remaining, lowest first</h3>
                <x-column-chart :data="$contractChart" :height="190" />
            </div>
            <div class="card" style="padding: 0; flex: 2 1 440px; min-width: 0; align-self: flex-start">
                <div class="overflow-x-auto">
                    <table class="table-clean" style="min-width: 520px">
                        <thead><tr><th>Contract</th><th>Customer</th><th>Visits left</th><th>Ends</th><th>Status</th></tr></thead>
                        <tbody>
                            @forelse ($contractRows as $r)
                                <tr wire:key="ct-{{ $r['c']->id }}">
                                    <td><a href="/contracts/{{ $r['c']->id }}" wire:navigate class="font-mono text-xs font-bold text-neutral-900 hover:text-primary-600">{{ $r['c']->reference }}</a></td>
                                    <td>{{ $r['c']->customer?->name }}</td>
                                    <td class="font-bold">{{ $r['left'] }}</td>
                                    <td class="whitespace-nowrap">{{ $r['c']->ends_at->format('d M Y') }}</td>
                                    <td class="whitespace-nowrap"><span class="{{ $r['state'] === 'Expired' ? 'pill-danger' : ($r['state'] === 'Expiring soon' ? 'pill-amber' : 'pill-success') }}">{{ $r['state'] }}</span></td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="text-center text-neutral-500">No active contracts match these filters.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif

    {{-- ================= JOB HISTORY ================= --}}
    @if ($tab === 'history')
        <div class="mb-3 flex flex-wrap items-center gap-3">
            <h2 class="text-base font-semibold text-neutral-900">Job history</h2>
            <div class="flex-1 border-t border-neutral-200"></div>
            <button type="button" wire:click="exportPdf" wire:loading.attr="disabled" class="btn-outline" style="{{ $btn }}">Export PDF</button>
            <button type="button" wire:click="exportCsv('history')" wire:loading.attr="disabled" class="btn-outline" style="{{ $btn }}">Export CSV</button>
        </div>
        <p class="mb-3 text-sm text-neutral-500">{{ $history->count() }} of {{ $historyTotal }} jobs match the filters above</p>

        <div class="card" style="padding: 0">
            <div class="overflow-x-auto">
                <table class="table-clean" style="min-width: 920px">
                    <thead>
                        <tr>
                            <th>Job</th><th>Customer</th><th>Branch</th><th>Country</th><th>Technician</th>
                            <th>Nature of visit</th><th>Date</th><th class="text-right">Value</th><th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($historyShown as $r)
                            @php
                                $pill = match (true) {
                                    $r['status'] === 'Overdue' => 'pill-danger',
                                    $r['status'] === 'Assigned' => 'pill-neutral',
                                    in_array($r['status'], ['On site', 'Awaiting review']) => 'pill-info',
                                    default => 'pill-success',
                                };
                            @endphp
                            <tr wire:key="jh-{{ $r['job']->id }}">
                                <td class="whitespace-nowrap"><a href="/jobs/{{ $r['job']->id }}" wire:navigate class="font-mono text-xs font-bold text-neutral-900 hover:text-primary-600">{{ $r['job']->reference }}</a></td>
                                <td>{{ $r['job']->customer->name }}</td>
                                <td>{{ $r['branch'] }}</td>
                                <td>{{ $r['country'] }}</td>
                                <td>{{ $r['technician'] }}</td>
                                <td class="text-neutral-600">{{ $r['job']->nature_of_visit }}</td>
                                <td class="whitespace-nowrap">{{ $r['job']->due_date->format('d M Y') }}</td>
                                <td class="whitespace-nowrap text-right font-mono text-xs">{{ $r['valueMinor'] > 0 ? $kes($r['valueMinor']) : '-' }}</td>
                                <td><span class="{{ $pill }}">{{ $r['status'] }}</span></td>
                            </tr>
                        @empty
                            <tr><td colspan="9" class="text-center text-neutral-500">No jobs match these filters.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($history->count() > $rowsShown)
                <div class="border-t border-neutral-100 px-5 py-3 text-center">
                    <button type="button" wire:click="showMore" class="text-sm font-semibold text-primary-600 hover:text-primary-700">
                        Show more ({{ $history->count() - $rowsShown }} left)
                    </button>
                </div>
            @else
                <div style="height: 0.5rem"></div>
            @endif
        </div>
    @endif
</div>
