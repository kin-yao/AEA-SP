<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TechnicianDocument extends Model
{
    protected $fillable = ['technician_id', 'document_type', 'issued_at', 'validity_months', 'file_path'];

    protected $casts = ['issued_at' => 'date'];

    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technician_id');
    }

    public function expiresAt(): \Carbon\Carbon
    {
        return $this->issued_at->copy()->addDays((int) round($this->validity_months * 30.4));
    }

    public function percentUsed(): int
    {
        $totalDays = $this->validity_months * 30.4;
        $elapsedDays = $this->issued_at->diffInDays(now());

        return max(0, (int) round($elapsedDays / $totalDays * 100));
    }

    public function percentUsedCapped(): int
    {
        return min($this->percentUsed(), 100);
    }

    // Same four-stage system as Contract::expiryStage(), same thresholds,
    // same meaning, one shared component can render either.
    public function expiryStage(): string
    {
        $pct = $this->percentUsed();

        return match (true) {
            $pct >= 100 => 'critical',
            $pct >= 75 => 'urgent',
            $pct >= 50 => 'warn',
            default => 'fresh',
        };
    }

    public function dueLabel(): string
    {
        $diffDays = (int) now()->diffInDays($this->expiresAt(), false);

        if ($diffDays < 0) {
            return 'Expired '.abs($diffDays).' days ago';
        }

        return $diffDays < 60 ? "In {$diffDays} days" : round($diffDays / 30).' months';
    }
}
