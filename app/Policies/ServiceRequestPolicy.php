<?php

namespace App\Policies;

use App\Models\ServiceRequest;
use App\Models\User;

class ServiceRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['Manager', 'Supervisor', 'Service Admin', 'Customer']);
    }

    // Staff see everything. A Customer only sees requests belonging to
    // their own customer_id, now that the link actually exists.
    public function view(User $user, ServiceRequest $serviceRequest): bool
    {
        if ($user->hasRole('Customer')) {
            return $user->customer_id === $serviceRequest->customer_id;
        }

        return $user->hasAnyRole(['Manager', 'Supervisor', 'Service Admin']);
    }

    // Revisited: customers may now log their own requests through their
    // self-service account, and Service Admin can still log one on behalf
    // of a customer who calls in instead of using the portal.
    public function create(User $user): bool
    {
        return $user->hasAnyRole(['Service Admin', 'Customer']);
    }

    public function update(User $user, ServiceRequest $serviceRequest): bool
    {
        return $user->hasAnyRole(['Supervisor', 'Service Admin']);
    }

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

    public function delete(User $user, ServiceRequest $serviceRequest): bool
    {
        return false;
    }
}