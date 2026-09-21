<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaintenanceVoucherDetail extends Model
{
    protected $fillable = [
        'document_id',
        'contract_id',
        'technician_id',
        'client_signatory',
        'client_signed_at',
    ];

    protected $casts = [
        'client_signed_at' => 'datetime',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technician_id');
    }

        public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }
}