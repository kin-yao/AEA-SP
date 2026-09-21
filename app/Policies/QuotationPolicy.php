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

    // Judgment call, not from the prototype: a quotation's line items,
    // labour, and scope should only be editable while it's still awaiting
    // a decision. Once someone's approved it, sent it back, or the
    // customer's accepted it, rewriting the numbers underneath that
    // decision is a real integrity hole, not something the prototype had
    // to think about since it never had real state to protect.
    public function update(User $user, Quotation $quotation): bool
    {
        return $user->hasRole('Service Admin')
            && str_starts_with($quotation->status, 'Awaiting');
    }

    // Whoever it's routed to approves it, Supervisor for the Supervisor
    // threshold, Manager for the Manager threshold, checked against
    // approval_threshold, not against the total directly, that column is
    // the actual routing decision Quotation::routeApproval() already made.
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

    // Logging an LPO against an approved quotation, Service Admin's job.
    public function logLpo(User $user, Quotation $quotation): bool
    {
        return $user->hasRole('Service Admin') && $quotation->status === 'Approved';
    }

    public function delete(User $user, Quotation $quotation): bool
    {
        return false;
    }
}