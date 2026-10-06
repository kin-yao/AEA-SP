<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Services\CustomerReports;
use App\Services\ManagerExport;
use App\Services\ServiceAdminReports;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;

new #[Layout('layouts.app', ['title' => 'My Reports'])] class extends Component
{
    // What is typed into the date boxes, and what has actually been applied.
    public string $fromInput = '';
    public string $toInput = '';
    public string $from = '';
    public string $to = '';

    public bool $showExport = false;
    public string $exportFrom = '';
    public string $exportTo = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->hasRole('Customer') && auth()->user()->customer_id, 403);
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
    }

    public function resetFilter(): void
    {
        $this->reset(['fromInput', 'toInput', 'from', 'to']);
        $this->resetValidation();
    }

    protected function filters(): array
    {
        return ['from' => $this->from, 'to' => $this->to];
    }

    protected function customerId(): int
    {
        return (int) auth()->user()->customer_id;
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

        return CustomerReports::build($this->customerId(), ['from' => $this->exportFrom, 'to' => $this->exportTo]);
    }

    public function exportAllPdf()
    {
        $d = $this->exportData();

        $col = fn (array $rows, int $w = 330, int $h = 190) => ManagerExport::dataUri(ServiceAdminReports::columnSvg($rows, $w, $h));
        $pie = fn (array $mix) => ManagerExport::dataUri(ManagerExport::pieSvg($mix, 110));

        $pdf = Pdf::loadView('pdfs.customer-report', $d + [
            'company' => auth()->user()->customer?->name,
            'person' => auth()->user()->name,
            'img' => [
                'requestMix' => $pie($d['requestMix']),
                'requestMonths' => $col($d['requestMonths']),
                'nature' => $col($d['natureRows']),
                'invoiceMix' => $pie($d['invoiceMix']),
                'invoiced' => $col($d['invoicedMonths']),
                'ageing' => $col($d['ageing']),
                'machineMix' => $pie($d['machineMix']),
            ],
        ])->setPaper('a4', 'portrait');

        $name = 'aea-my-reports-'.Carbon::parse($this->exportFrom)->format('Y-m-d').'-to-'.Carbon::parse($this->exportTo)->format('Y-m-d').'.pdf';
        $this->showExport = false;

        return response()->streamDownload(fn () => print($pdf->output()), $name, ['Content-Type' => 'application/pdf']);
    }

    public function exportAllCsv()
    {
        $d = $this->exportData();
        $name = 'aea-my-reports-'.Carbon::parse($this->exportFrom)->format('Y-m-d').'-to-'.Carbon::parse($this->exportTo)->format('Y-m-d').'.csv';
        $this->showExport = false;

        return response()->streamDownload(function () use ($d) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['AEA Limited, My reports', $d['label']]);
            fputcsv($out, []);
            foreach (CustomerReports::csvSections($d) as [$title, $headers, $rows]) {
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

    public function with(): array
    {
        return CustomerReports::build($this->customerId(), $this->filters());
    }
};
?>

@php
    $kes = fn ($m, $compact = false) => \App\Services\ManagerReports::kes((int) $m, $compact);
    $statusPill = ['Unpaid' => 'pill-info', 'Part paid' => 'pill-amber', 'Paid' => 'pill-success', 'Overdue' => 'pill-danger'];
    $machinePill = ['Active' => 'pill-success', 'Due soon' => 'pill-amber', 'Overdue' => 'pill-danger'];
    $stagePill = ['Active' => 'pill-success', 'Expiring soon' => 'pill-amber', 'Expired' => 'pill-danger'];
@endphp

<div class="mx-auto" style="max-width: 72rem">
    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-neutral-900">My Reports</h1>
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
                        <p class="mt-1 text-sm text-neutral-500">Everything on this page, charts included. Pick the dates the figures should cover.</p>
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

                <p class="mt-3 text-xs text-neutral-400">Machines, unpaid invoices and contracts are always shown as they stand today.</p>

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

    {{-- Dates --}}
    <form wire:submit="applyFilter" class="card mb-4 grid items-end gap-3" style="grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));">
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

    {{-- Cards --}}
    <div class="grid gap-3" style="grid-template-columns: repeat(auto-fit, minmax(135px, 1fr));">
        <x-dash.kpi label="Requests raised" tone="info" :value="$kpis['requests']" />
        <x-dash.kpi label="Jobs completed" tone="good" :value="$kpis['jobsDone']" />
        <x-dash.kpi label="Reports received" tone="info" :value="$kpis['reports']" />
        <x-dash.kpi label="Invoiced" tone="info" :value="$kes($kpis['invoicedMinor'], true)" />
        <x-dash.kpi label="Outstanding" tone="warn" :value="$kes($kpis['outstandingMinor'], true)" />
    </div>
    <p class="mb-6 mt-2 text-xs text-neutral-400">Machines, unpaid invoices and contracts below are always as they stand today. The rest follows the dates above.</p>

    {{-- Service activity --}}
    <div class="mb-3 flex items-center gap-3">
        <h2 class="text-base font-semibold text-neutral-900">Service activity</h2>
        <div class="flex-1 border-t border-neutral-200"></div>
    </div>
    <div class="mb-4 flex flex-wrap gap-4">
        <div class="card" style="flex: 1 1 300px; min-width: 0">
            <h3 class="mb-4 text-sm font-semibold text-neutral-900">Requests by status</h3>
            <x-pie-chart :data="$requestMix" :size="150" />
        </div>
        <div class="card" style="flex: 2 1 420px; min-width: 0">
            <h3 class="mb-1 text-sm font-semibold text-neutral-900">Requests per month</h3>
            <p class="mb-4 text-xs text-neutral-400">How many service requests you raised</p>
            <x-column-chart :data="$requestMonths" :height="190" />
        </div>
    </div>
    <div class="card mb-6">
        <h3 class="mb-1 text-sm font-semibold text-neutral-900">Jobs by type of visit</h3>
        <p class="mb-4 text-xs text-neutral-400">Visits to your machines in the chosen dates</p>
        <x-column-chart :data="$natureRows" :height="190" />
    </div>

    {{-- Invoices --}}
    <div class="mb-3 flex items-center gap-3">
        <h2 class="text-base font-semibold text-neutral-900">Invoices and payments</h2>
        <div class="flex-1 border-t border-neutral-200"></div>
    </div>
    <div class="mb-4 flex flex-wrap gap-4">
        <div class="card" style="flex: 1 1 300px; min-width: 0">
            <h3 class="mb-4 text-sm font-semibold text-neutral-900">Invoices by status</h3>
            <x-pie-chart :data="$invoiceMix" :size="150" />
        </div>
        <div class="card" style="flex: 2 1 420px; min-width: 0">
            <h3 class="mb-1 text-sm font-semibold text-neutral-900">Invoiced per month</h3>
            <p class="mb-4 text-xs text-neutral-400">KES, by the date the invoice was issued</p>
            <x-column-chart :data="$invoicedMonths" :height="190" />
        </div>
    </div>
    <div class="card mb-4">
        <h3 class="mb-1 text-sm font-semibold text-neutral-900">Money still to pay, by age</h3>
        <p class="mb-4 text-xs text-neutral-400">Balance in KES, grouped by days past the due date</p>
        <x-column-chart :data="$ageing" :height="190" />
    </div>
    <div class="card mb-6" style="padding: 0">
        <h3 class="px-5 pt-5 text-sm font-semibold text-neutral-900">Unpaid invoices</h3>
        <div class="mt-3 overflow-x-auto">
            <table class="table-clean" style="min-width: 600px">
                <thead><tr><th>Invoice</th><th>Due</th><th>Amount</th><th>Paid</th><th>Status</th></tr></thead>
                <tbody>
                    @forelse ($owingRows as $i)
                        @php $shown = \App\Services\FinanceReports::shownStatus($i); @endphp
                        <tr wire:key="ow-{{ $i->id }}">
                            <td class="whitespace-nowrap font-mono font-bold"><a href="/invoices/{{ $i->id }}" wire:navigate class="text-neutral-900 hover:underline">{{ $i->reference }}</a></td>
                            <td class="whitespace-nowrap">{{ $i->due_at->format('d M Y') }}</td>
                            <td class="whitespace-nowrap">{{ $kes($i->amount_minor) }}</td>
                            <td class="whitespace-nowrap">{{ $kes($i->paid_minor) }}</td>
                            <td><span class="{{ $statusPill[$shown] ?? 'pill-neutral' }}">{{ $shown }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-neutral-500">You have nothing unpaid.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Machines --}}
    <div class="mb-3 flex items-center gap-3">
        <h2 class="text-base font-semibold text-neutral-900">Machines</h2>
        <div class="flex-1 border-t border-neutral-200"></div>
    </div>
    <div class="mb-6 flex flex-wrap gap-4">
        <div class="card" style="flex: 1 1 300px; min-width: 0">
            <h3 class="mb-4 text-sm font-semibold text-neutral-900">Machines by service status</h3>
            <x-pie-chart :data="$machineMix" :size="150" />
        </div>
        <div class="card" style="flex: 2 1 420px; min-width: 0; padding: 0">
            <div class="overflow-x-auto">
                <table class="table-clean" style="min-width: 420px">
                    <thead><tr><th>Machine</th><th>Next service</th><th>Status</th></tr></thead>
                    <tbody>
                        @forelse ($machines as $e)
                            @php $st = $e->visitStatus(); @endphp
                            <tr wire:key="mc-{{ $e->id }}">
                                <td><span class="font-semibold text-neutral-900">{{ $e->model }}</span><br><span class="font-mono text-xs text-neutral-500">{{ $e->serial_number }}</span></td>
                                <td class="whitespace-nowrap">{{ $e->next_visit_due_at?->format('d M Y') ?? 'Not set' }}</td>
                                <td><span class="{{ $machinePill[$st] }}">{{ $st }}</span></td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="text-center text-neutral-500">No machines are registered to your company yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Contracts --}}
    <div class="mb-3 flex items-center gap-3">
        <h2 class="text-base font-semibold text-neutral-900">Contracts</h2>
        <div class="flex-1 border-t border-neutral-200"></div>
    </div>
    <div class="card" style="padding: 0">
        <div class="overflow-x-auto">
            <table class="table-clean" style="min-width: 560px">
                <thead><tr><th>Contract</th><th>Type</th><th>Ends</th><th>Visits used</th><th>Visits left</th><th>Status</th></tr></thead>
                <tbody>
                    @forelse ($contractRows as $r)
                        <tr wire:key="ct-{{ $r['c']->id }}">
                            <td class="whitespace-nowrap font-mono font-bold text-neutral-900">{{ $r['c']->reference }}</td>
                            <td>{{ $r['c']->type }}</td>
                            <td class="whitespace-nowrap">{{ $r['c']->ends_at->format('d M Y') }}</td>
                            <td>{{ $r['used'] }}</td>
                            <td>{{ $r['left'] }}</td>
                            <td><span class="{{ $stagePill[$r['stage']] }}">{{ $r['stage'] }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-neutral-500">You have no contracts.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
