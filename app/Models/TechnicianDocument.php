<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TechnicianDocument extends Model
{
    protected $fillable = [
        'technician_id',
        'document_type',
        'issued_at',
        'validity_months',
        'file_path',
    ];

    protected $casts = [
        'issued_at' => 'date',
    ];

    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technician_id');
    }

    // Same maths as Contract::percentOfTermUsed(): elapsed days over total
    // validity days (validity_months * 30.4), as a whole percentage,
    // uncapped above 100 so "112% used" still reads correctly for
    // something already expired.
    public function percentUsed(): int
    {
        $totalDays = $this->validity_months * 30.4;
        $elapsedDays = $this->issued_at->diffInDays(now());

        return max(0, (int) round($elapsedDays / $totalDays * 100));
    }

    public function status(): string
    {
        $pct = $this->percentUsed();

        return $pct >= 90 ? 'Overdue' : ($pct >= 75 ? 'Due soon' : 'Active');
    }

    public function dueLabel(): string
    {
        $expiresAt = $this->issued_at->copy()->addDays((int) round($this->validity_months * 30.4));
        $diffDays = (int) now()->diffInDays($expiresAt, false);

        if ($diffDays < 0) {
            return 'Expired '.abs($diffDays).' days ago';
        }

        return $diffDays < 60 ? "In {$diffDays} days" : round($diffDays / 30).' months';
    }
}