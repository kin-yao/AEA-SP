<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceRequest extends Model
{
    protected $table = 'service_requests';

    protected $fillable = [
        'reference',
        'customer_id',
        'customer_site_id',
        'equipment_id',
        'equipment_description',
        'contact_name',
        'fault_description',
        'cover',
        'priority',
        'status',
        'assigned_technician_id',
        'nature_of_visit',
        'logged_by_id',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(CustomerSite::class, 'customer_site_id');
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_technician_id');
    }

    public function loggedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'logged_by_id');
    }

    // Step 1 of the two-step flow: assign a technician, status flips to
    // Assigned. No job exists yet, that's a separate, deliberate step.
    public function assignTechnician(User $technician, string $natureOfVisit): void
    {
        $this->update([
            'assigned_technician_id' => $technician->id,
            'nature_of_visit' => $natureOfVisit,
            'status' => 'Assigned',
        ]);
    }
}