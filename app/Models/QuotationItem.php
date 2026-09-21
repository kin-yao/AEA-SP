<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuotationItem extends Model
{
    protected $fillable = [
        'quotation_id',
        'description',
        'quantity',
        'rate_minor',
        'sort_order',
    ];

    // Match the DB column default here in PHP too, same lesson as
    // Quotation's vat_rate, so a freshly created record is correct in
    // memory immediately, not just after a ->fresh() round-trip.
    protected $attributes = [
        'sort_order' => 0,
    ];

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function amountMinor(): int
    {
        return $this->quantity * $this->rate_minor;
    }
}