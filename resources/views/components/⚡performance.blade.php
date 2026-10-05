<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Services\ManagerReports;
use Carbon\Carbon;

new #[Layout('layouts.app', ['title' => 'Technician performance'])] class extends Component
{
    public string $search = '';
    public string $sort = 'revenue_desc';
    public string $period = 'this_month';

    public function mount(): void
    {
        abort_unless(auth()->user()->hasRole('Manager'), 403);
    }

    public function clear(): void
    {
        $this->reset(['search', 'sort', 'period']);
    }

    protected function range(): array
    {
        return match ($this->period) {
            'last_month' => [now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow()->endOfMonth(), 'Last month'],
            'last_90' => [now()->subDays(89)->startOfDay(), now()->endOfDay(), 'Last 90 days'],
            'this_year' => [now()->startOfYear(), now()->endOfDay(), 'This year'],
            'all' => [
                Carbon::parse(\App\Models\WorkOrder::min('due_date') ?? now()->startOfYear())->startOfDay(),
                now()->endOfDay(),
                'All time',
            ],
            default => [now()->startOfMonth(), now()->endOfMonth(), now()->format('F Y')],
        };
    }

    protected function rows()
    {
        [$from, $to] = $this->range();
        $rows = ManagerReports::technicianRows($from, $to);

        if ($this->search !== '') {
            $rows = $rows->filter(fn ($r) => str_contains(strtolower($r['user']->name), strtolower($this->search)));
        }

        return match ($this->sort) {
            'revenue_asc' => $rows->sortBy('revenueMinor'),
            'utilization_desc' => $rows->sortByDesc(fn ($r) => $r['utilization'] ?? -1),
            'name' => $rows->sortBy(fn ($r) => $r['user']->name),
            default => $rows->sortByDesc('revenueMinor'),
        };
    }

    public function exportCsv()
    {
        $rows = $this->rows()->values();
        [, , $label] = $this->range();

        return response()->streamDownload(function () use ($rows, $label) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Technician performance, '.$label]);
            fputcsv($out, ['Technician', 'Branch', 'Jobs closed', 'Open', 'Overdue', 'On-time %', 'Utilization %', 'Revenue (KES)']);
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r['user']->name, $r['location'], $r['closed'], $r['open'], $r['overdue'],
                    $r['onTimeRate'] ?? '', $r['utilization'] ?? '', number_format($r['revenueMinor'] / 100, 2, '.', ''),
                ]);
            }
            fclose($out);
        }, 'aea-technician-performance-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }

    public function with(): array
    {
        [, , $label] = $this->range();
        $rows = $this->rows()->values();

        $withUtil = $rows->filter(fn ($r) => $r['utilization'] !== null);

        return [
            'rows' => $rows,
            'label' => $label,
            'totalTechnicians' => \App\Models\User::role('Technician')->count(),
            'teamRevenue' => $rows->sum('revenueMinor'),
            'teamClosed' => $rows->sum('closed'),
            'teamOverdue' => $rows->sum('overdue'),
            'avgUtilization' => $withUtil->isNotEmpty() ? (int) round($withUtil->avg('utilization')) : null,
        ];
    }
};
?>

<div class="mx-auto" style="max-width: 72rem">
    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-neutral-900">Technician performance</h1>
            <p class="text-sm text-neutral-500">{{ $label }}</p>
        </div>
        <button type="button" wire:click="exportCsv" wire:loading.attr="disabled" class="btn-outline">Export</button>
    </div>

    <div class="mb-5 grid gap-3" style="grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));">
        <div class="card">
            <p class="text-xs font-medium text-neutral-500">Team revenue</p>
            <p class="mt-2 font-mono text-2xl font-bold text-neutral-900">{{ \App\Services\ManagerReports::kes($teamRevenue, true) }}</p>
        </div>
        <div class="card">
            <p class="text-xs font-medium text-neutral-500">Jobs closed</p>
            <p class="mt-2 font-mono text-2xl font-bold text-neutral-900">{{ $teamClosed }}</p>
        </div>
        <div class="card">
            <p class="text-xs font-medium text-neutral-500">Average utilization</p>
            <p class="mt-2 font-mono text-2xl font-bold text-neutral-900">{{ $avgUtilization !== null ? $avgUtilization.'%' : '-' }}</p>
        </div>
        <div class="card">
            <p class="text-xs font-medium text-neutral-500">Overdue open jobs</p>
            <p @class(['mt-2 font-mono text-2xl font-bold', 'text-critical-700' => $teamOverdue > 0, 'text-neutral-900' => $teamOverdue === 0])>{{ $teamOverdue }}</p>
        </div>
    </div>

    <div class="mb-5 flex flex-wrap items-center gap-3">
        <input wire:model.live.debounce.300ms="search" type="text" placeholder="Search technician name" class="input" style="flex: 1 1 220px; font-size: 16px; min-height: 46px">
        <select wire:model.live="period" class="input" style="width: auto; font-size: 16px; min-height: 46px">
            <option value="this_month">This month</option>
            <option value="last_month">Last month</option>
            <option value="last_90">Last 90 days</option>
            <option value="this_year">This year</option>
            <option value="all">All time</option>
        </select>
        <select wire:model.live="sort" class="input" style="width: auto; font-size: 16px; min-height: 46px">
            <option value="revenue_desc">Sort: revenue, high to low</option>
            <option value="revenue_asc">Sort: revenue, low to high</option>
            <option value="utilization_desc">Sort: utilization</option>
            <option value="name">Sort: name</option>
        </select>
        @if ($search !== '' || $sort !== 'revenue_desc' || $period !== 'this_month')
            <button type="button" wire:click="clear" class="btn-ghost">Clear</button>
        @endif
        <span class="text-sm text-neutral-400">{{ $rows->count() }} of {{ $totalTechnicians }} shown</span>
    </div>

    <div class="card mb-5" style="padding: 0">
        <h2 class="px-5 pt-5 text-sm font-semibold text-neutral-900">Revenue by technician</h2>
        <div class="mt-3 overflow-x-auto">
            <table class="table-clean" style="min-width: 720px">
                <thead>
                    <tr>
                        <th>Technician</th><th>Branch</th><th>Jobs closed</th><th>On time</th><th>Utilization (30d)</th><th class="text-right">Revenue</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $r)
                        <tr wire:key="row-{{ $r['user']->id }}">
                            <td>
                                <a href="/technicians/{{ $r['user']->id }}" wire:navigate class="font-semibold text-neutral-900 hover:text-primary-600">{{ $r['user']->name }}</a>
                            </td>
                            <td class="text-neutral-600">{{ $r['location'] ?: '-' }}</td>
                            <td>{{ $r['closed'] }} <span class="text-xs text-neutral-400">&middot; {{ $r['open'] }} open</span></td>
                            <td>{{ $r['onTimeRate'] !== null ? $r['onTimeRate'].'%' : '-' }}</td>
                            <td class="font-bold">{{ $r['utilization'] !== null ? $r['utilization'].'%' : '-' }}</td>
                            <td class="whitespace-nowrap text-right font-mono text-xs font-semibold">{{ \App\Services\ManagerReports::kes($r['revenueMinor']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-8 text-center text-neutral-500">No technician matches that filter.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <p class="px-5 pb-4 pt-2 text-xs text-neutral-400">
            Utilization is the share of working days (Monday to Friday) in the last 30 days on which the technician had at least one visit booked. It does not change with the period.
            Revenue is invoiced work on the technician's jobs, by invoice date.
        </p>
    </div>

    <div class="grid gap-4" style="grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));">
        @foreach ($rows as $r)
            @php
                $pct = $r['utilization'] ?? 0;
                $circ = 2 * M_PI * 15;
                $dash = round($pct / 100 * $circ, 1).' '.round($circ, 1);
            @endphp
            <a href="/technicians/{{ $r['user']->id }}" wire:navigate class="card flex items-start gap-4 hover:border-neutral-300" wire:key="card-{{ $r['user']->id }}">
                <svg viewBox="0 0 36 36" class="h-16 w-16 shrink-0 -rotate-90">
                    <circle cx="18" cy="18" r="15" fill="none" stroke-width="3" class="stroke-neutral-100" />
                    @if ($r['utilization'] !== null)
                        <circle cx="18" cy="18" r="15" fill="none" stroke-width="3" stroke-linecap="round"
                                class="{{ $pct >= 85 ? 'stroke-amber-500' : 'stroke-primary-600' }}" stroke-dasharray="{{ $dash }}" />
                    @endif
                    <text x="18" y="18" text-anchor="middle" dominant-baseline="central" transform="rotate(90 18 18)" class="fill-neutral-900" style="font-size: 7px; font-weight: 700">
                        {{ $r['utilization'] !== null ? $r['utilization'].'%' : '-' }}
                    </text>
                </svg>
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-semibold text-neutral-900">{{ $r['user']->name }}</p>
                    <p class="truncate text-xs text-neutral-500">
                        {{ $r['location'] ?: '-' }}@if ($r['user']->specialty) &middot; {{ $r['user']->specialty }}@endif
                    </p>
                    <p class="mt-2 font-mono text-sm font-bold text-neutral-900">{{ \App\Services\ManagerReports::kes($r['revenueMinor']) }}</p>
                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        <span class="text-xs text-neutral-600">{{ $r['open'] }} open</span>
                        <span @class([
                            'pill-amber' => $r['status'] === 'On site',
                            'pill-success' => $r['status'] === 'Available',
                            'pill-neutral' => $r['status'] === 'On leave',
                        ])>{{ $r['status'] }}</span>
                        @if ($r['overdue'] > 0)
                            <span class="pill-danger">{{ $r['overdue'] }} overdue</span>
                        @endif
                    </div>
                </div>
            </a>
        @endforeach
    </div>
</div>
