<?php

namespace App\Policies;

use App\Models\InventoryItem;
use App\Models\User;

class InventoryItemPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['Manager', 'Supervisor', 'Service Admin', 'Technician']);
    }

    public function view(User $user, InventoryItem $inventoryItem): bool
    {
        return $this->viewAny($user);
    }

    // Confirmed from the prototype's role split: Service Admin owns
    // inventory. Manager and Supervisor can see it (reflected in
    // viewAny/view above), but managing stock levels and recording
    // movements isn't their job.
    public function create(User $user): bool
    {
        return $user->hasRole('Service Admin');
    }

    public function update(User $user, InventoryItem $inventoryItem): bool
    {
        return $user->hasRole('Service Admin');
    }

    // Recording a movement, issue, stock in, adjustment. A technician can
    // record an issue against their own job (parts they used), that's the
    // one real exception to "Service Admin owns inventory", everyone else
    // outside those two roles has no reason to touch this at all.
    public function recordMovement(User $user, InventoryItem $inventoryItem): bool
    {
        return $user->hasAnyRole(['Service Admin', 'Technician']);
    }

    public function delete(User $user, InventoryItem $inventoryItem): bool
    {
        return false;
    }
}