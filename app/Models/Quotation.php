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
        'rejection_reason',
        'decided_by',
        'decided_at',
        'escalated_by',
        'escalated_at',
        'escalation_note',
    ];

    protected $casts = [
        'vat_rate' => 'decimal:3',
        'decided_at' => 'datetime',
        'escalated_at' => 'datetime',
    ];

    public function __construct(array $attributes = [])
    {
        $this->attributes = array_merge($this->attributes, [
            'validity_days' => setting('quotation_validity_days'),
            'vat_rate' => setting('vat_rate') / 100,
            'currency_code' => currency(),
        ]);

        parent::__construct($attributes);
    }

    protected $attributes = [
        'labour_minor' => 0,
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

    /** What the customer has actually agreed to pay: the LPO's total once it is on file, else this quotation's. */
    public function bindingTotalMinor(): int
    {
        $lpo = $this->lpoDetail;
        if ($lpo) {
            $lpo->ensureLines();
            $lpo->load('items');

            return $lpo->totalMinor();
        }

        return $this->totalMinor();
    }

    public function routeApproval(): void
    {
        $limitMinor = $this->customer ? (int) round($this->customer->approvalLimit() * 100) : \App\Support\Settings::approvalThresholdMinor();
        $manager = $this->totalMinor() >= $limitMinor;

        $this->approval_threshold = $manager ? 'Manager' : 'Supervisor';
        $this->status = $manager ? 'Awaiting Manager' : 'Awaiting Supervisor';
    }

    public function approve(?int $byId = null): void
    {
        $this->status = 'Approved';
        $this->rejection_reason = null;
        $this->decided_by = $byId;
        $this->decided_at = now();
        $this->save();
    }

    /** A rejection always carries the reason, so the person who wrote the quote knows what to change. */
    public function reject(string $reason, ?int $byId = null): void
    {
        $this->status = 'Rejected';
        $this->rejection_reason = $reason;
        $this->decided_by = $byId;
        $this->decided_at = now();
        $this->save();
    }

    /** A Supervisor passes the decision up to the Manager. */
    public function escalate(int $byId, ?string $note = null): void
    {
        $this->approval_threshold = 'Manager';
        $this->status = 'Awaiting Manager';
        $this->escalated_by = $byId;
        $this->escalated_at = now();
        $this->escalation_note = $note ?: null;
        $this->save();
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function escalator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'escalated_by');
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

        $lpo = $document->lpoDetail()->create([
            'quotation_id' => $this->id,
            'received_via' => $receivedVia,
            'file_path' => $filePath,
        ]);
        $lpo->ensureLines(); // the LPO starts as a copy of this quotation, and can then be edited

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
            'value_minor' => $this->bindingTotalMinor(),
            'currency_code' => $this->currency_code,
        ]);

        $this->update([
            'converted_work_order_id' => $workOrder->id,
            'status' => 'Converted',
        ]);

        if ($this->source_service_request_id) {
            ServiceRequest::whereKey($this->source_service_request_id)->update([
                'assigned_technician_id' => $technicianId,
                'status' => 'Converted',
            ]);
        }

        return $workOrder;
    }
}
