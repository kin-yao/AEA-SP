<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMovement extends Model
{
    protected $fillable = [
        'reference',
        'inventory_item_id',
        'type',
        'quantity_delta',
        'from_location',
        'to_location',
        'work_order_id',
        'recorded_by',
        'occurred_at',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
    ];

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    // The one correct way to record a movement: creates the row AND
    // applies it to the item's running quantity in the same call, closing
    // the gap we named but didn't fix back in the original Inventory
    // verification, quantity and the movement trail could drift apart
    // before this existed. Judgment call, not from the prototype: rejects
    // a movement that would take stock negative, same category as the
    // Payment overpayment guard, a data-integrity check the prototype
    // never needed since nothing there could actually go wrong.
    public static function recordAgainst(InventoryItem $item, string $reference, string $type, int $quantityDelta, int $recordedById, array $extra = []): self
    {
        if ($item->quantity + $quantityDelta < 0) {
            throw new \DomainException(
                "Movement of {$quantityDelta} would take {$item->name} below zero, current stock is {$item->quantity}."
            );
        }

        $movement = static::create(array_merge([
            'reference' => $reference,
            'inventory_item_id' => $item->id,
            'type' => $type,
            'quantity_delta' => $quantityDelta,
            'recorded_by' => $recordedById,
            'occurred_at' => now(),
        ], $extra));

        $item->update(['quantity' => $item->quantity + $quantityDelta]);

        return $movement;
    }
}