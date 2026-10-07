<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Country extends Model
{
    protected $fillable = ['name', 'currency_code', 'vat_rate', 'approval_threshold'];

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }
}