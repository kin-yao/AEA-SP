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
        'report_date',
        'tel_no',
        'equipment_description',
        'parts_to_order',
        'customer_comments',
        'contract_on_file',
        'voucher_number',
        'delivery_note_path',
        'incident_photo_path',
        'repairer_signature',
        'customer_signature',
        'voucher_signature',
    ];

    protected $casts = [
        'customer_signed_at' => 'datetime',
        'report_date' => 'date',
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