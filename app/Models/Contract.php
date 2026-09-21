<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Contract extends Model
{
    protected $fillable = [
        'reference',
        'customer_id',
        'type',
        'starts_at',
        'ends_at',
        'visits_included',
        'visits_used',
        'value_minor',
        'currency_code',
        'status',
        'scan_file_path',
    ];

    protected $casts = [
        'starts_at' => 'date',
        'ends_at' => 'date',
    ];

    // Match the DB column defaults here in PHP too, same lesson as
    // Quotation's vat_rate, so a freshly created record is correct in
    // memory immediately, not just after a ->fresh() round-trip.
    protected $attributes = [
        'visits_included' => 0,
        'visits_used' => 0,
        'currency_code' => 'KES',
        'status' => 'Active',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function visitsRemaining(): int
    {
        return max(0, $this->visits_included - $this->visits_used);
    }

    // Percentage of the contract term elapsed. Yellow at 75%, red at 90%,
    // same thresholds as technician document expiry, drives the dashboard
    // "Contracts nearing renewal" cards.
    public function percentOfTermUsed(): int
    {
        $totalDays = $this->starts_at->diffInDays($this->ends_at) ?: 1;
        $elapsedDays = $this->starts_at->diffInDays(now());

        return max(0, min(100, (int) round($elapsedDays / $totalDays * 100)));
    }

    public function riskLevel(): string
    {
        $pct = $this->percentOfTermUsed();

        return $pct >= 90 ? 'bad' : ($pct >= 75 ? 'warn' : 'ok');
    }
}