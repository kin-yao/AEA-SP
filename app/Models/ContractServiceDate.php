<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractServiceDate extends Model
{
    protected $fillable = ['contract_id', 'due_on', 'work_order_id'];

    protected $casts = ['due_on' => 'date'];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }
}
