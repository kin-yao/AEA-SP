<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'reference',
        'name',
        'branch_id',
        'kra_pin',
        'po_box',
        'main_contact_name',
        'main_contact_email',
        'main_contact_phone',
        'has_active_contract',
        'balance_minor',
    ];

    protected $casts = [
        'has_active_contract' => 'boolean',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function balanceFormatted(): string
    {
        return number_format($this->balance_minor / 100, 2);
    }
}