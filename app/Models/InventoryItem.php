<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryItem extends Model
{
    protected $fillable = [
        'code',
        'name',
        'category',
        'manufacturer',
        'model',
        'unit',
        'serial_tracked',
        'quantity',
        'reorder_level',
        'branch_id',
        'cost_minor',
        'price_minor',
    ];

    protected $casts = [
        'serial_tracked' => 'boolean',
    ];

    // Match the DB column defaults here in PHP too, same lesson as
    // Quotation's vat_rate, so a freshly created record is correct in
    // memory immediately, not just after a ->fresh() round-trip.
    protected $attributes = [
        'unit' => 'Piece',
        'serial_tracked' => false,
        'quantity' => 0,
        'reorder_level' => 0,
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function isBelowReorderLevel(): bool
    {
        return $this->quantity <= $this->reorder_level;
    }

        public function movements(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(StockMovement::class);
    }
}