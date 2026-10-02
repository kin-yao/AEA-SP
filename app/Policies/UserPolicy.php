<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    // Manager and Service Admin hold the broader 'users.manage'
    // permission, ICT holds the narrower 'users.manage-technical' one,
    // Super Admin needs in only to create and oversee the ICT accounts
    // it's responsible for. Super Admin accounts themselves are never
    // shown or actioned by anyone, kept out of this screen entirely.
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['Manager', 'Service Admin', 'ICT', 'Super Admin']);
    }

    public function view(User $user, User $target): bool
    {
        return $this->viewAny($user) && ! $target->hasRole('Super Admin');
    }

    // Account creation follows the hierarchy fixed in UserSeeder's
    // comments: Super Admin creates the ICT account, ICT creates
    // everyone else. Manager and Service Admin can see and manage
    // accounts but never create them.
    public function create(User $user): bool
    {
        return $user->hasAnyRole(['Super Admin', 'ICT']);
    }

    // Locking, unlocking, and resetting a password are the only other
    // account actions this policy covers.
    public function manage(User $user, User $target): bool
    {
        return $this->viewAny($user)
            && ! $target->hasRole('Super Admin')
            && $user->id !== $target->id;
    }

    public function delete(User $user, User $target): bool
    {
        return false;
    }
}
