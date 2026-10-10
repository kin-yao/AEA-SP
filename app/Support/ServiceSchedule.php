<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/** Builds the planned service dates for a contract from a maintenance frequency. */
class ServiceSchedule
{
    public const MAX_DATES = 200;

    /** @return array<int,string> Y-m-d dates, first one period after the start, then every period up to the end. */
    public static function generate(CarbonInterface $start, CarbonInterface $end, int $every, string $unit): array
    {
        if ($every < 1 || $end->lt($start)) {
            return [];
        }

        $start = CarbonImmutable::parse($start->toDateString());
        $end = CarbonImmutable::parse($end->toDateString());
        $dates = [];
        $last = null;

        for ($k = 1; $k <= self::MAX_DATES; $k++) {
            $d = self::step($start, $every * $k, $unit);

            if ($d->gt($end)) {
                // A final visit that only just misses the end date is pulled back to the end date.
                if ($last && $last->lt($end) && abs($d->diffInDays($end)) <= 3) {
                    $dates[] = $end->toDateString();
                }
                break;
            }

            $dates[] = $d->toDateString();
            $last = $d;
        }

        return array_values(array_unique($dates));
    }

    private static function step(CarbonImmutable $from, int $n, string $unit): CarbonImmutable
    {
        return match ($unit) {
            'w' => $from->addWeeks($n),
            'd' => $from->addDays($n),
            default => $from->addMonthsNoOverflow($n),
        };
    }

    public static function unitName(string $unit, int $n): string
    {
        return match ($unit) {
            'w' => $n === 1 ? 'week' : 'weeks',
            'd' => $n === 1 ? 'day' : 'days',
            default => $n === 1 ? 'month' : 'months',
        };
    }
}
