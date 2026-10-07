<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\Document;
use App\Models\Equipment;
use App\Models\Invoice;
use App\Models\InventoryItem;
use App\Models\Quotation;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Models\WorkOrder;

/**
 * One place that decides what each role sees in the sidebar and the phone menu.
 *
 * groups() returns a list of groups:
 *   ['key' => 'insights', 'title' => 'Insights', 'items' => [item, ...]]
 * and an item is:
 *   ['href' => '/x', 'pattern' => 'x*', 'icon' => 'house', 'label' => 'X', 'badge' => 0]
 * A group with title null is shown as plain top-level links (no heading).
 */
class Nav
{
    public static function groups(User $u): array
    {
        if ($u->hasAnyRole(['ICT', 'Super Admin'])) {
            return self::ict($u);
        }

        if ($u->hasRole('Technician')) {
            return self::technician();
        }

        if ($u->hasRole('Manager')) {
            return self::manager($u);
        }

        if ($u->hasRole('Customer')) {
            return self::customer($u);
        }

        return self::generic($u);
    }

    private static function item(string $href, string $pattern, string $icon, string $label, int $badge = 0): array
    {
        return compact('href', 'pattern', 'icon', 'label', 'badge');
    }

    private static function group(string $key, ?string $title, array $items): ?array
    {
        $items = array_values(array_filter($items));

        return $items ? ['key' => $key, 'title' => $title, 'items' => $items] : null;
    }

    /** LPOs sit under Sales for every role that is allowed to see them. */
    private static function lpoItem(User $u): ?array
    {
        $role = $u->roles->first()?->name ?? '';

        return in_array(Document::TYPE_LPO, Document::scopeForRole($role), true)
            ? self::item('/lpos', 'lpos*', 'file-earmark-text', 'LPOs')
            : null;
    }

    /** Documents, minus LPOs which have their own page. Hidden when nothing is left to show. */
    private static function documentsItem(User $u): ?array
    {
        $role = $u->roles->first()?->name ?? '';
        $types = array_diff(Document::scopeForRole($role), [Document::TYPE_LPO]);

        return $u->can('viewAny', Document::class) && $types
            ? self::item('/documents', 'documents*', 'folder', 'Documents')
            : null;
    }

    private static function ict(User $u): array
    {
        return array_values(array_filter([
            self::group('home', null, [
                self::item('/dashboard', 'dashboard*', 'house', 'Overview'),
                self::item('/security', 'security*', 'shield-lock', 'Security'),
                self::item('/users', 'users*', 'people', 'Users'),
                self::item('/branches', 'branches*', 'globe', 'Branches and Country'),
                self::item('/roles', 'roles*', 'diagram-3', 'Roles and Permissions'),
                self::item('/equipment-categories', 'equipment-categories*', 'tags', 'Equipment categories'),
                self::item('/settings', 'settings*', 'sliders', 'System settings'),
                self::item('/audit-trail', 'audit-trail*', 'clock-history', 'Audit trail'),
            ]),
        ]));
    }

    private static function customer(User $u): array
    {
        return array_values(array_filter([
            self::group('home', null, [
                self::item('/dashboard', 'dashboard*', 'house', 'Home'),
                self::item('/requests', 'requests*', 'envelope', 'My Requests'),
                self::item('/my-machines', 'my-machines*', 'nut', 'My Machines'),
                self::documentsItem($u),
                self::item('/invoices', 'invoices*', 'receipt', 'Invoices'),
                self::item('/contracts', 'contracts*', 'chevron-bar-contract', 'My contracts'),
                self::item('/customer-reports', 'customer-reports*', 'file-earmark-text', 'My Reports'),
            ]),
        ]));
    }

    private static function technician(): array
    {
        return array_values(array_filter([
            self::group('home', null, [
                self::item('/dashboard', 'dashboard', 'house', 'My day'),
            ]),
            self::group('work', 'My work', [
                self::item('/jobs', 'jobs*', 'tools', 'My jobs'),
                self::item('/schedule', 'schedule*', 'calendar-week', 'My schedule'),
                self::item('/service-report', 'service-report*', 'file-earmark-text', 'Service report'),
                self::item('/my-reports', 'my-reports*', 'journal-text', 'My reports'),
            ]),
            self::group('resources', 'Resources', [
                self::item('/my-equipment', 'my-equipment*', 'nut', 'Equipment'),
                self::item('/inventory', 'inventory*', 'box-seam', 'Parts'),
            ]),
        ]));
    }

    private static function manager(User $u): array
    {
        $pending = Quotation::where('status', 'Awaiting Manager')->count();

        return array_values(array_filter([
            self::group('home', null, [
                self::item('/dashboard', 'dashboard*', 'house', 'Overview'),
            ]),
            self::group('operations', 'Operations', [
                self::item('/jobs', 'jobs*', 'tools', 'All jobs'),
                self::item('/approvals', 'approvals*', 'check2-circle', 'Approvals', $pending),
            ]),
            self::group('sales', 'Sales', [
                self::lpoItem($u),
            ]),
            self::group('resources', 'Resources', [
                self::item('/customers', 'customers*', 'people', 'Customers'),
                self::documentsItem($u),
            ]),
            self::group('insights', 'Insights', [
                self::item('/performance', 'performance*', 'person-standing', 'Technician performance'),
                self::item('/reports', 'reports*', 'bar-chart-line', 'Reports'),
            ]),
            self::group('admin', 'Admin', [
                self::item('/users', 'users*', 'person-circle', 'User accounts'),
            ]),
        ]));
    }

    private static function generic(User $u): array
    {
        $supervisor = $u->hasRole('Supervisor');
        $serviceAdmin = $u->hasRole('Service Admin');
        $staff = $u->hasAnyRole(['Manager', 'Supervisor', 'Service Admin']);
        $supPending = $supervisor ? Quotation::where('status', 'Awaiting Supervisor')->count() : 0;

        $operations = [
            $u->can('viewAny', ServiceRequest::class) ? self::item('/requests', 'requests*', 'envelope', 'Requests') : null,
            $u->can('viewAny', WorkOrder::class) ? self::item('/jobs', 'jobs*', 'tools', 'Jobs') : null,
            $staff ? self::item('/dispatch', 'dispatch*', 'truck', 'Dispatch') : null,
            $staff ? self::item('/technicians', 'technicians*', 'person-standing', 'Technicians') : null,
            $supervisor ? self::item('/approvals', 'approvals*', 'check2-circle', 'Approvals', $supPending) : null,
        ];

        $sales = $supervisor ? [self::lpoItem($u)] : [
            $u->can('viewAny', Quotation::class) ? self::item('/quotations', 'quotations*', 'journal-text', 'Quotations') : null,
            self::lpoItem($u),
            $u->can('viewAny', Invoice::class) ? self::item('/invoices', 'invoices*', 'receipt', 'Invoices') : null,
            $u->hasRole('Finance') ? self::item('/receipts', 'receipts*', 'check2-circle', 'Receipts') : null,
        ];

        $resources = [
            $u->can('viewAny', Customer::class) ? self::item('/customers', 'customers*', 'people', 'Customers') : null,
            $u->can('viewAny', Contract::class) ? self::item('/contracts', 'contracts*', 'chevron-bar-contract', $u->hasRole('Customer') ? 'My contract' : 'Contracts') : null,
            $u->can('viewAny', Equipment::class) ? self::item('/equipment', 'equipment*', 'nut', 'Equipment') : null,
            $u->can('viewAny', InventoryItem::class) ? self::item('/inventory', 'inventory*', 'cart-check', 'Inventory') : null,
            self::documentsItem($u),
        ];

        $insights = [
            $supervisor ? self::item('/finance-watch', 'finance-watch*', 'receipt', 'Finance watch') : null,
            $supervisor ? self::item('/team-reports', 'team-reports*', 'bar-chart-line', 'Reports') : null,
            $serviceAdmin ? self::item('/service-reports', 'service-reports*', 'bar-chart-line', 'Reports') : null,
            $u->hasRole('Finance') ? self::item('/finance-reports', 'finance-reports*', 'bar-chart-line', 'Reports') : null,
        ];

        $admin = [
            $u->can('viewAny', User::class) ? self::item('/users', 'users*', 'people', 'Accounts') : null,
        ];

        return array_values(array_filter([
            self::group('home', null, [self::item('/dashboard', 'dashboard*', 'house', 'Overview')]),
            self::group('operations', 'Operations', $operations),
            self::group('sales', 'Sales', $sales),
            self::group('resources', 'Resources', $resources),
            self::group('insights', 'Insights', $insights),
            self::group('admin', 'Admin', $admin),
        ]));
    }
}
