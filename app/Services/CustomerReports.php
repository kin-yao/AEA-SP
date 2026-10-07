<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\Document;
use App\Models\Equipment;
use App\Models\Invoice;
use App\Models\ServiceRequest;
use App\Models\WorkOrder;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Numbers behind a customer's My Reports page. Everything is limited to the
 * one customer, and dates follow each record's own date: requests and
 * reports by created date, jobs by due date, invoices by issue date.
 * Machines, outstanding balances and contracts are always as of today.
 */
class CustomerReports
{
    public const PALETTE = ['#8f1d1d', '#e0ac2e', '#1f2937', '#15803d', '#475569', '#d62828', '#0f766e', '#9ca3af'];
    public const MACHINE_COLORS = ['Active' => '#15803d', 'Due soon' => '#e0ac2e', 'Overdue' => '#d62828'];

    public static function filters(array $raw): array
    {
        return [
            'from' => ! empty($raw['from']) ? Carbon::parse($raw['from'])->startOfDay() : null,
            'to' => ! empty($raw['to']) ? Carbon::parse($raw['to'])->endOfDay() : null,
        ];
    }

    public static function label(array $f): string
    {
        if ($f['from'] || $f['to']) {
            return ($f['from'] ? $f['from']->format('d M Y') : 'start').' to '.($f['to'] ? $f['to']->format('d M Y') : 'today');
        }

        return 'All dates';
    }

    protected static function between($query, array $f, string $column)
    {
        if ($f['from']) {
            $query->whereDate($column, '>=', $f['from']->toDateString());
        }
        if ($f['to']) {
            $query->whereDate($column, '<=', $f['to']->toDateString());
        }

        return $query;
    }

    /** Month starts to chart: the chosen dates, or the last six months. Never more than twelve. */
    protected static function months(array $f): array
    {
        $end = ($f['to'] ?? now())->copy()->startOfMonth();
        $start = $f['from'] ? $f['from']->copy()->startOfMonth() : $end->copy()->subMonths(5);
        if ($start->diffInMonths($end) > 11) {
            $start = $end->copy()->subMonths(11);
        }

        $out = [];
        for ($m = $start->copy(); $m->lte($end); $m->addMonth()) {
            $out[] = $m->copy();
        }

        return $out;
    }

    protected static function perMonth(Collection $items, callable $date, callable $value, array $months, string $color, bool $money): array
    {
        $rows = [];
        foreach ($months as $m) {
            $sum = (int) $items->filter(fn ($i) => $date($i) && $date($i)->isSameMonth($m))->sum($value);
            $rows[] = [
                'label' => $m->format('M y'),
                'value' => $sum,
                'valueLabel' => $money ? ServiceAdminReports::short($sum) : (string) $sum,
                'color' => $color,
            ];
        }

        return $rows;
    }

    protected static function mix(Collection $counts, array $order, array $colors): array
    {
        $out = [];
        foreach ($order as $label) {
            if (($counts[$label] ?? 0) > 0) {
                $out[] = ['label' => $label, 'value' => (int) $counts[$label], 'color' => $colors[$label] ?? '#9ca3af'];
            }
        }

        return $out;
    }

    public static function build(int $customerId, array $raw): array
    {
        $f = self::filters($raw);
        $months = self::months($f);

        // ---- Requests
        $requests = self::between(ServiceRequest::where('customer_id', $customerId), $f, 'created_at')->get();
        $requestMix = self::mix(
            $requests->groupBy('status')->map->count(),
            ServiceAdminReports::REQUEST_ORDER,
            ServiceAdminReports::REQUEST_COLORS
        );
        $requestMonths = self::perMonth($requests, fn ($r) => $r->created_at, fn () => 1, $months, '#8f1d1d', false);

        // ---- Jobs
        $jobs = self::between(WorkOrder::where('customer_id', $customerId), $f, 'due_date')->get();
        $closed = $jobs->where('status', 'Closed');
        $nature = $jobs->groupBy(fn ($j) => $j->nature_of_visit ?: 'Not set')->map->count()->sortDesc();
        $natureRows = [];
        foreach ($nature->take(6) as $label => $n) {
            $natureRows[] = ['label' => (string) $label, 'value' => $n, 'valueLabel' => (string) $n, 'color' => self::PALETTE[count($natureRows) % count(self::PALETTE)]];
        }

        // ---- Service reports released to the customer
        $reports = self::between(
            Document::where('customer_id', $customerId)->where('type', Document::TYPE_REPORT)->where('status', 'Released'),
            $f,
            'created_at'
        )->count();

        // ---- Invoices
        $invoices = self::between(Invoice::where('customer_id', $customerId), $f, 'issued_at')->get();
        $issued = $invoices->where('status', '!=', 'Draft');
        $shown = fn (Invoice $i) => FinanceReports::shownStatus($i);
        $invoiceMix = self::mix(
            $issued->groupBy(fn ($i) => $shown($i))->map->count(),
            ['Unpaid', 'Part paid', 'Paid', 'Overdue'],
            FinanceReports::STATUS_COLORS
        );
        $invoicedMonths = self::perMonth($issued, fn ($i) => $i->issued_at, fn ($i) => $i->amount_minor, $months, '#8f1d1d', true);

        // Money still to collect is today's picture, so the dates do not narrow it.
        $owing = Invoice::where('customer_id', $customerId)->whereIn('status', ['Unpaid', 'Part paid'])->orderBy('due_at')->get();
        $buckets = [
            ['label' => 'Not yet due', 'min' => null, 'max' => 0, 'color' => '#15803d'],
            ['label' => '1 to 30 days late', 'min' => 1, 'max' => 30, 'color' => '#e0ac2e'],
            ['label' => '31 to 60 days late', 'min' => 31, 'max' => 60, 'color' => '#c2410c'],
            ['label' => '61 to 90 days late', 'min' => 61, 'max' => 90, 'color' => '#d62828'],
            ['label' => 'Over 90 days late', 'min' => 91, 'max' => null, 'color' => '#8f1d1d'],
        ];
        $ageing = [];
        foreach ($buckets as $b) {
            $set = $owing->filter(function ($i) use ($b) {
                $late = (int) today()->diffInDays($i->due_at, false) * -1;

                return ($b['min'] === null ? $late <= 0 : $late >= $b['min']) && ($b['max'] === null || $late <= $b['max']);
            });
            $sum = (int) $set->sum(fn ($i) => $i->balanceMinor());
            $ageing[] = ['label' => $b['label'], 'value' => $sum, 'valueLabel' => ServiceAdminReports::short($sum), 'color' => $b['color'], 'count' => $set->count()];
        }

        // ---- Machines (today)
        $machines = Equipment::with('site')->where('customer_id', $customerId)->orderBy('model')->get();
        $machineMix = self::mix($machines->groupBy(fn ($e) => $e->visitStatus())->map->count(), ['Active', 'Due soon', 'Overdue'], self::MACHINE_COLORS);

        // ---- Contracts (today)
        $contractRows = Contract::withVisitCounts()->where('customer_id', $customerId)->orderBy('ends_at')->get()->map(fn ($c) => [
            'c' => $c,
            'left' => $c->visitsRemaining(),
            'used' => $c->visitsUsed(),
            'stage' => $c->ends_at->lt(today()) ? 'Expired' : ($c->ends_at->lte(today()->addDays(60)) ? 'Expiring soon' : 'Active'),
        ]);

        return [
            'label' => self::label($f),
            'kpis' => [
                'requests' => $requests->count(),
                'jobsDone' => $closed->count(),
                'jobs' => $jobs->count(),
                'reports' => $reports,
                'invoicedMinor' => (int) $issued->sum('amount_minor'),
                'outstandingMinor' => (int) $owing->sum(fn ($i) => $i->balanceMinor()),
            ],
            'requestMix' => $requestMix,
            'requestMonths' => $requestMonths,
            'natureRows' => $natureRows,
            'invoiceMix' => $invoiceMix,
            'invoicedMonths' => $invoicedMonths,
            'ageing' => $ageing,
            'owingRows' => $owing,
            'machineMix' => $machineMix,
            'machines' => $machines,
            'contractRows' => $contractRows,
        ];
    }

    public static function csvSections(array $d): array
    {
        $m = fn ($minor) => number_format($minor / 100, 2, '.', '');
        $k = $d['kpis'];
        $mixCsv = fn (string $title, array $mix) => [$title, ['Status', 'Count'], array_map(fn ($r) => [$r['label'], $r['value']], $mix)];
        $chartCsv = fn (string $title, array $rows, bool $money = false) => [$title, ['Label', 'Value'], array_map(fn ($r) => [$r['label'], $money ? $m($r['value']) : $r['value']], $rows)];

        return [
            'summary' => ['Summary', ['Measure', 'Value'], [
                ['Requests raised', $k['requests']],
                ['Jobs completed', $k['jobsDone'].' of '.$k['jobs']],
                ['Service reports received', $k['reports']],
                ['Invoiced ('.currency().')', $m($k['invoicedMinor'])],
                ['Outstanding today ('.currency().')', $m($k['outstandingMinor'])],
            ]],
            'requests' => $mixCsv('Requests by status', $d['requestMix']),
            'requestMonths' => $chartCsv('Requests per month', $d['requestMonths']),
            'nature' => $chartCsv('Jobs by type of visit', $d['natureRows']),
            'invoices' => $mixCsv('Invoices by status', $d['invoiceMix']),
            'invoiced' => $chartCsv('Invoiced per month ('.currency().')', $d['invoicedMonths'], true),
            'ageing' => ['Money still to pay, by age', ['Age', 'Invoices', 'Balance ('.currency().')'], array_map(fn ($a) => [$a['label'], $a['count'], $m($a['value'])], $d['ageing'])],
            'owing' => ['Unpaid invoices', ['Invoice', 'Due', 'Amount ('.currency().')', 'Paid ('.currency().')', 'Balance ('.currency().')', 'Status'], $d['owingRows']->map(fn ($i) => [
                $i->reference, $i->due_at->format('Y-m-d'), $m($i->amount_minor), $m($i->paid_minor), $m($i->balanceMinor()), FinanceReports::shownStatus($i),
            ])->all()],
            'machines' => ['Machines', ['Serial', 'Machine', 'Site', 'Next service', 'Status'], $d['machines']->map(fn ($e) => [
                $e->serial_number, $e->model, $e->site?->name, $e->next_visit_due_at?->format('Y-m-d'), $e->visitStatus(),
            ])->all()],
            'contracts' => ['Contracts', ['Contract', 'Type', 'Ends', 'Visits used', 'Visits left', 'Status'], $d['contractRows']->map(fn ($r) => [
                $r['c']->reference, $r['c']->type, $r['c']->ends_at->format('Y-m-d'), $r['used'], $r['left'], $r['stage'],
            ])->all()],
        ];
    }
}
