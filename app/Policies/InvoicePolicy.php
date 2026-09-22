<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;

class InvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['Manager', 'Supervisor', 'Service Admin', 'Finance', 'Customer']);
    }

    public function view(User $user, Invoice $invoice): bool
    {
        if ($user->hasRole('Customer')) {
            return $user->customer_id === $invoice->customer_id;
        }

        return $user->hasAnyRole(['Manager', 'Supervisor', 'Service Admin', 'Finance']);
    }

    // Confirmed rule: Finance raises invoices. Service Admin doesn't, their
    // role ends at posting a checked report to Finance, this is the
    // handoff point.
    public function create(User $user): bool
    {
        return $user->hasRole('Finance');
    }

    // Line items, amount, due date, only editable while still a Draft, not
    // once it's been issued to the customer. Judgment call, not from the
    // prototype, same reasoning as the quotation lock: an issued invoice
    // is a real document someone's expecting to pay against, rewriting it
    // silently underneath them is a real integrity hole.
    public function update(User $user, Invoice $invoice): bool
    {
        return $user->hasRole('Finance') && $invoice->status === 'Draft';
    }

    // Moving Draft to Unpaid, sending it out. Only meaningful once, an
    // invoice that's already issued doesn't get issued again.
    public function issue(User $user, Invoice $invoice): bool
    {
        return $user->hasRole('Finance') && $invoice->status === 'Draft';
    }

    // Recording a payment against it, this is what Payment::recordAgainst()
    // is gated behind. Only makes sense once it's actually out and unpaid
    // or partially paid, not while still a Draft, not once fully Paid.
    public function recordPayment(User $user, Invoice $invoice): bool
    {
        return $user->hasRole('Finance') && in_array($invoice->status, ['Unpaid', 'Part paid']);
    }

    // Financial records don't get deleted, only voided through the normal
    // status lifecycle if that's ever needed, not built yet.
    public function delete(User $user, Invoice $invoice): bool
    {
        return false;
    }
}