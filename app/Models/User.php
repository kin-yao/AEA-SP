<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasRoles, SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'password',
        'branch_id',
        'customer_id',
        'phone',
        'status',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $attributes = [
        'status' => 'Active',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    // Only meaningful for users with the Customer role, null for everyone
    // else, same pattern as branch() being null for Super Admin.
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function assignedRequests(): HasMany
    {
        return $this->hasMany(ServiceRequest::class, 'assigned_technician_id');
    }

    public function technicianDocuments(): HasMany
    {
        return $this->hasMany(TechnicianDocument::class, 'technician_id');
    }
}