<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerSite extends Model
{
    protected $fillable = [
        'customer_id',
        'name',
        'address',
        'contact_name',
        'lat',
        'lng',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function equipment(): HasMany
    {
        return $this->hasMany(Equipment::class);
    }

    public function hasCoordinates(): bool
    {
        return $this->lat !== null && $this->lng !== null;
    }

    /** Google Maps directions to this site, or null when there is nothing to navigate to. */
    public function directionsUrl(): ?string
    {
        if ($this->hasCoordinates()) {
            return 'https://www.google.com/maps/dir/?api=1&travelmode=driving&destination='.$this->lat.','.$this->lng;
        }

        $place = trim((string) $this->address);

        return $place !== '' ? 'https://www.google.com/maps/dir/?api=1&travelmode=driving&destination='.urlencode($place) : null;
    }
}