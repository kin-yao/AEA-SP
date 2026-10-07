<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Quotation;
use App\Models\User;
use App\Models\WorkOrder;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Numbers behind the Manager's Reports and Technician performance pages.
 *
 * Definitions, kept in one place so both pages always agree:
 *  - Revenue: invoices that have left Draft, by issue date.
 *  - A job's branch: the branch of its technician, falling back to the
 *    customer's branch when nobody is assigned.
 *  - Jobs closed: Closed jobs whose record was last touched in the period.
 *  - Utilization: share of working days (Mon to Fri) in the last 30 days on
 *    which the technician had at least one visit booked.
 */
class ManagerReports
{
    public static function revenueQuery(Carbon $from, Carbon $to)
    {
        return Invoice::query()
            ->where('status', '!=', 'Draft')
            ->whereBetween('issued_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()]);
    }

    public static function revenueMinor(Carbon $from, Carbon $to): int
    {
        return (int) self::revenueQuery($from, $to)->sum('amount_minor');
    }

    public static function jobBranch(WorkOrder $job): ?Branch
    {
        return $job->technician?->branch ?? $job->customer?->branch;
    }

    /** @return Collection<int, array{name:string,country:string,minor:int}> every branch, highest revenue first */
    public static function revenueByBranch(Carbon $from, Carbon $to): Collection
    {
        $totals = [];

        $invoices = self::revenueQuery($from, $to)
            ->with(['customer.branch.country', 'workOrder.technician.branch.country'])
            ->get();

        foreach ($invoices as $invoice) {
            $branch = $invoice->workOrder?->technician?->branch ?? $invoice->customer?->branch;
            if ($branch) {
                $totals[$branch->id] = ($totals[$branch->id] ?? 0) + $invoice->amount_minor;
            }
        }

        return Branch::with('country')->get()
            ->map(fn (Branch $b) => [
                'name' => $b->name,
                'country' => $b->country?->name ?? '',
                'minor' => (int) ($totals[$b->id] ?? 0),
            ])
            ->sortByDesc('minor')
            ->values();
    }

    /** @return array{labels:array<int,string>,values:array<int,float>} last N months, oldest first, values in KES */
    public static function revenueTrend(int $months = 6): array
    {
        $labels = [];
        $values = [];

        for ($i = $months - 1; $i >= 0; $i--) {
            $start = now()->startOfMonth()->subMonthsNoOverflow($i);
            $labels[] = $start->format('M');
            $values[] = round(self::revenueMinor($start, $start->copy()->endOfMonth()) / 100, 2);
        }

        return ['labels' => $labels, 'values' => $values];
    }

    /**
     * Where every live job stands right now. Overdue takes priority over the
     * job's own status so nothing is counted twice. Closed jobs are limited
     * to this month so history does not swamp the picture.
     *
     * @return array<int, array{label:string,value:int,color:string}>
     */
    public static function jobMix(): array
    {
        $jobs = WorkOrder::query()
            ->where(fn ($q) => $q->where('status', '!=', 'Closed')->orWhere('updated_at', '>=', now()->startOfMonth()))
            ->get(['id', 'status', 'due_date']);

        $mix = ['Assigned' => 0, 'On site / awaiting review' => 0, 'Approved' => 0, 'Closed this month' => 0, 'Overdue' => 0];

        foreach ($jobs as $job) {
            $overdue = $job->status !== 'Closed' && $job->due_date->lt(today());

            if ($overdue) {
                $mix['Overdue']++;
            } elseif ($job->status === 'Assigned') {
                $mix['Assigned']++;
            } elseif (in_array($job->status, ['On site', 'Awaiting review'])) {
                $mix['On site / awaiting review']++;
            } elseif ($job->status === 'Approved') {
                $mix['Approved']++;
            } else {
                $mix['Closed this month']++;
            }
        }

        $colors = [
            'Assigned' => '#1f2937',
            'On site / awaiting review' => '#e0ac2e',
            'Approved' => '#475569',
            'Closed this month' => '#15803d',
            'Overdue' => '#d62828',
        ];

        $out = [];
        foreach ($mix as $label => $value) {
            if ($value > 0) {
                $out[] = ['label' => $label, 'value' => $value, 'color' => $colors[$label]];
            }
        }

        return $out;
    }

    public static function workingDays(Carbon $from, Carbon $to): int
    {
        $to = $to->copy()->min(today());
        if ($to->lt($from)) {
            return 0;
        }

        $days = 0;
        for ($d = $from->copy()->startOfDay(); $d->lte($to); $d->addDay()) {
            if ($d->isWeekday()) {
                $days++;
            }
        }

        return $days;
    }

    /**
     * One row per technician for the period, highest revenue first.
     *
     * @return Collection<int, array<string,mixed>>
     */
    public static function technicianRows(Carbon $from, Carbon $to): Collection
    {
        $technicians = User::role('Technician')->with('branch.country')->orderBy('name')->get();

        $invoices = self::revenueQuery($from, $to)
            ->whereNotNull('work_order_id')
            ->with('workOrder:id,assigned_technician_id')
            ->get()
            ->groupBy(fn ($i) => $i->workOrder?->assigned_technician_id);

        $jobs = WorkOrder::whereIn('assigned_technician_id', $technicians->pluck('id'))
            ->get(['id', 'assigned_technician_id', 'status', 'due_date', 'updated_at'])
            ->groupBy('assigned_technician_id');

        // Utilization is always judged over the last 30 days, so it stays
        // meaningful early in a month and does not change with the period.
        $utilFrom = today()->subDays(29);
        $workingDays = self::workingDays($utilFrom, today());

        return $technicians->map(function (User $t) use ($invoices, $jobs, $from, $to, $workingDays, $utilFrom) {
            $mine = $jobs->get($t->id, collect());

            $closed = $mine->filter(fn ($j) => $j->status === 'Closed'
                && $j->updated_at->between($from->copy()->startOfDay(), $to->copy()->endOfDay()));

            $onTime = $closed->filter(fn ($j) => $j->updated_at->toDateString() <= $j->due_date->toDateString())->count();

            $bookedDays = $mine
                ->filter(fn ($j) => $j->due_date->between($utilFrom->copy()->startOfDay(), now()->endOfDay()) && $j->due_date->isWeekday())
                ->map(fn ($j) => $j->due_date->toDateString())
                ->unique()
                ->count();

            $location = collect([$t->branch?->name, $t->branch?->country?->name])->filter()->unique()->implode(', ');

            return [
                'user' => $t,
                'location' => $location,
                'open' => $mine->where('status', '!=', 'Closed')->count(),
                'overdue' => $mine->filter(fn ($j) => $j->status !== 'Closed' && $j->due_date->lt(today()))->count(),
                'closed' => $closed->count(),
                'onTimeRate' => $closed->count() > 0 ? (int) round($onTime / $closed->count() * 100) : null,
                'utilization' => $workingDays > 0 ? min(100, (int) round($bookedDays / $workingDays * 100)) : null,
                'revenueMinor' => (int) $invoices->get($t->id, collect())->sum('amount_minor'),
                'status' => $t->on_leave
                    ? 'On leave'
                    : ($mine->contains(fn ($j) => $j->status === 'On site') ? 'On site' : 'Available'),
            ];
        })->sortByDesc('revenueMinor')->values();
    }

    /** Active contracts that end within the next N days, or have already ended without being closed off. */
    public static function contractsExpiring(?int $days = null): Collection
    {
        $days ??= (int) setting('contract_warn_days');

        return Contract::withVisitCounts()->with('customer')
            ->where('status', 'Active')
            ->where('ends_at', '<=', today()->addDays($days))
            ->orderBy('ends_at')
            ->get();
    }

    public static function approvalsGiven(Carbon $from, Carbon $to): int
    {
        return Quotation::where('approval_threshold', 'Manager')
            ->whereIn('status', ['Approved', 'Accepted', 'Converted'])
            ->whereBetween('updated_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->count();
    }

    /**
     * Job history, newest due date first.
     *
     * @param  array{from?:?string,to?:?string,customer?:?string,country?:?string}  $f
     * @return Collection<int, array<string,mixed>>
     */
    public static function jobHistory(array $f = []): Collection
    {
        $query = WorkOrder::with([
            'customer.branch.country',
            'technician.branch.country',
            'invoices' => fn ($q) => $q->where('status', '!=', 'Draft'),
        ])->orderByDesc('due_date')->orderByDesc('id');

        if (! empty($f['from'])) {
            $query->whereDate('due_date', '>=', $f['from']);
        }
        if (! empty($f['to'])) {
            $query->whereDate('due_date', '<=', $f['to']);
        }
        if (! empty($f['customer'])) {
            $query->where('customer_id', $f['customer']);
        }

        $rows = $query->get()->map(function (WorkOrder $job) {
            $branch = self::jobBranch($job);
            $invoiced = (int) $job->invoices->sum('amount_minor');
            $overdue = $job->status !== 'Closed' && $job->due_date->lt(today());

            return [
                'job' => $job,
                'branch' => $branch?->name ?? '-',
                'country' => $branch?->country?->name ?? '-',
                'technician' => $job->technician?->name ?? 'Unassigned',
                'valueMinor' => $invoiced > 0 ? $invoiced : (int) $job->value_minor,
                'invoiced' => $invoiced > 0,
                'status' => $overdue ? 'Overdue' : $job->status,
            ];
        });

        if (! empty($f['country'])) {
            $rows = $rows->filter(fn ($r) => $r['country'] === $f['country']);
        }

        return $rows->values();
    }

    /** "KES 3.6M" for big numbers, "KES 245,000" otherwise. */
    public static function kes(int $minor, bool $compact = false): string
    {
        $kes = $minor / 100;

        if ($compact && abs($kes) >= 1_000_000) {
            return currency().' '.rtrim(rtrim(number_format($kes / 1_000_000, 1), '0'), '.').'M';
        }

        return currency().' '.number_format($kes, 0);
    }
}
