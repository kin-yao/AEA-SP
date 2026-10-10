<?php

namespace App\Policies;

use App\Models\Contract;
use App\Models\User;

class ContractPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['Manager', 'Supervisor', 'Service Admin', 'Customer']);
    }

    public function view(User $user, Contract $contract): bool
    {
        if ($user->hasRole('Customer')) {
            return $user->customer_id === $contract->customer_id;
        }

        return $user->hasAnyRole(['Manager', 'Supervisor', 'Service Admin']);
    }

    public function create(User $user): bool
    {
        return $user->hasRole('Service Admin');
    }

    // The rule the Contract model has been pointing at since before this
    // policy existed: once created, a contract cannot be edited, only
    // terminated or superseded by a fresh one. No role, not even Service
    // Admin, gets an exception here, that's the whole point of the rule.
    public function update(User $user, Contract $contract): bool
    {
        return false;
    }

    // Terminating is a distinct, deliberate action, not an edit, it changes
    // status to Terminated without touching the terms of what was agreed.
    public function terminate(User $user, Contract $contract): bool
    {
        return $user->hasRole('Service Admin') && $contract->status === 'Active';
    }

    // The terms are fixed, but the planned service dates are a schedule and can be moved.
    public function manageSchedule(User $user, Contract $contract): bool
    {
        return $user->hasRole('Service Admin') && $contract->status === 'Active';
    }

    public function delete(User $user, Contract $contract): bool
    {
        return false;
    }
}