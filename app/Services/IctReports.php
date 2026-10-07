<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Country;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Role;

/** Numbers for the ICT Overview and Security pages. Super Admin accounts are never counted or listed. */
class IctReports
{
    /** AEA works in Nairobi time, the database stores UTC. */
    public const TZ = 'Africa/Nairobi';

    /** Midnight in Nairobi, expressed in the database's own time zone, $daysAgo days back. */
    public static function dayStart(int $daysAgo = 0): Carbon
    {
        return now(\App\Support\Settings::timezone())->subDays($daysAgo)->startOfDay()->setTimezone(config('app.timezone'));
    }

    public const ROLE_COLORS = [
        'Manager' => '#8f1d1d',
        'Supervisor' => '#e0ac2e',
        'Service Admin' => '#1f2937',
        'Technician' => '#15803d',
        'Finance' => '#475569',
        'ICT' => '#0f766e',
        'Customer' => '#9ca3af',
    ];

    public static function people(): \Illuminate\Database\Eloquent\Builder
    {
        return User::query()->whereDoesntHave('roles', fn ($q) => $q->where('name', 'Super Admin'));
    }

    public static function roleMix(): array
    {
        $out = [];
        foreach (self::ROLE_COLORS as $role => $color) {
            $n = self::people()->role($role)->count();
            if ($n > 0) {
                $out[] = ['label' => $role, 'value' => $n, 'color' => $color];
            }
        }

        return $out;
    }

    /** One row per day, oldest first, for the last $days days. */
    public static function perDay(string $event, int $days, User $viewer, string $color): array
    {
        $from = self::dayStart($days - 1);
        $rows = AuditLog::visibleTo($viewer)->where('event', $event)->where('created_at', '>=', $from)->get(['created_at']);

        $out = [];
        $first = now(\App\Support\Settings::timezone())->subDays($days - 1)->startOfDay();
        for ($d = $first->copy(); $d->lte(now(\App\Support\Settings::timezone())); $d->addDay()) {
            $n = $rows->filter(fn ($r) => $r->created_at->copy()->setTimezone(\App\Support\Settings::timezone())->isSameDay($d))->count();
            $out[] = ['label' => $d->format('D j'), 'value' => $n, 'valueLabel' => (string) $n, 'color' => $color];
        }

        return $out;
    }

    public static function counts(User $viewer): array
    {
        $people = fn () => self::people();

        return [
            'users' => $people()->count(),
            'active' => $people()->where('status', 'Active')->count(),
            'locked' => $people()->where('status', '!=', 'Active')->count(),
            'tempPassword' => $people()->where('must_change_password', true)->count(),
            'unverified' => $people()->whereNull('email_verified_at')->count(),
            'failedToday' => AuditLog::visibleTo($viewer)->where('event', 'login_failed')->where('created_at', '>=', self::dayStart())->count(),
            'failedWeek' => AuditLog::visibleTo($viewer)->where('event', 'login_failed')->where('created_at', '>=', self::dayStart(6))->count(),
            'signInsToday' => AuditLog::visibleTo($viewer)->where('event', 'login')->where('created_at', '>=', self::dayStart())->count(),
            'branches' => Branch::count(),
            'countries' => Country::count(),
            'categories' => EquipmentCategory::count(),
            'machines' => Equipment::count(),
        ];
    }

    /** Failed sign-ins in the last 24 hours, grouped by the email that was tried. */
    public static function failedByEmail(User $viewer): Collection
    {
        return AuditLog::visibleTo($viewer)
            ->where('event', 'login_failed')
            ->where('created_at', '>=', now()->subDay())
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('email')
            ->map(fn ($g) => [
                'email' => $g->first()->email ?: 'unknown',
                'count' => $g->count(),
                'last' => $g->first()->created_at,
                'ip' => $g->first()->ip,
                'known' => $g->first()->email && User::where('email', $g->first()->email)->exists(),
            ])
            ->sortByDesc('count')
            ->values();
    }

    public static function recentActivity(User $viewer, int $n = 8): Collection
    {
        return AuditLog::visibleTo($viewer)->whereNotIn('event', ['login', 'logout'])->orderByDesc('id')->limit($n)->get();
    }

    /** Permissions held by each role, for the Roles page. Super Admin is left out on purpose. */
    public static function roleMatrix(): array
    {
        $roles = Role::where('name', '!=', 'Super Admin')->with('permissions')->get()->sortBy(fn ($r) => array_search($r->name, array_keys(self::ROLE_COLORS)));

        return [$roles->values(), \Spatie\Permission\Models\Permission::orderBy('name')->pluck('name')->all()];
    }
}
