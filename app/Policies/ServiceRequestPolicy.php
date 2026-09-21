<?php

namespace App\Policies;

use App\Models\ServiceRequest;
use App\Models\User;

class ServiceRequestPolicy
{
    // Manager, Supervisor and Service Admin all see the full list. Customer
    // scoping (a customer seeing only their own requests) isn't enforced
    // here yet, our User model has no link back to a Customer record, so
    // there's nothing to scope against. Worth fixing before a Customer
    // role can safely use this at all, flagging rather than faking it.
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['Manager', 'Supervisor', 'Service Admin']);
    }

    public function view(User $user, ServiceRequest $serviceRequest): bool
    {
        return $this->viewAny($user);
    }

    // The one rule we're most certain about: only Service Admin logs a new
    // request, confirmed directly, not a prototype guess.
    public function create(User $user): bool
    {
        return $user->hasRole('Service Admin');
    }

    // General edits (contact name, fault description, etc, not the
    // assign/decline actions below, those have their own rules).
    public function update(User $user, ServiceRequest $serviceRequest): bool
    {
        return $user->hasAnyRole(['Supervisor', 'Service Admin']);
    }

    // Assigning a technician is only meaningful while the request is still
    // Open, once it's Assigned, Quoted, Converted, or Declined, this isn't
    // the right action anymore, that's what the status check guards
    // against, not just the role.
    public function assign(User $user, ServiceRequest $serviceRequest): bool
    {
        return $user->hasAnyRole(['Supervisor', 'Service Admin'])
            && $serviceRequest->status === 'Open';
    }

    public function decline(User $user, ServiceRequest $serviceRequest): bool
    {
        return $user->hasAnyRole(['Supervisor', 'Service Admin'])
            && $serviceRequest->status === 'Open';
    }

    // A logged request is part of the intake record, it doesn't get erased,
    // only declined. No role gets a hard delete here.
    public function delete(User $user, ServiceRequest $serviceRequest): bool
    {
        return false;
    }
}