<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;

class DocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->roles->isNotEmpty();
    }

    public function view(User $user, Document $document): bool
    {
        $role = $user->roles->first()?->name;

        if (! $role || ! in_array($document->type, Document::scopeForRole($role))) {
            return false;
        }

        if ($user->hasRole('Technician')) {
            return $document->workOrder?->assigned_technician_id === $user->id;
        }

        if ($user->hasRole('Customer')) {
            return $user->customer_id === $document->customer_id;
        }

        return true;
    }

    public function create(User $user): bool
    {
        return $user->hasRole('Service Admin');
    }

    public function update(User $user, Document $document): bool
    {
        return $user->hasRole('Service Admin');
    }

    // Confirmed role split: Supervisor reviews reports. Only meaningful on
    // a report type document that's actually sitting in Awaiting review,
    // not on certificates, LPOs, or anything already past that stage.
    public function review(User $user, Document $document): bool
    {
        return $user->hasRole('Supervisor')
            && $document->type === Document::TYPE_REPORT
            && $document->status === 'Awaiting review';
    }

    public function delete(User $user, Document $document): bool
    {
        return false;
    }
}