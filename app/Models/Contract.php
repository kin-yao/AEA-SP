<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contract extends Model
{
    protected $fillable = [
        'reference', 'customer_id', 'type', 'frequency', 'starts_at', 'ends_at',
        'visits_included', 'visits_used', 'value_minor', 'currency_code',
        'status', 'scan_file_path',
    ];

    protected $casts = ['starts_at' => 'date', 'ends_at' => 'date'];

    public function __construct(array $attributes = [])
    {
        $this->attributes = array_merge($this->attributes, [
            'currency_code' => currency(),
        ]);

        parent::__construct($attributes);
    }

    protected $attributes = [
        'visits_included' => 0,
        'visits_used' => 0,
        'status' => 'Active',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** The machines this contract covers. */
    public function equipment(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Equipment::class, 'contract_equipment')->withTimestamps();
    }

    /** Planned service dates, soonest first. */
    public function serviceDates(): HasMany
    {
        return $this->hasMany(ContractServiceDate::class)->orderBy('due_on');
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class);
    }

    // The visits_used column is seed-data only (never updated by the real
    // workflow), so visits are counted live from linked, Closed jobs
    // instead of trusting that stored counter.
    public function visitsUsed(): int
    {
        // Lists load this count for every contract in one query (see scopeWithVisitCounts).
        $loaded = $this->getAttribute('closed_work_orders_count');

        return $loaded !== null ? (int) $loaded : $this->workOrders()->where('status', 'Closed')->count();
    }

    public function scopeWithVisitCounts($query)
    {
        return $query->withCount(['workOrders as closed_work_orders_count' => fn ($q) => $q->where('status', 'Closed')]);
    }

    public function visitsRemaining(): int
    {
        return max(0, $this->visits_included - $this->visitsUsed());
    }

    // Earliest linked job that hasn't closed yet, or null if nothing is
    // currently scheduled under this contract.
    public function nextVisit(): ?WorkOrder
    {
        return $this->workOrders()
            ->where('status', '!=', 'Closed')
            ->orderBy('due_date')
            ->first();
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
            $pct >= (int) setting('stage_urgent_pct') => 'urgent',
            $pct >= (int) setting('stage_warn_pct') => 'warn',
            default => 'fresh',
        };
    }
}
