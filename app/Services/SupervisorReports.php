<?php

namespace App\Services;

use App\Models\Document;
use App\Models\Quotation;
use App\Models\ServiceRequest;
use App\Models\WorkOrder;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Numbers behind the Supervisor's Reports page and its export.
 *
 *  - Jobs dispatched: jobs created (and so given a technician) in the period.
 *  - Quotations approved: Supervisor-sized quotations that were approved in the period.
 *  - Reports checked: service reports the Supervisor has reviewed in the period.
 *  - Response compliance: share of service requests that had a job dispatched within the
 *    response target (24 hours). A request still open inside its 24 hours is not counted yet.
 */
class SupervisorReports
{
    public const RESPONSE_TARGET_HOURS = 24;
    public const TARGET_PERCENT = 90;

    public static function jobsDispatched(Carbon $from, Carbon $to): int
    {
        return WorkOrder::whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->count();
    }

    public static function quotationsApproved(Carbon $from, Carbon $to): int
    {
        return Quotation::where('approval_threshold', 'Supervisor')
            ->whereIn('status', ['Approved', 'Accepted', 'Converted'])
            ->whereBetween('updated_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->count();
    }

    public static function reportsChecked(Carbon $from, Carbon $to): int
    {
        return Document::where('type', Document::TYPE_REPORT)
            ->whereIn('status', ['Checked, ready to post', 'Released'])
            ->whereBetween('updated_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->count();
    }

    /** @return array{met:int,total:int} */
    protected static function responseCounts(Carbon $from, Carbon $to): array
    {
        $requests = ServiceRequest::with('workOrder:id,source_service_request_id,created_at')
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->get(['id', 'created_at']);

        $met = 0;
        $total = 0;

        foreach ($requests as $request) {
            $due = $request->created_at->copy()->addHours(self::RESPONSE_TARGET_HOURS);
            $first = $request->workOrder?->created_at;

            if ($first !== null) {
                $total++;
                if ($first->lte($due)) {
                    $met++;
                }
            } elseif ($due->lt(now())) {
                $total++;
            }
        }

        return ['met' => $met, 'total' => $total];
    }

    /** Whole percent, or null when no request has come due in the period. */
    public static function responseCompliance(Carbon $from, Carbon $to): ?int
    {
        $c = self::responseCounts($from, $to);

        return $c['total'] > 0 ? (int) round($c['met'] / $c['total'] * 100) : null;
    }

    /** @return array{labels:array<int,string>,values:array<int,?int>} eight weeks, oldest first */
    public static function responseWeekly(int $weeks = 8): array
    {
        $labels = [];
        $values = [];

        for ($i = $weeks - 1; $i >= 0; $i--) {
            $start = now()->startOfWeek()->subWeeks($i);
            $labels[] = $start->format('d M');
            $values[] = self::responseCompliance($start, $start->copy()->endOfWeek());
        }

        return ['labels' => $labels, 'values' => $values];
    }

    /** Utilization over the last 30 days, highest first. */
    public static function utilization(): Collection
    {
        return ManagerReports::technicianRows(now()->startOfMonth(), now()->endOfMonth())
            ->map(fn ($r) => ['name' => $r['user']->name, 'utilization' => $r['utilization']])
            ->sortByDesc(fn ($r) => $r['utilization'] ?? -1)
            ->values();
    }

    /** @return array<int, array{label:string,value:int,color:string}> */
    public static function approvalRoute(Carbon $from, Carbon $to): array
    {
        $mine = Quotation::where('approval_threshold', 'Supervisor')
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->count();
        $escalated = Quotation::where('approval_threshold', 'Manager')
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->count();

        return array_values(array_filter([
            ['label' => 'Mine to approve', 'value' => $mine, 'color' => '#d9a21b'],
            ['label' => 'Escalated to Manager', 'value' => $escalated, 'color' => '#8f1d1d'],
        ], fn ($s) => $s['value'] > 0));
    }

    public static function quotations(Carbon $from, Carbon $to, ?int $limit = null): Collection
    {
        $q = Quotation::with(['customer', 'items'])
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->latest();

        return $limit ? $q->take($limit)->get() : $q->get();
    }

    public static function build(Carbon $from, Carbon $to): array
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->endOfDay();

        return [
            'from' => $from,
            'to' => $to,
            'dispatched' => self::jobsDispatched($from, $to),
            'approved' => self::quotationsApproved($from, $to),
            'checked' => self::reportsChecked($from, $to),
            'compliance' => self::responseCompliance($from, $to),
            'weekly' => self::responseWeekly(8),
            'utilization' => self::utilization(),
            'route' => self::approvalRoute($from, $to),
            'quotations' => self::quotations($from, $to),
            'history' => ManagerReports::jobHistory(['from' => $from->toDateString(), 'to' => $to->toDateString()]),
        ];
    }

    /** Every report as plain rows, for the CSV: [title, headers, rows]. */
    public static function csvSections(array $d): array
    {
        $pct = fn ($v) => $v === null ? '' : $v;

        return [
            ['Summary', ['Measure', 'Value'], [
                ['Jobs dispatched', $d['dispatched']],
                ['Quotations approved', $d['approved']],
                ['Reports checked', $d['checked']],
                ['Response compliance %', $pct($d['compliance'])],
            ]],
            ['Response target compliance, last 8 weeks (target '.self::TARGET_PERCENT.'%)', ['Week starting', 'Compliance %'],
                collect($d['weekly']['labels'])->map(fn ($l, $i) => [$l, $pct($d['weekly']['values'][$i])])->all()],
            ['Technician utilization, last 30 days', ['Technician', 'Utilization %'],
                $d['utilization']->map(fn ($u) => [$u['name'], $pct($u['utilization'])])->all()],
            ['Approval route', ['Route', 'Quotations'], array_map(fn ($s) => [$s['label'], $s['value']], $d['route'])],
            ['Quotations', ['Reference', 'Customer', 'Amount (KES)', 'Approval route', 'Status'],
                $d['quotations']->map(fn ($q) => [$q->reference, $q->customer->name, number_format($q->totalMinor() / 100, 2, '.', ''), $q->approval_threshold, $q->status])->all()],
            ['Job history', ['Job', 'Customer', 'Branch', 'Country', 'Technician', 'Nature of visit', 'Due date', 'Value (KES)', 'Status'],
                $d['history']->map(fn ($r) => [
                    $r['job']->reference, $r['job']->customer->name, $r['branch'], $r['country'], $r['technician'],
                    $r['job']->nature_of_visit, $r['job']->due_date->format('Y-m-d'),
                    $r['valueMinor'] > 0 ? number_format($r['valueMinor'] / 100, 2, '.', '') : '', $r['status'],
                ])->all()],
        ];
    }

    /** Weekly compliance as an SVG with a dashed target line. Gaps where a week had no requests. */
    public static function complianceSvg(array $labels, array $values, int $w = 330, int $h = 200): string
    {
        $padL = 34;
        $padR = 12;
        $padT = 12;
        $padB = 26;
        $n = max(count($labels), 1);
        $x = fn ($i) => $padL + ($n > 1 ? $i / ($n - 1) : 0.5) * ($w - $padL - $padR);
        $y = fn ($v) => $padT + (1 - $v / 100) * ($h - $padT - $padB);

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="'.$w.'" height="'.$h.'" viewBox="0 0 '.$w.' '.$h.'">';
        $svg .= '<rect width="'.$w.'" height="'.$h.'" fill="#ffffff"/>';
        foreach ([0, 50, 100] as $g) {
            $gy = round($y($g), 1);
            $svg .= '<line x1="'.$padL.'" x2="'.($w - $padR).'" y1="'.$gy.'" y2="'.$gy.'" stroke="#e4e4e7" stroke-width="1"/>';
            $svg .= '<text x="'.($padL - 6).'" y="'.($gy + 3).'" text-anchor="end" font-size="10" font-family="Helvetica" fill="#71717a">'.$g.'%</text>';
        }
        $ty = round($y(self::TARGET_PERCENT), 1);
        $svg .= '<line x1="'.$padL.'" x2="'.($w - $padR).'" y1="'.$ty.'" y2="'.$ty.'" stroke="#a16207" stroke-width="1.2" stroke-dasharray="4 3"/>';
        foreach ($labels as $i => $label) {
            $svg .= '<text x="'.round($x($i), 1).'" y="'.($h - 8).'" text-anchor="middle" font-size="9" font-family="Helvetica" fill="#71717a">'.e($label).'</text>';
        }

        $run = [];
        $flush = function () use (&$run, &$svg) {
            if (count($run) > 1) {
                $svg .= '<polyline points="'.implode(' ', $run).'" fill="none" stroke="#e31e24" stroke-width="2.2"/>';
            }
            $run = [];
        };
        foreach ($values as $i => $v) {
            if ($v === null) {
                $flush();

                continue;
            }
            $run[] = round($x($i), 1).','.round($y($v), 1);
        }
        $flush();
        foreach ($values as $i => $v) {
            if ($v !== null) {
                $svg .= '<circle cx="'.round($x($i), 1).'" cy="'.round($y($v), 1).'" r="3" fill="#ffffff" stroke="#e31e24" stroke-width="2"/>';
            }
        }

        return $svg.'</svg>';
    }
}
