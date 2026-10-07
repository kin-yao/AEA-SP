<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkOrder extends Model
{
    protected $fillable = [
        'reference',
        'customer_id',
        'contract_id',
        'customer_site_id',
        'equipment_id',
        'equipment_description',
        'nature_of_visit',
        'priority',
        'assigned_technician_id',
        'vehicle',
        'due_date',
        'status',
        'source_service_request_id',
        'source_quotation_id',
        'value_type',
        'value_minor',
        'currency_code',
    ];

    protected $casts = [
        'due_date' => 'date',
    ];

    // Same reasoning as Quotation: match the DB column defaults here in PHP
    // too, so a freshly created WorkOrder is correct in memory immediately,
    // not just after a ->fresh() round-trip. Learned that lesson the hard
    // way on Quotation's vat_rate, not repeating it here.
    public function __construct(array $attributes = [])
    {
        $this->attributes = array_merge($this->attributes, [
            'currency_code' => currency(),
        ]);

        parent::__construct($attributes);
    }

    protected $attributes = [
        'priority' => 'Medium',
        'status' => 'Assigned',
        'value_type' => 'tbd',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(CustomerSite::class, 'customer_site_id');
    }

    public function directionsUrl(): ?string
    {
        return $this->site?->directionsUrl();
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_technician_id');
    }

    public function sourceRequest(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class, 'source_service_request_id');
    }

    public function sourceQuotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class, 'source_quotation_id');
    }

    public function documents(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function invoices(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function stockMovements(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(StockMovement::class);
    }
}