<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Contract extends Model
{
    protected $fillable = [
        'reference', 'customer_id', 'type', 'starts_at', 'ends_at',
        'visits_included', 'visits_used', 'value_minor', 'currency_code',
        'status', 'scan_file_path',
    ];

    protected $casts = ['starts_at' => 'date', 'ends_at' => 'date'];

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

    // Percentage of the contract term elapsed, uncapped internally so
    // expiryStage() can still tell "just expired" from "expired months
    // ago" if ever needed, capped only for display.
    public function percentOfTermUsed(): int
    {
        $totalDays = $this->starts_at->diffInDays($this->ends_at) ?: 1;
        $elapsedDays = $this->starts_at->diffInDays(now(), false);

        return max(0, (int) round($elapsedDays / $totalDays * 100));
    }

    public function percentOfTermUsedCapped(): int
    {
        return min($this->percentOfTermUsed(), 100);
    }

    // Four stages, exact thresholds: under 50% elapsed is fresh, 50-74%
    // is the first warning, 75-99% is the second, 100% or past the end
    // date is critical.
    public function expiryStage(): string
    {
        $pct = $this->percentOfTermUsed();

        return match (true) {
            $pct >= 100 => 'critical',
            $pct >= 75 => 'urgent',
            $pct >= 50 => 'warn',
            default => 'fresh',
        };
    }
}
