<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'restricted' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    /** Plain words for each event type, shown on the Audit trail page. */
    public const EVENTS = [
        'login' => 'Signed in',
        'logout' => 'Signed out',
        'login_failed' => 'Failed sign-in',
        'login_blocked' => 'Locked account tried to sign in',
        'created' => 'Created',
        'updated' => 'Changed',
        'deleted' => 'Deleted',
        'account_locked' => 'Account locked',
        'account_unlocked' => 'Account unlocked',
        'password_reset' => 'Password reset',
        'password_changed' => 'Password changed',
    ];

    /** Actions by, or on, a Super Admin are kept out of everyone else's view. */
    public function scopeVisibleTo(Builder $query, User $viewer): Builder
    {
        return $viewer->hasRole('Super Admin') ? $query : $query->where('restricted', false);
    }
}