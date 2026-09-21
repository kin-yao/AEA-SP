<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryNoteDetail extends Model
{
    protected $fillable = [
        'document_id',
        'items_description',
        'recipient_note',
    ];

    // Same lesson as Quotation's vat_rate: match the DB column default here
    // in PHP too, so a freshly created record is correct in memory
    // immediately, not just after a ->fresh() round-trip.
    protected $attributes = [
        'recipient_note' => 'Signature not required',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}