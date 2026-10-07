<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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

    protected $attributes = [
        'has_active_contract' => false,
        'balance_minor' => 0,
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function country(): ?Country
    {
        return $this->branch?->country;
    }

    /** The currency this customer is billed in: their country's, else the default. */
    public function currencyCode(): string
    {
        return $this->country()?->currency_code ?: currency();
    }

    /** VAT as a percent: the country's own rate if it has one, else the default. */
    public function vatPercent(): float
    {
        $rate = $this->country()?->vat_rate;

        return $rate !== null ? (float) $rate : (float) setting('vat_rate');
    }

    /** Quotation total, in major units, from which a Manager must approve. */
    public function approvalLimit(): float
    {
        $limit = $this->country()?->approval_threshold;

        return $limit !== null ? (float) $limit : (float) setting('approval_threshold');
    }

    public function sites(): HasMany
    {
        return $this->hasMany(CustomerSite::class);
    }

    public function equipment(): HasMany
    {
        return $this->hasMany(Equipment::class);
    }

    public function serviceRequests(): HasMany
    {
        return $this->hasMany(ServiceRequest::class);
    }

    public function quotations(): HasMany
    {
        return $this->hasMany(Quotation::class);
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    // The portal login accounts belonging to this customer, e.g. their main
    // contact, distinct from main_contact_name/email/phone above, which are
    // just contact details, not necessarily someone with a login.
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function balanceFormatted(): string
    {
        return number_format($this->balance_minor / 100, 2);
    }
}