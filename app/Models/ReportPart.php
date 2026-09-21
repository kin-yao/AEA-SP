<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportPart extends Model
{
    protected $fillable = [
        'service_report_detail_id',
        'item',
        'part_number',
        'quantity',
        'source',
    ];

    public function reportDetail(): BelongsTo
    {
        return $this->belongsTo(ServiceReportDetail::class, 'service_report_detail_id');
    }
}