<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportPart extends Model
{
    protected $fillable = [
        'service_report_detail_id',
        'inventory_item_id',
        'item',
        'part_number',
        'quantity',
        'source',
        'returned',
    ];

    // Match the DB column default here in PHP too, same lesson as
    // Quotation's vat_rate, so a freshly created record is correct in
    // memory immediately, not just after a ->fresh() round-trip.
    protected $attributes = [
        'quantity' => 1,
        'returned' => 0,
    ];

    public function reportDetail(): BelongsTo
    {
        return $this->belongsTo(ServiceReportDetail::class, 'service_report_detail_id');
    }
}