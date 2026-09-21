<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LpoDetail extends Model
{
    protected $table = 'lpo_details';

    protected $fillable = [
        'document_id',
        'quotation_id',
        'received_via',
        'file_path',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }
}