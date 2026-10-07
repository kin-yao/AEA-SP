<?php

namespace App\Support;

/** Sorts unpaid invoices into "days late" groups in one pass (the per-bucket version re-did the date maths 5 times per invoice). */
class Ageing
{
    /** @return array<int, array{sum:int,count:int}> in the same order as the buckets given */
    public static function sort($owing, array $buckets): array
    {
        $out = array_map(fn () => ['sum' => 0, 'count' => 0], $buckets);
        $todayTs = today()->timestamp;

        foreach ($owing as $invoice) {
            $late = (int) floor(($todayTs - $invoice->due_at->timestamp) / 86400);

            foreach ($buckets as $k => $b) {
                if (($b['min'] === null ? $late <= 0 : $late >= $b['min']) && ($b['max'] === null || $late <= $b['max'])) {
                    $out[$k]['sum'] += $invoice->balanceMinor();
                    $out[$k]['count']++;
                    break;
                }
            }
        }

        return $out;
    }
}
