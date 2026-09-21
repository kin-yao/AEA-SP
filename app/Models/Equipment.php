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

        return $this->next_visit_due_at->isPast() ? 'Overdue'
            : ($this->next_visit_due_at->diffInDays(now()) <= 30 ? 'Due soon' : 'Active');
    }
}