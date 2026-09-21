<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceReportDetail extends Model
{
    protected $fillable = [
        'document_id',
        'vehicle',
        'branch_id',
        'contact_name',
        'address',
        'nature_of_visit',
        'fault_description',
        'cause',
        'correction',
        'final_result',
        'incident_type',
        'incident_description',
        'repairer_name',
        'customer_signoff_name',
        'customer_signed_at',
    ];

    protected $casts = [
        'customer_signed_at' => 'datetime',
    ];

    protected $attributes = [
        'incident_type' => 'None',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function parts(): HasMany
    {
        return $this->hasMany(ReportPart::class);
    }
}