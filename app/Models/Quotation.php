<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Quotation extends Model
{
    use SoftDeletes;

    public const APPROVAL_THRESHOLD_MINOR = 300_000_000; // KES 3,000,000 in minor units

    protected $fillable = [
        'reference',
        'customer_id',
        'customer_site_id',
        'source_service_request_id',
        'scope',
        'validity_days',
        'payment_terms',
        'labour_minor',
        'vat_rate',
        'currency_code',
        'lpo_status',
        'lpo_reference',
        'approval_threshold',
        'status',
        'converted_work_order_id',
        'created_by',
    ];

    protected $casts = [
        'vat_rate' => 'decimal:3',
    ];

    protected $attributes = [
        'validity_days' => 30,
        'labour_minor' => 0,
        'vat_rate' => 0.160,
        'currency_code' => 'KES',
        'lpo_status' => 'Not yet received',
        'approval_threshold' => 'Supervisor',
        'status' => 'Awaiting Supervisor',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(CustomerSite::class, 'customer_site_id');
    }

    public function sourceRequest(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class, 'source_service_request_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuotationItem::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class, 'converted_work_order_id');
    }

    public function lpoDetail(): HasOne
    {
        return $this->hasOne(LpoDetail::class);
    }

    public function itemsSubtotalMinor(): int
    {
        return $this->items->sum(fn (QuotationItem $item) => $item->quantity * $item->rate_minor);
    }

    public function subtotalMinor(): int
    {
        return $this->itemsSubtotalMinor() + $this->labour_minor;
    }

    public function vatMinor(): int
    {
        return (int) round($this->subtotalMinor() * (float) $this->vat_rate);
    }

    public function totalMinor(): int
    {
        return $this->subtotalMinor() + $this->vatMinor();
    }

    public function routeApproval(): void
    {
        $manager = $this->totalMinor() >= self::APPROVAL_THRESHOLD_MINOR;

        $this->approval_threshold = $manager ? 'Manager' : 'Supervisor';
        $this->status = $manager ? 'Awaiting Manager' : 'Awaiting Supervisor';
    }

    public function approve(): void
    {
        $this->status = 'Approved';
        $this->save();
    }

    public function sendBack(): void
    {
        $this->status = 'Sent back';
        $this->save();
    }

    // Now takes an actual uploaded file path, nullable, since a customer
    // might send the LPO by hand before the scanned copy follows, that's
    // still a real, correctly-logged LPO, just without the file attached
    // yet.
    public function logLpo(string $reference, string $receivedVia, int $loggedById, ?string $filePath = null): Document
    {
        $document = Document::create([
            'reference' => $reference,
            'type' => Document::TYPE_LPO,
            'customer_id' => $this->customer_id,
            'status' => 'On file',
            'filed_by' => $loggedById,
        ]);

        $document->lpoDetail()->create([
            'quotation_id' => $this->id,
            'received_via' => $receivedVia,
            'file_path' => $filePath,
        ]);

        $this->update([
            'lpo_status' => 'On file',
            'lpo_reference' => $reference,
            'status' => 'Accepted',
        ]);

        return $document;
    }

    public function convertToJob(int $technicianId, string $dueDate, string $reference): WorkOrder
    {
        $workOrder = WorkOrder::create([
            'reference' => $reference,
            'customer_id' => $this->customer_id,
            'customer_site_id' => $this->customer_site_id,
            'nature_of_visit' => $this->scope,
            'assigned_technician_id' => $technicianId,
            'due_date' => $dueDate,
            'source_quotation_id' => $this->id,
            'value_type' => 'chargeable',
            'value_minor' => $this->totalMinor(),
            'currency_code' => $this->currency_code,
        ]);

        $this->update([
            'converted_work_order_id' => $workOrder->id,
            'status' => 'Converted',
        ]);

        return $workOrder;
    }
}
