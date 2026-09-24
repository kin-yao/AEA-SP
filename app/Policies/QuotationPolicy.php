<?php

namespace App\Policies;

use App\Models\Quotation;
use App\Models\User;

class QuotationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['Manager', 'Supervisor', 'Service Admin', 'Finance', 'Customer']);
    }

    public function view(User $user, Quotation $quotation): bool
    {
        if ($user->hasRole('Customer')) {
            return $user->customer_id === $quotation->customer_id;
        }

        return $user->hasAnyRole(['Manager', 'Supervisor', 'Service Admin', 'Finance']);
    }

    // Confirmed rule: only Service Admin creates quotations.
    public function create(User $user): bool
    {
        return $user->hasRole('Service Admin');
    }

    // Line items, labour, and scope only editable while still awaiting a
    // decision, not after someone's approved, sent back, or the customer's
    // accepted it.
    public function update(User $user, Quotation $quotation): bool
    {
        return $user->hasRole('Service Admin')
            && str_starts_with($quotation->status, 'Awaiting');
    }

    // Whoever it's routed to approves it, checked against approval_threshold,
    // the actual routing decision routeApproval() already made.
    public function approve(User $user, Quotation $quotation): bool
    {
        return match ($quotation->approval_threshold) {
            'Supervisor' => $user->hasRole('Supervisor') && $quotation->status === 'Awaiting Supervisor',
            'Manager' => $user->hasRole('Manager') && $quotation->status === 'Awaiting Manager',
            default => false,
        };
    }

    public function sendBack(User $user, Quotation $quotation): bool
    {
        return $this->approve($user, $quotation);
    }

    // Logging the customer's LPO against an approved quotation, Service
    // Admin's job.
    public function logLpo(User $user, Quotation $quotation): bool
    {
        return $user->hasRole('Service Admin') && $quotation->status === 'Approved';
    }

    // The second real entry point into a job, only once the LPO's actually
    // on file and nothing's been generated from this quotation yet.
    public function convertToJob(User $user, Quotation $quotation): bool
    {
        return $user->hasRole('Service Admin')
            && $quotation->status === 'Accepted'
            && $quotation->converted_work_order_id === null;
    }

    public function delete(User $user, Quotation $quotation): bool
    {
        return false;
    }
}
