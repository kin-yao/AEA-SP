<?php

namespace App\Services;

use App\Models\WorkOrder;
use Carbon\Carbon;

/**
 * Everything on the Manager's Reports page, worked out for a chosen date range.
 * Used by the full PDF export and the all-reports CSV so both always agree.
 */
class ManagerExport
{
    public static function build(Carbon $from, Carbon $to): array
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->endOfDay();

        $contracts = ManagerReports::contractsExpiring(60);

        return [
            'from' => $from,
            'to' => $to,
            'revenueMinor' => ManagerReports::revenueMinor($from, $to),
            'jobsClosed' => WorkOrder::where('status', 'Closed')->whereBetween('updated_at', [$from, $to])->count(),
            'approvals' => ManagerReports::approvalsGiven($from, $to),
            'contracts' => $contracts,
            'branches' => ManagerReports::revenueByBranch($from, $to),
            'trend' => self::trend($from, $to),
            'mix' => ManagerReports::jobMix(),
            'techs' => ManagerReports::technicianRows($from, $to),
            'history' => ManagerReports::jobHistory(['from' => $from->toDateString(), 'to' => $to->toDateString()]),
        ];
    }

    /** Whole calendar months covering the range, never fewer than six, oldest first. */
    public static function trend(Carbon $from, Carbon $to): array
    {
        $months = [];
        $end = $to->copy()->startOfMonth();
        for ($m = $from->copy()->startOfMonth(); $m->lte($end); $m->addMonthNoOverflow()) {
            $months[] = $m->copy();
        }
        while (count($months) < 6) {
            array_unshift($months, $months[0]->copy()->subMonthNoOverflow());
        }
        $months = array_slice($months, -24);

        $fmt = count($months) > 12 ? 'M y' : 'M';
        $labels = [];
        $values = [];
        foreach ($months as $m) {
            $labels[] = $m->format($fmt);
            $values[] = round(ManagerReports::revenueMinor($m, $m->copy()->endOfMonth()) / 100, 2);
        }

        return ['labels' => $labels, 'values' => $values];
    }

    public static function lineSvg(array $labels, array $values, int $w = 330, int $h = 200): string
    {
        $padL = 50;
        $padR = 12;
        $padT = 12;
        $padB = 26;
        $n = max(count($labels), 1);
        $raw = max($values ?: [0]);
        $mag = $raw > 0 ? pow(10, floor(log10($raw))) : 1;
        $top = max($raw > 0 ? ceil($raw / $mag * 2) / 2 * $mag : 1, 1);
        $x = fn ($i) => $padL + ($n > 1 ? $i / ($n - 1) : 0.5) * ($w - $padL - $padR);
        $y = fn ($v) => $padT + (1 - $v / $top) * ($h - $padT - $padB);
        $short = function ($v) {
            if ($v >= 1000000) {
                return rtrim(rtrim(number_format($v / 1000000, 1), '0'), '.').'M';
            }
            if ($v >= 1000) {
                return rtrim(rtrim(number_format($v / 1000, 1), '0'), '.').'k';
            }

            return (string) round($v);
        };

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="'.$w.'" height="'.$h.'" viewBox="0 0 '.$w.' '.$h.'">';
        $svg .= '<rect width="'.$w.'" height="'.$h.'" fill="#ffffff"/>';
        for ($t = 0; $t <= 4; $t++) {
            $gv = $top * $t / 4;
            $gy = round($y($gv), 1);
            $svg .= '<line x1="'.$padL.'" x2="'.($w - $padR).'" y1="'.$gy.'" y2="'.$gy.'" stroke="#e4e4e7" stroke-width="1"/>';
            $svg .= '<text x="'.($padL - 6).'" y="'.($gy + 3).'" text-anchor="end" font-size="10" font-family="Helvetica" fill="#71717a">'.$short($gv).'</text>';
        }
        foreach ($labels as $i => $label) {
            $svg .= '<text x="'.round($x($i), 1).'" y="'.($h - 8).'" text-anchor="middle" font-size="10" font-family="Helvetica" fill="#71717a">'.e($label).'</text>';
        }
        $pts = [];
        foreach ($values as $i => $v) {
            $pts[] = round($x($i), 1).','.round($y($v), 1);
        }
        $svg .= '<polyline points="'.implode(' ', $pts).'" fill="none" stroke="#e31e24" stroke-width="2.2"/>';
        foreach ($values as $i => $v) {
            $svg .= '<circle cx="'.round($x($i), 1).'" cy="'.round($y($v), 1).'" r="3" fill="#ffffff" stroke="#e31e24" stroke-width="2"/>';
        }

        return $svg.'</svg>';
    }

    public static function pieSvg(array $mix, int $size = 150): string
    {
        $total = array_sum(array_column($mix, 'value'));
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="'.$size.'" height="'.$size.'" viewBox="0 0 100 100">';
        if ($total === 0) {
            return $svg.'<circle cx="50" cy="50" r="48" fill="#f4f4f5"/></svg>';
        }
        if (count($mix) === 1) {
            return $svg.'<circle cx="50" cy="50" r="48" fill="'.$mix[0]['color'].'"/></svg>';
        }
        $angle = -M_PI / 2;
        foreach ($mix as $slice) {
            $sweep = $slice['value'] / $total * 2 * M_PI;
            $x1 = 50 + 48 * cos($angle);
            $y1 = 50 + 48 * sin($angle);
            $angle += $sweep;
            $x2 = 50 + 48 * cos($angle);
            $y2 = 50 + 48 * sin($angle);
            $svg .= '<path d="M50,50 L'.round($x1, 2).','.round($y1, 2).' A48,48 0 '.($sweep > M_PI ? 1 : 0).' 1 '.round($x2, 2).','.round($y2, 2).' Z" fill="'.$slice['color'].'" stroke="#ffffff" stroke-width="0.8"/>';
        }

        return $svg.'</svg>';
    }

    public static function dataUri(string $svg): string
    {
        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
