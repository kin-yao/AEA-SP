<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Equipment extends Model
{
    protected $fillable = [
        'serial_number',
        'model',
        'customer_id',
        'customer_site_id',
        'category',
        'installed_at',
        'warranty_expires_at',
        'cover',
        'next_visit_due_at',
    ];

    protected $casts = [
        'installed_at' => 'date',
        'warranty_expires_at' => 'date',
        'next_visit_due_at' => 'date',
    ];

    // Match the DB column default here in PHP too, same lesson as
    // Quotation's vat_rate, so a freshly created record is correct in
    // memory immediately, not just after a ->fresh() round-trip.
    protected $attributes = [
        'cover' => 'Chargeable',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(CustomerSite::class, 'customer_site_id');
    }

    public function visitStatus(): string
    {
        if (! $this->next_visit_due_at) {
            return 'Overdue';
        }

        $due = $this->next_visit_due_at;

        if ($due->lt(today())) {
            return 'Overdue';
        }

        return $due->lte(today()->addDays(30)) ? 'Due soon' : 'Active';
    }
}