<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'vat_rate',
        'paid_minor',
        'currency_code',
        'status',
        'documents_required_before_send',
        'documents_attached',
        'raised_by',
        'lpo_document_id',
    ];

    protected $casts = [
        'issued_at' => 'date',
        'due_at' => 'date',
        'vat_rate' => 'decimal:3',
        'documents_required_before_send' => 'boolean',
        'documents_attached' => 'boolean',
    ];

    public function __construct(array $attributes = [])
    {
        $this->attributes = array_merge($this->attributes, [
            'vat_rate' => setting('vat_rate') / 100,
            'currency_code' => currency(),
        ]);

        parent::__construct($attributes);
    }

    protected $attributes = [
        'paid_minor' => 0,
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

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function itemsSubtotalMinor(): int
    {
        return $this->items->sum(fn (InvoiceItem $item) => $item->amountMinor());
    }

    // VAT computed from the real item subtotal and the rate actually
    // stored on this invoice, never a hardcoded percentage, since the
    // rate itself is now an editable input, not a constant.
    public function vatMinor(): int
    {
        return (int) round($this->itemsSubtotalMinor() * (float) $this->vat_rate);
    }

    public function balanceMinor(): int
    {
        return $this->amount_minor - $this->paid_minor;
    }

    public function recordPayment(int $amountMinor): void
    {
        $this->paid_minor += $amountMinor;
        $this->status = $this->paid_minor >= $this->amount_minor ? 'Paid' : 'Part paid';
        $this->save();
    }
}
