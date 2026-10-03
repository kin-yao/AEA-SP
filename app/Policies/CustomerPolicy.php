<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;

class CustomerPolicy
{
    // Customer accounts see their own company's data through My contract,
    // Invoices, etc, not this master list, that's why Customer is left
    // off here same as Contract/Invoice's viewAny for staff-only screens.
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['Manager', 'Supervisor', 'Service Admin', 'Finance']);
    }

    public function view(User $user, Customer $customer): bool
    {
        return $this->viewAny($user);
    }

    // Most customers arrive through self-registration (routes/web.php's
    // register flow). This covers the walk-in/phone case: Service Admin
    // raising a record for a company before anyone there has signed up.
    public function create(User $user): bool
    {
        return $user->hasRole('Service Admin');
    }

    // Contact and reference details only, not has_active_contract or
    // balance_minor, those are derived from real contracts/invoices
    // elsewhere, not something to hand-edit on this form.
    public function update(User $user, Customer $customer): bool
    {
        return $user->hasRole('Service Admin');
    }

    public function delete(User $user, Customer $customer): bool
    {
        return false;
    }
}
