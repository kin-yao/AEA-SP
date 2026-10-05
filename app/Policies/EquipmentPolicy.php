<?php

namespace App\Policies;

use App\Models\Equipment;
use App\Models\User;

class EquipmentPolicy
{
    // Mirrors InventoryItemPolicy: everyone who touches a job site can see
    // the equipment register, but only Service Admin registers machines or
    // edits their records.
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['Manager', 'Supervisor', 'Service Admin', 'Technician']);
    }

    public function view(User $user, Equipment $equipment): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasRole('Service Admin');
    }

    public function update(User $user, Equipment $equipment): bool
    {
        return $user->hasRole('Service Admin');
    }

    public function delete(User $user, Equipment $equipment): bool
    {
        return false;
    }
}
