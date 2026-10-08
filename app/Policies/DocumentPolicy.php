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
            return $user->customer_id === $document->customer_id
                && ($document->type !== Document::TYPE_REPORT || $document->status === 'Released');
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

    // Supervisor reviews a report. Only meaningful while it's actually
    // sitting in Awaiting review.
    public function review(User $user, Document $document): bool
    {
        return $user->hasRole('Supervisor')
            && $document->type === Document::TYPE_REPORT
            && $document->status === 'Awaiting review';
    }

    // Service Admin releases a checked report to Finance. Only meaningful
    // once Supervisor has actually reviewed it, this is the next real
    // handoff in the chain, not something Service Admin can skip to.
    public function post(User $user, Document $document): bool
    {
        return $user->hasRole('Service Admin')
            && $document->type === Document::TYPE_REPORT
            && $document->status === 'Checked, ready to post';
    }

    // The LPO is the binding agreement, so its prices can be corrected after a
    // negotiation, by whoever manages the work, until an invoice has been raised.
    public function editLpo(User $user, Document $document): bool
    {
        if ($document->type !== Document::TYPE_LPO || ! $user->hasAnyRole(['Service Admin', 'Supervisor', 'Manager'])) {
            return false;
        }

        $job = $document->lpoDetail?->quotation?->workOrder;

        return ! ($job && $job->invoices()->exists());
    }

    public function delete(User $user, Document $document): bool
    {
        return false;
    }
}
