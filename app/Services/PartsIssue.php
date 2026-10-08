<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\ReportPart;
use App\Models\ServiceReportDetail;
use App\Models\StockMovement;
use App\Models\WorkOrder;

/**
 * Takes the parts a technician lists on a submitted service report out of stock, so nobody has
 * to log a stock movement by hand. It works from what has already been issued against the job,
 * so submitting again, or a manual issue made earlier, never deducts twice.
 */
class PartsIssue
{
    /** @return array<int,string> plain-language problems, empty when everything was deducted */
    public static function forReport(WorkOrder $job, ServiceReportDetail $detail, int $userId): array
    {
        $problems = [];

        $needed = ReportPart::where('service_report_detail_id', $detail->id)
            ->whereNotNull('inventory_item_id')
            ->get()
            ->groupBy('inventory_item_id')
            ->map(fn ($lines) => (int) $lines->sum(fn ($l) => max(0, $l->quantity - $l->returned)));

        foreach ($needed as $itemId => $use) {
            $item = InventoryItem::with('branch')->find($itemId);
            if (! $item) {
                continue;
            }

            $already = -1 * (int) StockMovement::where('work_order_id', $job->id)
                ->where('inventory_item_id', $itemId)
                ->sum('quantity_delta');

            $delta = $use - $already; // positive: take more out, negative: put some back

            if ($delta === 0) {
                continue;
            }

            try {
                StockMovement::recordAgainst(
                    $item,
                    \App\Models\ReferenceSeries::next('stock_movement'),
                    $delta > 0 ? 'Issue' : 'Adjustment',
                    -$delta,
                    $userId,
                    [
                        'work_order_id' => $job->id,
                        'from_location' => $delta > 0 ? $item->branch?->name : null,
                        'to_location' => $delta < 0 ? $item->branch?->name : null,
                    ]
                );
            } catch (\DomainException $e) {
                $problems[] = $e->getMessage();
            }
        }

        return $problems;
    }
}
