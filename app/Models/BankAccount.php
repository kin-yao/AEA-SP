<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankAccount extends Model
{
    protected $fillable = ['country_id', 'currency_code', 'bank_name', 'account_name', 'account_number', 'branch', 'swift', 'active'];

    protected $casts = ['active' => 'boolean'];

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    /**
     * The accounts to print on a quotation or invoice: those for its currency
     * that serve the customer's country (or every country). If none match, fall
     * back to the active accounts for that country, then to all active accounts,
     * so a document never goes out with no way to pay.
     */
    public static function forDocument(?string $currency, ?int $countryId): Collection
    {
        $active = static::where('active', true)->orderBy('id')->get();
        $serves = fn ($a) => $a->country_id === null || $a->country_id === $countryId;

        $pick = $active->filter(fn ($a) => $a->currency_code === $currency && $serves($a));
        if ($pick->isEmpty()) {
            $pick = $active->filter($serves);
        }

        return $pick->isEmpty() ? $active : $pick->values();
    }
}
