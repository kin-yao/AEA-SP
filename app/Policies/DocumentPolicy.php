<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;

class DocumentPolicy
{
    // Uses the confirmed type-visibility rule already sitting in
    // Document::scopeForRole(), a technician never sees an LPO, Finance
    // only sees LPOs, a customer sees reports/certs/vouchers/delivery
    // notes but never LPOs. This just wires that existing list into an
    // actual permission check, it wasn't enforcing anything on its own.
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

        return true; // Manager, Supervisor, Service Admin, Finance, already type-checked above
    }

    // Only Service Admin uploads a document directly (a scanned hard copy
    // against an existing record). Reports, vouchers, and delivery notes
    // aren't "created" through this, they come from Technician actions
    // elsewhere (submitReport() and friends), those aren't gated by this
    // policy at all, they're a different action on a different model.
    public function create(User $user): bool
    {
        return $user->hasRole('Service Admin');
    }

    public function update(User $user, Document $document): bool
    {
        return $user->hasRole('Service Admin');
    }

    public function delete(User $user, Document $document): bool
    {
        return false;
    }
}