<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    protected $fillable = [
        'reference',
        'invoice_id',
        'customer_id',
        'paid_at',
        'amount_minor',
        'currency_code',
        'method',
        'recorded_by',
    ];

    protected $casts = [
        'paid_at' => 'date',
    ];

    public function __construct(array $attributes = [])
    {
        $this->attributes = array_merge($this->attributes, [
            'currency_code' => currency(),
        ]);

        parent::__construct($attributes);
    }

    protected $attributes = [
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    // The one correct way to record a payment: creates the receipt AND
    // applies it to the invoice's running balance in the same call.
    // Judgment call, not from the prototype: rejects an amount that would
    // overpay the invoice, since the prototype never had real state to
    // protect against this, a UI mockup can't actually be overpaid.
    public static function recordAgainst(Invoice $invoice, string $reference, int $amountMinor, string $method, int $recordedById): self
    {
        if ($amountMinor > $invoice->balanceMinor()) {
            throw new \DomainException(
                'That is more than the balance owed. This invoice has '.$invoice->currency_code.' '.number_format($invoice->balanceMinor() / 100, 2).' left to pay.'
            );
        }

        $payment = static::create([
            'reference' => $reference,
            'invoice_id' => $invoice->id,
            'customer_id' => $invoice->customer_id,
            'currency_code' => $invoice->currency_code,
            'paid_at' => now(),
            'amount_minor' => $amountMinor,
            'method' => $method,
            'recorded_by' => $recordedById,
        ]);

        $invoice->recordPayment($amountMinor);

        return $payment;
    }
}