<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Quotation extends Model
{
    use SoftDeletes;

    // Below this, Supervisor approves. At or above, it routes to Manager.
    // Confirmed independently by two survey respondents, treat this as a
    // business constant, not a per-quotation setting.
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

    // PHP-level defaults, matching the database column defaults exactly.
    // Needed because Eloquent doesn't pull DB-generated defaults back into
    // the in-memory object after create(), so without these, a freshly
    // created (not yet ->fresh()'d) Quotation would have vat_rate = null
    // and totalMinor() would silently compute VAT as zero.
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

    // Call this after items/labour are finalised, before saving, to set the
    // correct approval route and starting status.
    public function routeApproval(): void
    {
        $manager = $this->totalMinor() >= self::APPROVAL_THRESHOLD_MINOR;

        $this->approval_threshold = $manager ? 'Manager' : 'Supervisor';
        $this->status = $manager ? 'Awaiting Manager' : 'Awaiting Supervisor';
    }
}