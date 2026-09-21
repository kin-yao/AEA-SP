<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WorkOrder;

class WorkOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['Manager', 'Supervisor', 'Service Admin', 'Technician', 'Customer']);
    }

    // Staff see everything. A technician only sees jobs assigned to them,
    // per what you just confirmed, they don't get visibility into other
    // technicians' work through this. A customer only sees their own.
    public function view(User $user, WorkOrder $workOrder): bool
    {
        if ($user->hasRole('Technician')) {
            return $user->id === $workOrder->assigned_technician_id;
        }

        if ($user->hasRole('Customer')) {
            return $user->customer_id === $workOrder->customer_id;
        }

        return $user->hasAnyRole(['Manager', 'Supervisor', 'Service Admin']);
    }

    // A work order comes from an assigned request or an accepted
    // quotation, both staff actions, never a technician deciding to start
    // one.
    public function create(User $user): bool
    {
        return $user->hasAnyRole(['Supervisor', 'Service Admin']);
    }

    // Full edits, reassigning the technician, changing due date, priority,
    // equipment, etc, staff only. Not what a technician does while working
    // a job, that's updateStatus() below.
    public function update(User $user, WorkOrder $workOrder): bool
    {
        return $user->hasAnyRole(['Supervisor', 'Service Admin']);
    }

    // The narrow thing a technician can actually do: move their own
    // assigned job through its status (Assigned -> On site -> Awaiting
    // review), nothing else on the record. They don't decide who's
    // assigned, they only act once they are.
    public function updateStatus(User $user, WorkOrder $workOrder): bool
    {
        if ($user->hasRole('Technician')) {
            return $user->id === $workOrder->assigned_technician_id;
        }

        return $user->hasAnyRole(['Supervisor', 'Service Admin']);
    }

    // A job doesn't get erased once real work, parts, or documents may be
    // attached to it, only closed through its normal status lifecycle.
    public function delete(User $user, WorkOrder $workOrder): bool
    {
        return false;
    }
}