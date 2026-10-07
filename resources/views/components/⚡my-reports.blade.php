<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\WorkOrder;
use Barryvdh\DomPDF\Facade\Pdf;

new #[Layout('layouts.app', ['title' => 'My reports'])] class extends Component
{
    use \App\Support\ShowsMore;

    public string $fromInput = '';
    public string $toInput = '';
    public string $from = '';
    public string $to = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->hasRole('Technician'), 403);
    }

    public function applyFilter(): void
    {
        $this->validate([
            'fromInput' => ['nullable', 'date'],
            'toInput' => ['nullable', 'date', 'after_or_equal:fromInput'],
        ]);

        $this->from = $this->fromInput;
        $this->to = $this->toInput;
    }

    public function resetFilter(): void
    {
        $this->fromInput = $this->toInput = $this->from = $this->to = '';
        $this->resetValidation();
    }

    protected function report(): array
    {
        $uid = auth()->id();

        $query = WorkOrder::with([
            'customer',
            'invoices' => fn ($q) => $q->where('status', '!=', 'Draft'),
            'documents' => fn ($q) => $q->where('type', Document::TYPE_REPORT),
        ])->where('assigned_technician_id', $uid);

        if ($this->from !== '') {
            $query->whereDate('due_date', '>=', $this->from);
        }

        if ($this->to !== '') {
            $query->whereDate('due_date', '<=', $this->to);
        }

        $jobs = $query->orderByDesc('due_date')->get();

        $rows = $jobs->map(function (WorkOrder $job) {
            $billed = (int) $job->invoices->sum('amount_minor');
            $paid = (int) $job->invoices->sum('paid_minor');
            $balance = $billed - $paid;

            if ($job->invoices->isEmpty()) {
                [$label, $tone] = ['Not yet invoiced', 'neutral'];
            } elseif ($balance <= 0) {
                [$label, $tone] = ['Paid', 'success'];
            } elseif ($job->invoices->contains(fn ($i) => $i->balanceMinor() > 0 && $i->due_at->lt(today()))) {
                [$label, $tone] = ['Overdue', 'danger'];
            } elseif ($paid > 0) {
                [$label, $tone] = ['Part paid', 'amber'];
            } else {
                [$label, $tone] = ['Unpaid', 'info'];
            }

            return [
                'job' => $job,
                'billed' => $billed,
                'paid' => $paid,
                'balance' => $balance,
                'payLabel' => $label,
                'payTone' => $tone,
                'report' => $job->documents->sortByDesc('id')->first(),
            ];
        });

        $customers = $rows->groupBy(fn ($r) => $r['job']->customer_id)->map(function ($group) {
            $billed = $group->sum('billed');
            $paid = $group->sum('paid');

            return [
                'name' => $group->first()['job']->customer->name,
                'jobs' => $group->count(),
                'billed' => $billed,
                'paid' => $paid,
                'balance' => $billed - $paid,
                'pct' => $billed > 0 ? (int) round($paid / $billed * 100) : 0,
            ];
        })->sortByDesc('billed')->values();

        // Twelve-month trend, independent of the date filter.
        $start = now()->startOfMonth()->subMonths(11);
        $mine = fn ($q) => $q->where('assigned_technician_id', $uid);

        $billedByMonth = Invoice::whereHas('workOrder', $mine)
            ->where('status', '!=', 'Draft')
            ->whereDate('issued_at', '>=', $start->toDateString())
            ->get()
            ->groupBy(fn ($i) => $i->issued_at->format('Y-m'))
            ->map(fn ($g) => $g->sum('amount_minor'));

        $collectedByMonth = Payment::whereHas('invoice.workOrder', $mine)
            ->whereDate('paid_at', '>=', $start->toDateString())
            ->get()
            ->groupBy(fn ($p) => $p->paid_at->format('Y-m'))
            ->map(fn ($g) => $g->sum('amount_minor'));

        $labels = [];
        $billedSeries = [];
        $collectedSeries = [];

        for ($i = 0; $i < 12; $i++) {
            $m = $start->copy()->addMonths($i);
            $labels[] = $m->format('M y');
            $billedSeries[] = (int) round(($billedByMonth[$m->format('Y-m')] ?? 0) / 100);
            $collectedSeries[] = (int) round(($collectedByMonth[$m->format('Y-m')] ?? 0) / 100);
        }

        return [
            'rows' => $rows,
            'customers' => $customers,
            'kpi' => [
                'closedThisMonth' => WorkOrder::where('assigned_technician_id', $uid)
                    ->where('status', 'Closed')
                    ->whereYear('updated_at', now()->year)
                    ->whereMonth('updated_at', now()->month)
                    ->count(),
                'matching' => $rows->count(),
                'billed' => $rows->sum('billed'),
                'paid' => $rows->sum('paid'),
                'balance' => $rows->sum('balance'),
            ],
            'chartLabels' => $labels,
            'chartSeries' => [
                ['name' => 'Billed', 'color' => 'var(--color-primary-500)', 'values' => $billedSeries],
                ['name' => 'Collected', 'color' => 'var(--color-success-500)', 'values' => $collectedSeries],
            ],
        ];
    }

    public function exportCsv()
    {
        $data = $this->report();

        return response()->streamDownload(function () use ($data) {
            $out = fopen('php://output', 'w');
            \App\Support\Csv::put($out, ['Job', 'Customer', 'Nature of visit', 'Due date', 'Job status', 'Billed ('.currency().')', 'Paid ('.currency().')', 'Balance ('.currency().')', 'Payment', 'Report']);

            foreach ($data['rows'] as $r) {
                \App\Support\Csv::put($out, [
                    $r['job']->reference,
                    $r['job']->customer->name,
                    $r['job']->nature_of_visit,
                    $r['job']->due_date->format('Y-m-d'),
                    $r['job']->status,
                    number_format($r['billed'] / 100, 2, '.', ''),
                    number_format($r['paid'] / 100, 2, '.', ''),
                    number_format($r['balance'] / 100, 2, '.', ''),
                    $r['payLabel'],
                    $r['report'] ? $r['report']->reference.' ('.$r['report']->status.')' : 'Not filed',
                ]);
            }

            fclose($out);
        }, 'my-job-history-'.now()->format('Ymd').'.csv', ['Content-Type' => 'text/csv']);
    }

    public function exportPdf()
    {
        $data = $this->report();

        $range = match (true) {
            $this->from !== '' && $this->to !== '' => $this->from.' to '.$this->to,
            $this->from !== '' => 'from '.$this->from,
            $this->to !== '' => 'up to '.$this->to,
            default => 'all dates',
        };

        $pdf = Pdf::loadView('pdfs.my-reports', $data + [
            'technician' => auth()->user()->name,
            'range' => $range,
        ]);

        return response()->streamDownload(
            fn () => print($pdf->output()),
            'my-reports-'.now()->format('Ymd').'.pdf'
        );
    }

    public function with(): array
    {
        return $this->report();
    }
};
?>

<div>
    @php
        $pillClass = ['neutral' => 'pill-neutral', 'success' => 'pill-success', 'amber' => 'pill-amber', 'danger' => 'pill-danger', 'info' => 'pill-info'];
        $money = fn ($minor) => number_format($minor / 100, 2);
    @endphp

    <div class="mb-5 flex flex-wrap items-center gap-4">
        <h1 class="shrink-0 text-xl font-semibold text-neutral-900">My reports</h1>
        <div class="h-px min-w-[40px] flex-1 border-t border-neutral-200"></div>
        <div class="flex shrink-0 gap-2">
            <button type="button" wire:click="exportPdf" wire:loading.attr="disabled" wire:target="exportPdf" class="btn-outline">Export PDF</button>
            <button type="button" wire:click="exportCsv" wire:loading.attr="disabled" wire:target="exportCsv" class="btn-primary">Export CSV</button>
        </div>
    </div>

    {{-- Filter --}}
    <div class="card mb-5">
        <div class="flex flex-wrap items-end gap-4">
            <div>
                <label class="label">From</label>
                <input type="date" wire:model="fromInput" class="input">
            </div>
            <div>
                <label class="label">To</label>
                <input type="date" wire:model="toInput" class="input">
            </div>
            <div class="flex gap-2">
                <button type="button" wire:click="applyFilter" class="btn-primary">Apply filter</button>
                <button type="button" wire:click="resetFilter" class="btn-outline">Reset</button>
            </div>
        </div>
        @error('fromInput') <p class="field-error">{{ $message }}</p> @enderror
        @error('toInput') <p class="field-error">{{ $message }}</p> @enderror
    </div>

    {{-- KPIs --}}
    <div class="mb-5 grid gap-3" style="grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));">
        <div class="card">
            <p class="text-xs text-neutral-500">Jobs closed this month</p>
            <p class="mt-1 font-mono text-2xl font-bold text-neutral-900">{{ $kpi['closedThisMonth'] }}</p>
        </div>
        <div class="card">
            <p class="text-xs text-neutral-500">Matching this filter</p>
            <p class="mt-1 font-mono text-2xl font-bold text-neutral-900">{{ $kpi['matching'] }}</p>
        </div>
        <div class="card">
            <p class="text-xs text-neutral-500">Revenue billed</p>
            <p class="mt-1 font-mono text-xl font-bold text-neutral-900">{{ $money($kpi['billed']) }}</p>
            <p class="text-xs text-neutral-400">{{ currency() }}</p>
        </div>
        <div class="card">
            <p class="text-xs text-neutral-500">Collected</p>
            <p class="mt-1 font-mono text-xl font-bold text-success-700">{{ $money($kpi['paid']) }}</p>
            <p class="text-xs text-neutral-400">{{ currency() }}</p>
        </div>
        <div class="card">
            <p class="text-xs text-neutral-500">Outstanding balance</p>
            <p @class(['mt-1 font-mono text-xl font-bold', 'text-critical-700' => $kpi['balance'] > 0, 'text-neutral-900' => $kpi['balance'] <= 0])>{{ $money($kpi['balance']) }}</p>
            <p class="text-xs text-neutral-400">{{ currency() }}</p>
        </div>
    </div>

    {{-- Revenue trend --}}
    <div class="card mb-5">
        <div class="mb-1 flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="text-sm font-semibold text-neutral-900">Revenue per month</h2>
            <p class="text-xs text-neutral-400">Last 12 months, all your jobs</p>
        </div>
        <x-line-chart :labels="$chartLabels" :series="$chartSeries" />
    </div>

    {{-- Revenue per customer --}}
    <div class="card mb-5">
        <h2 class="mb-3 text-sm font-semibold text-neutral-900">Revenue per customer served</h2>
        @if ($customers->isEmpty())
            <p class="text-sm text-neutral-500">No customers match this filter.</p>
        @else
            <div class="overflow-x-auto">
                <table class="table-clean w-full" style="min-width: 620px">
                    <thead>
                        <tr>
                            <th>Customer</th>
                            <th>Jobs</th>
                            <th class="text-right">Billed</th>
                            <th class="text-right">Paid</th>
                            <th class="text-right">Balance</th>
                            <th style="width: 150px">Collected</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($customers as $c)
                            <tr wire:key="cust-{{ $loop->index }}">
                                <td class="font-medium text-neutral-900">{{ $c['name'] }}</td>
                                <td>{{ $c['jobs'] }}</td>
                                <td class="text-right font-mono">{{ $money($c['billed']) }}</td>
                                <td class="text-right font-mono text-success-700">{{ $money($c['paid']) }}</td>
                                <td @class(['text-right font-mono', 'text-critical-700 font-semibold' => $c['balance'] > 0])>{{ $money($c['balance']) }}</td>
                                <td>
                                    @if ($c['billed'] > 0)
                                        <div class="flex items-center gap-2">
                                            <div style="height: 6px; flex: 1; border-radius: 999px; background: var(--color-neutral-100); overflow: hidden;">
                                                <div style="height: 100%; width: {{ $c['pct'] }}%; background: var(--color-success-500);"></div>
                                            </div>
                                            <span class="text-xs text-neutral-500">{{ $c['pct'] }}%</span>
                                        </div>
                                    @else
                                        <span class="text-xs text-neutral-400">Not invoiced</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="mt-2 text-xs text-neutral-400">Amounts in {{ currency() }}, VAT included. A balance means the customer has not paid in full yet.</p>
        @endif
    </div>

    {{-- Job history --}}
    <div class="card">
        <h2 class="mb-3 text-sm font-semibold text-neutral-900">Job history</h2>
        <div class="overflow-x-auto">
            <table class="table-clean w-full" style="min-width: 820px">
                <thead>
                    <tr>
                        <th>Job</th>
                        <th>Customer</th>
                        <th>Nature of visit</th>
                        <th>Date</th>
                        <th>Status</th>
                        <th>Report</th>
                        <th>Customer payment</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows->take($limit) as $r)
                        @php $job = $r['job']; @endphp
                        <tr wire:key="job-{{ $job->id }}">
                            <td><a href="/jobs/{{ $job->id }}" wire:navigate class="font-mono font-semibold text-neutral-900 hover:text-primary-600">{{ $job->reference }}</a></td>
                            <td>{{ $job->customer->name }}</td>
                            <td>{{ $job->nature_of_visit }}</td>
                            <td class="whitespace-nowrap">{{ $job->due_date->format('d M') }}</td>
                            <td>
                                <span @class([
                                    'pill-neutral' => $job->status === 'Assigned',
                                    'pill-amber' => $job->status === 'On site',
                                    'pill-info' => $job->status === 'Awaiting review',
                                    'pill-success' => in_array($job->status, ['Approved', 'Closed']),
                                ])>{{ $job->status }}</span>
                            </td>
                            <td class="whitespace-nowrap">
                                @if ($r['report'])
                                    @if ($r['report']->status === 'Draft')
                                        <a href="/jobs/{{ $job->id }}/report" wire:navigate class="text-xs font-semibold text-primary-600 hover:text-primary-700">Continue draft</a>
                                    @else
                                        <a href="/documents/{{ $r['report']->id }}" wire:navigate class="text-xs font-semibold text-primary-600 hover:text-primary-700">{{ $r['report']->reference }}</a>
                                        <span class="ml-1 text-xs text-neutral-400">{{ $r['report']->status }}</span>
                                    @endif
                                @elseif ($job->status === 'On site')
                                    <a href="/jobs/{{ $job->id }}/report" wire:navigate class="text-xs font-semibold text-primary-600 hover:text-primary-700">File report</a>
                                @else
                                    <span class="text-xs text-neutral-400">Not filed</span>
                                @endif
                            </td>
                            <td>
                                <span class="{{ $pillClass[$r['payTone']] }}">{{ $r['payLabel'] }}</span>
                                @if ($r['billed'] > 0)
                                    <p class="mt-1 text-xs text-neutral-500">
                                        {{ currency() }} {{ $money($r['billed']) }}
                                        @if ($r['balance'] > 0)
                                            &middot; <span class="font-semibold text-critical-700">balance {{ $money($r['balance']) }}</span>
                                        @endif
                                    </p>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="py-6 text-center text-sm text-neutral-500">No jobs match this filter.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <x-show-more :shown="min($limit, count($rows))" :total="count($rows)" />
</div>
