<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invoice extends Model
{
    protected $fillable = [
        'reference',
        'customer_id',
        'work_order_id',
        'contract_id',
        'issued_at',
        'due_at',
        'amount_minor',
        'paid_minor',
        'currency_code',
        'status',
        'documents_required_before_send',
        'documents_attached',
        'raised_by',
    ];

    protected $casts = [
        'issued_at' => 'date',
        'due_at' => 'date',
        'documents_required_before_send' => 'boolean',
        'documents_attached' => 'boolean',
    ];

    // Match the DB column defaults here in PHP too, same lesson as
    // Quotation's vat_rate, so a freshly created record is correct in
    // memory immediately, not just after a ->fresh() round-trip.
    protected $attributes = [
        'paid_minor' => 0,
        'currency_code' => 'KES',
        'status' => 'Draft',
        'documents_required_before_send' => false,
        'documents_attached' => false,
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function raisedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'raised_by');
    }

    public function payments(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function balanceMinor(): int
    {
        return $this->amount_minor - $this->paid_minor;
    }

    // Not a standalone create-a-payment method, that belongs to Payment
    // once it exists. This just applies an already-recorded payment amount
    // to the invoice's running total and recomputes status.
    public function recordPayment(int $amountMinor): void
    {
        $this->paid_minor += $amountMinor;
        $this->status = $this->paid_minor >= $this->amount_minor ? 'Paid' : 'Part paid';
        $this->save();
    }
}