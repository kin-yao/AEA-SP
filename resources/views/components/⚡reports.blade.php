<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Models\Country;
use App\Models\Customer;
use App\Services\ManagerReports;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Services\ManagerExport;
use Carbon\Carbon;

new #[Layout('layouts.app', ['title' => 'Reports'])] class extends Component
{
    // What the person is typing into the filter boxes...
    public string $fromInput = '';
    public string $toInput = '';
    public string $customerInput = '';
    public string $countryInput = '';

    // ...and what has actually been applied (after "Apply filter").
    public string $from = '';
    public string $to = '';
    public string $customer = '';
    public string $country = '';

    public int $rowsShown = 15;

    public bool $showExport = false;
    public string $exportFrom = '';
    public string $exportTo = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->hasRole('Manager'), 403);
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
        $this->rowsShown = 15;
    }

    public function resetFilter(): void
    {
        $this->reset(['fromInput', 'toInput', 'customerInput', 'countryInput', 'from', 'to', 'customer', 'country']);
        $this->resetValidation();
        $this->rowsShown = 15;
    }

    public function showMore(): void
    {
        $this->rowsShown += 15;
    }

    protected function filters(): array
    {
        return ['from' => $this->from, 'to' => $this->to, 'customer' => $this->customer, 'country' => $this->country];
    }

    protected function filterLabel(): string
    {
        $parts = [];
        if ($this->from !== '' || $this->to !== '') {
            $parts[] = ($this->from !== '' ? date('d M Y', strtotime($this->from)) : 'start').' to '.($this->to !== '' ? date('d M Y', strtotime($this->to)) : 'today');
        }
        if ($this->customer !== '') {
            $parts[] = Customer::find($this->customer)?->name ?? 'one customer';
        }
        if ($this->country !== '') {
            $parts[] = $this->country;
        }

        return $parts ? implode(' | ', $parts) : 'All jobs';
    }

    protected function csvRows($rows): array
    {
        return $rows->map(fn ($r) => [
            $r['job']->reference,
            $r['job']->customer->name,
            $r['branch'],
            $r['country'],
            $r['technician'],
            $r['job']->nature_of_visit,
            $r['job']->due_date->format('Y-m-d'),
            $r['valueMinor'] > 0 ? number_format($r['valueMinor'] / 100, 2, '.', '') : '',
            $r['status'],
        ])->all();
    }

    protected const JOB_HEADERS = ['Job', 'Customer', 'Branch', 'Country', 'Technician', 'Nature of visit', 'Due date', 'Value (KES)', 'Status'];

    public function exportCsv()
    {
        $rows = $this->csvRows(ManagerReports::jobHistory($this->filters()));

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, self::JOB_HEADERS);
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, 'aea-job-history-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }

    public function exportPdf()
    {
        $rows = ManagerReports::jobHistory($this->filters());

        $pdf = Pdf::loadView('pdfs.manager-jobs', [
            'rows' => $rows,
            'label' => $this->filterLabel(),
            'manager' => auth()->user()->name,
        ])->setPaper('a4', 'landscape');

        return response()->streamDownload(fn () => print($pdf->output()), 'aea-job-history-'.now()->format('Y-m-d').'.pdf', ['Content-Type' => 'application/pdf']);
    }

    public function openExport(): void
    {
        $this->exportFrom = now()->startOfMonth()->toDateString();
        $this->exportTo = now()->toDateString();
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

        return ManagerExport::build(Carbon::parse($this->exportFrom), Carbon::parse($this->exportTo));
    }

    // The whole page as a PDF, charts included, for the chosen dates.
    public function exportAllPdf()
    {
        $d = $this->exportData();

        $pdf = Pdf::loadView('pdfs.manager-report', $d + [
            'manager' => auth()->user()->name,
            'trendImg' => ManagerExport::dataUri(ManagerExport::lineSvg($d['trend']['labels'], $d['trend']['values'])),
            'pieImg' => ManagerExport::dataUri(ManagerExport::pieSvg($d['mix'])),
        ])->setPaper('a4', 'portrait');

        $name = 'aea-report-'.$d['from']->format('Y-m-d').'-to-'.$d['to']->format('Y-m-d').'.pdf';
        $this->showExport = false;

        return response()->streamDownload(fn () => print($pdf->output()), $name, ['Content-Type' => 'application/pdf']);
    }

    // The same numbers as plain data, every report one after another in a single CSV.
    public function exportAllCsv()
    {
        $d = $this->exportData();
        $range = $d['from']->format('d M Y').' to '.$d['to']->format('d M Y');
        $name = 'aea-report-'.$d['from']->format('Y-m-d').'-to-'.$d['to']->format('Y-m-d').'.csv';
        $this->showExport = false;

        return response()->streamDownload(function () use ($d, $range) {
            $out = fopen('php://output', 'w');

            fputcsv($out, ['AEA Limited management report', $range]);
            fputcsv($out, []);
            fputcsv($out, ['Summary']);
            fputcsv($out, ['Revenue (KES)', number_format($d['revenueMinor'] / 100, 2, '.', '')]);
            fputcsv($out, ['Jobs closed', $d['jobsClosed']]);
            fputcsv($out, ['Approvals given', $d['approvals']]);
            fputcsv($out, ['Contracts expiring within 60 days', $d['contracts']->count()]);
            fputcsv($out, []);

            fputcsv($out, ['Revenue by branch']);
            fputcsv($out, ['Branch', 'Country', 'Revenue (KES)']);
            foreach ($d['branches'] as $b) {
                fputcsv($out, [$b['name'], $b['country'], number_format($b['minor'] / 100, 2, '.', '')]);
            }
            fputcsv($out, []);

            fputcsv($out, ['Revenue trend, monthly']);
            fputcsv($out, ['Month', 'Revenue (KES)']);
            foreach ($d['trend']['labels'] as $i => $label) {
                fputcsv($out, [$label, number_format($d['trend']['values'][$i], 2, '.', '')]);
            }
            fputcsv($out, []);

            fputcsv($out, ['Jobs right now']);
            fputcsv($out, ['Group', 'Jobs']);
            foreach ($d['mix'] as $m) {
                fputcsv($out, [$m['label'], $m['value']]);
            }
            fputcsv($out, []);

            fputcsv($out, ['Revenue by technician']);
            fputcsv($out, ['Technician', 'Branch', 'Jobs closed', 'Open', 'Utilization %', 'Revenue (KES)']);
            foreach ($d['techs'] as $t) {
                fputcsv($out, [$t['user']->name, $t['location'], $t['closed'], $t['open'], $t['utilization'] ?? '', number_format($t['revenueMinor'] / 100, 2, '.', '')]);
            }
            fputcsv($out, []);

            fputcsv($out, ['Contracts expiring within 60 days']);
            fputcsv($out, ['Contract', 'Customer', 'Ends', 'Visits left']);
            foreach ($d['contracts'] as $c) {
                fputcsv($out, [$c->reference, $c->customer->name, $c->ends_at->format('Y-m-d'), $c->visitsRemaining()]);
            }
            fputcsv($out, []);

            fputcsv($out, ['Job history']);
            fputcsv($out, self::JOB_HEADERS);
            foreach ($this->csvRows($d['history']) as $row) {
                fputcsv($out, $row);
            }

            fclose($out);
        }, $name, ['Content-Type' => 'text/csv']);
    }

    public function with(): array
    {
        $monthStart = now()->startOfMonth();
        $monthEnd = now()->endOfMonth();

        $branches = ManagerReports::revenueByBranch($monthStart, $monthEnd);
        $trend = ManagerReports::revenueTrend(6);
        $contracts = ManagerReports::contractsExpiring(60);
        $history = ManagerReports::jobHistory($this->filters());

        return [
            'revenueMinor' => ManagerReports::revenueMinor($monthStart, $monthEnd),
            'jobsClosed' => \App\Models\WorkOrder::where('status', 'Closed')->where('updated_at', '>=', $monthStart)->count(),
            'approvals' => ManagerReports::approvalsGiven($monthStart, $monthEnd),
            'contractCount' => $contracts->count(),
            'branches' => $branches,
            'topBranch' => max($branches->max('minor') ?? 0, 1),
            'trend' => $trend,
            'mix' => ManagerReports::jobMix(),
            'techs' => ManagerReports::technicianRows($monthStart, $monthEnd)->take(7),
            'contracts' => $contracts,
            'history' => $history->take($this->rowsShown),
            'historyTotal' => ManagerReports::jobHistory([])->count(),
            'historyMatching' => $history->count(),
            'customers' => Customer::orderBy('name')->get(['id', 'name']),
            'countries' => Country::orderBy('name')->pluck('name'),
        ];
    }
};
?>

<div class="mx-auto" style="max-width: 72rem">
    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-neutral-900">Reports</h1>
            <p class="text-sm text-neutral-500">{{ now()->format('F Y') }}, all branches</p>
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
                        <p class="mt-1 text-sm text-neutral-500">Pick the dates the figures should cover.</p>
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
                    Revenue, jobs closed, approvals, branches, technicians and job history follow these dates. The trend shows whole months (at least six). Jobs right now and expiring contracts are always as of today.
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

    {{-- KPIs --}}
    <div class="mb-5 grid gap-3" style="grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));">
        <div class="card">
            <p class="text-xs font-medium text-neutral-500">Revenue this month</p>
            <p class="mt-2 font-mono text-2xl font-bold text-neutral-900">{{ \App\Services\ManagerReports::kes($revenueMinor, true) }}</p>
        </div>
        <div class="card">
            <p class="text-xs font-medium text-neutral-500">Jobs closed</p>
            <p class="mt-2 font-mono text-2xl font-bold text-neutral-900">{{ $jobsClosed }}</p>
            <p class="mt-0.5 text-xs text-neutral-400">this month</p>
        </div>
        <div class="card">
            <p class="text-xs font-medium text-neutral-500">Approvals given, this month</p>
            <p class="mt-2 font-mono text-2xl font-bold text-neutral-900">{{ $approvals }}</p>
        </div>
        <div class="card">
            <p class="text-xs font-medium text-neutral-500">Contracts expiring soon</p>
            <p @class(['mt-2 font-mono text-2xl font-bold', 'text-amber-700' => $contractCount > 0, 'text-neutral-900' => $contractCount === 0])>{{ $contractCount }}</p>
        </div>
    </div>

    {{-- Charts --}}
    <div class="mb-5 grid gap-4" style="grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));">
        <div class="card">
            <h2 class="mb-4 text-sm font-semibold text-neutral-900">Revenue by branch, this month</h2>
            <div class="space-y-3.5">
                @foreach ($branches as $b)
                    <div wire:key="br-{{ $loop->index }}">
                        <div class="mb-1 flex items-baseline justify-between gap-3 text-sm">
                            <span class="text-neutral-700">{{ $b['name'] }}</span>
                            <span class="font-mono text-xs font-bold text-neutral-900">{{ \App\Services\ManagerReports::kes($b['minor']) }}</span>
                        </div>
                        <div class="h-2 overflow-hidden rounded-full bg-neutral-100">
                            <div class="h-2 rounded-full" style="width: {{ $b['minor'] > 0 ? max(2, round($b['minor'] / $topBranch * 100)) : 0 }}%; background: var(--color-primary-600)"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="card">
            <h2 class="mb-4 text-sm font-semibold text-neutral-900">Revenue trend, last 6 months</h2>
            <x-line-chart :labels="$trend['labels']" :series="[['name' => 'Revenue (KES)', 'color' => 'var(--color-primary-600)', 'values' => $trend['values']]]" :height="230" :width="380" />
        </div>

        <div class="card">
            <h2 class="mb-4 text-sm font-semibold text-neutral-900">Jobs right now</h2>
            <x-pie-chart :data="$mix" :size="150" />
        </div>
    </div>

    {{-- Revenue by technician --}}
    <div class="card mb-5" style="padding: 0">
        <div class="flex items-center justify-between gap-3 px-5 pt-5">
            <h2 class="text-sm font-semibold text-neutral-900">Revenue by technician, this month</h2>
            <a href="/technicians" wire:navigate class="btn-outline" style="padding: 0.4rem 0.8rem">Full table</a>
        </div>
        <div class="mt-3 overflow-x-auto">
            <table class="table-clean" style="min-width: 640px">
                <thead>
                    <tr>
                        <th>Technician</th>
                        <th>Branch</th>
                        <th>Jobs closed</th>
                        <th>Utilization<span class="normal-case text-neutral-400"> (30d)</span></th>
                        <th class="text-right">Revenue</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($techs as $t)
                        <tr wire:key="tech-{{ $t['user']->id }}">
                            <td class="font-semibold text-neutral-900">{{ $t['user']->name }}</td>
                            <td class="text-neutral-600">{{ $t['location'] ?: '-' }}</td>
                            <td>{{ $t['closed'] }} <span class="text-xs text-neutral-400">&middot; {{ $t['open'] }} open</span></td>
                            <td class="font-bold">{{ $t['utilization'] !== null ? $t['utilization'].'%' : '-' }}</td>
                            <td class="text-right font-mono text-xs font-semibold">{{ \App\Services\ManagerReports::kes($t['revenueMinor']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-neutral-500">No technician accounts yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Contracts expiring --}}
    <div class="card mb-5" style="padding: 0">
        <h2 class="px-5 pt-5 text-sm font-semibold text-neutral-900">Contracts expiring within 60 days</h2>
        <div class="mt-3 overflow-x-auto">
            <table class="table-clean" style="min-width: 560px">
                <thead>
                    <tr><th>Contract</th><th>Customer</th><th>Ends</th><th>Visits left</th><th>Status</th></tr>
                </thead>
                <tbody>
                    @forelse ($contracts as $c)
                        <tr wire:key="ct-{{ $c->id }}">
                            <td><a href="/contracts/{{ $c->id }}" wire:navigate class="font-mono text-xs font-bold text-neutral-900 hover:text-primary-600">{{ $c->reference }}</a></td>
                            <td>{{ $c->customer->name }}</td>
                            <td class="whitespace-nowrap">{{ $c->ends_at->format('d M Y') }}</td>
                            <td>{{ $c->visitsRemaining() }}</td>
                            <td>
                                @if ($c->ends_at->lt(today()))
                                    <span class="pill-danger">Expired</span>
                                @else
                                    <span class="pill-amber">Expiring soon</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-neutral-500">No contracts end in the next 60 days.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Job history --}}
    <div class="card" style="padding: 0">
        <div class="flex flex-wrap items-center justify-between gap-3 px-5 pt-5">
            <h2 class="text-sm font-semibold text-neutral-900">Job history</h2>
            <div class="flex gap-2">
                <button type="button" wire:click="exportPdf" wire:loading.attr="disabled" class="btn-outline" style="padding: 0.4rem 0.8rem">Export PDF</button>
                <button type="button" wire:click="exportCsv" wire:loading.attr="disabled" class="btn-outline" style="padding: 0.4rem 0.8rem">Export CSV</button>
            </div>
        </div>

        <form wire:submit="applyFilter" class="grid items-end gap-3 px-5 pt-4" style="grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));">
            <div>
                <label class="label" for="hf-from">From</label>
                <input id="hf-from" type="date" wire:model="fromInput" class="input" style="font-size: 16px">
            </div>
            <div>
                <label class="label" for="hf-to">To</label>
                <input id="hf-to" type="date" wire:model="toInput" class="input" style="font-size: 16px">
                @error('toInput') <p class="mt-1 text-xs text-critical-700">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="label" for="hf-customer">Customer</label>
                <select id="hf-customer" wire:model="customerInput" class="input" style="font-size: 16px">
                    <option value="">All</option>
                    @foreach ($customers as $c)
                        <option value="{{ $c->id }}">{{ $c->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="label" for="hf-country">Country</label>
                <select id="hf-country" wire:model="countryInput" class="input" style="font-size: 16px">
                    <option value="">All</option>
                    @foreach ($countries as $name)
                        <option value="{{ $name }}">{{ $name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex gap-2">
                <button type="submit" class="btn-primary">Apply filter</button>
                <button type="button" wire:click="resetFilter" class="btn-outline">Reset</button>
            </div>
        </form>

        <p class="px-5 pt-3 text-sm text-neutral-500">{{ $historyMatching }} of {{ $historyTotal }} jobs match the filter</p>

        <div class="mt-3 overflow-x-auto">
            <table class="table-clean" style="min-width: 920px">
                <thead>
                    <tr>
                        <th>Job</th><th>Customer</th><th>Branch</th><th>Country</th><th>Technician</th>
                        <th>Nature of visit</th><th>Date</th><th class="text-right">Value</th><th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($history as $r)
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
                            <td class="whitespace-nowrap text-right font-mono text-xs">{{ $r['valueMinor'] > 0 ? \App\Services\ManagerReports::kes($r['valueMinor']) : '-' }}</td>
                            <td><span class="{{ $pill }}">{{ $r['status'] }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="text-center text-neutral-500">No jobs match this filter.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($historyMatching > $rowsShown)
            <div class="border-t border-neutral-100 px-5 py-3 text-center">
                <button type="button" wire:click="showMore" class="text-sm font-semibold text-primary-600 hover:text-primary-700">
                    Show more ({{ $historyMatching - $rowsShown }} left)
                </button>
            </div>
        @else
            <div style="height: 0.5rem"></div>
        @endif
    </div>
</div>
