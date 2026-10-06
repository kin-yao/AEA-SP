<?php

namespace App\Observers;

use App\Models\User;
use App\Services\Audit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class AuditObserver
{
    /** Fields that change all the time or must never be copied into the trail. */
    private const IGNORE = ['updated_at', 'created_at', 'remember_token', 'deleted_at', 'last_seen_at'];

    public function created(Model $m): void
    {
        Audit::record('created', 'Created '.Audit::describe($m), $m);
    }

    public function updated(Model $m): void
    {
        $dirty = collect($m->getChanges())->except(self::IGNORE);
        if ($dirty->isEmpty()) {
            return;
        }

        if ($m instanceof User) {
            $name = $m->name;

            if ($dirty->has('status')) {
                $locked = $m->status !== 'Active';
                Audit::record($locked ? 'account_locked' : 'account_unlocked', ($locked ? 'Locked' : 'Unlocked').' the account of '.$name, $m);
                $dirty = $dirty->except('status');
            }

            if ($dirty->has('password')) {
                $self = Auth::id() === $m->id;
                Audit::record($self ? 'password_changed' : 'password_reset', ($self ? 'Changed own password' : 'Reset the password of '.$name), $m);
                $dirty = $dirty->except(['password', 'must_change_password']);
            }

            if ($dirty->isEmpty()) {
                return;
            }
        }

        $changes = [];
        foreach ($dirty as $field => $to) {
            $from = $m->getOriginal($field);
            $changes[$field] = [$this->show($from), $this->show($to)];
        }

        Audit::record('updated', 'Changed '.Audit::describe($m), $m, $changes);
    }

    public function deleted(Model $m): void
    {
        Audit::record('deleted', 'Deleted '.Audit::describe($m), $m);
    }

    private function show(mixed $v): string
    {
        if ($v === null) {
            return '';
        }
        if (is_bool($v)) {
            return $v ? 'yes' : 'no';
        }
        if ($v instanceof \DateTimeInterface) {
            return $v->format('Y-m-d H:i');
        }

        return mb_substr(is_scalar($v) ? (string) $v : json_encode($v), 0, 120);
    }
}