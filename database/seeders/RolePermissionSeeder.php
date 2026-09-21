<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'requests.log', 'requests.assign', 'requests.decline',
            'quotations.create', 'quotations.approve.supervisor', 'quotations.approve.manager',
            'workorders.create', 'workorders.manage',
            'reports.submit', 'reports.check', 'reports.post-to-finance',
            'documents.upload',
            'contracts.create', 'contracts.terminate',
            'inventory.manage',
            'invoices.raise', 'invoices.issue',
            'payments.record',
            'customers.create', 'customers.edit',
            'users.manage', 'users.manage-technical',
            'reports.view-all',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        $matrix = [
            'Manager' => ['quotations.approve.manager', 'workorders.manage', 'reports.view-all', 'users.manage'],
            'Supervisor' => ['requests.assign', 'requests.decline', 'workorders.create', 'workorders.manage', 'quotations.approve.supervisor', 'reports.check', 'reports.view-all'],
            'Service Admin' => ['requests.log', 'requests.assign', 'requests.decline', 'quotations.create', 'workorders.create', 'workorders.manage', 'reports.post-to-finance', 'documents.upload', 'contracts.create', 'contracts.terminate', 'inventory.manage', 'customers.create', 'customers.edit', 'users.manage', 'reports.view-all'],
            'Technician' => ['reports.submit'],
            'ICT' => ['users.manage-technical'],
            'Super Admin' => [],
            'Finance' => ['invoices.raise', 'invoices.issue', 'payments.record', 'reports.view-all'],
            'Customer' => [],
        ];

        foreach ($matrix as $roleName => $rolePermissions) {
            $role = Role::firstOrCreate(['name' => $roleName]);
            $role->syncPermissions($rolePermissions);
        }
    }
}