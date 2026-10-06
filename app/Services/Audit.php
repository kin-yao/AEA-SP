<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * One place that writes the audit trail. Models are watched by
 * App\Observers\AuditObserver, sign-ins by the listeners in
 * AppServiceProvider, and one-off actions call record() directly.
 */
class Audit
{
    public static function record(string $event, string $label, ?Model $subject = null, ?array $changes = null, ?User $actor = null, ?string $email = null): void
    {
        $actor ??= Auth::user();

        // Seeders, queued jobs and the console have nobody signed in. Leave
        // those out, they would only bury the real activity.
        if (! $actor && ! $email && ! in_array($event, ['login_failed', 'logout'], true)) {
            return;
        }

        $restricted = (bool) $actor?->hasRole('Super Admin')
            || ($subject instanceof User && $subject->hasRole('Super Admin'));

        if ($email && User::where('email', $email)->role('Super Admin')->exists()) {
            $restricted = true;
        }

        AuditLog::create([
            'user_id' => $actor?->id,
            'user_name' => $actor?->name,
            'email' => $email ?? ($event === 'login' || $event === 'logout' || $event === 'login_blocked' ? $actor?->email : null),
            'event' => $event,
            'auditable_type' => $subject ? class_basename($subject) : null,
            'auditable_id' => $subject?->getKey(),
            'label' => mb_substr($label, 0, 500),
            'changes' => $changes ?: null,
            'ip' => request()?->ip(),
            'restricted' => $restricted,
            'created_at' => now(),
        ]);
    }

    /** A short name for a record: its reference, name or serial number. */
    public static function describe(Model $m): string
    {
        $kind = trim(preg_replace('/(?<!^)[A-Z]/', ' $0', class_basename($m)));
        $name = $m->reference ?? $m->name ?? $m->serial_number ?? ('#'.$m->getKey());

        return strtolower($kind).' '.$name;
    }
}