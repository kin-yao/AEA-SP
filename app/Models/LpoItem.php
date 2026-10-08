<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LpoItem extends Model
{
    protected $fillable = ['lpo_detail_id', 'description', 'quantity', 'rate_minor', 'sort_order'];

    protected $casts = ['quantity' => 'float'];

    public function lpo(): BelongsTo
    {
        return $this->belongsTo(LpoDetail::class, 'lpo_detail_id');
    }

    public function amountMinor(): int
    {
        return (int) round($this->quantity * $this->rate_minor);
    }
}
