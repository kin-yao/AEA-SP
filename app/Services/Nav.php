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
        if ($u->hasRole('Technician')) {
            return self::technician();
        }

        if ($u->hasRole('Manager')) {
            return self::manager();
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

    private static function manager(): array
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
            self::group('resources', 'Resources', [
                self::item('/customers', 'customers*', 'people', 'Customers'),
                self::item('/documents', 'documents*', 'folder', 'Documents'),
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

        $sales = $supervisor ? [] : [
            $u->can('viewAny', Quotation::class) ? self::item('/quotations', 'quotations*', 'journal-text', 'Quotations') : null,
            $u->can('viewAny', Invoice::class) ? self::item('/invoices', 'invoices*', 'receipt', 'Invoices') : null,
        ];

        $resources = [
            $u->can('viewAny', Customer::class) ? self::item('/customers', 'customers*', 'people', 'Customers') : null,
            $u->can('viewAny', Contract::class) ? self::item('/contracts', 'contracts*', 'chevron-bar-contract', $u->hasRole('Customer') ? 'My contract' : 'Contracts') : null,
            $u->can('viewAny', Equipment::class) ? self::item('/equipment', 'equipment*', 'nut', 'Equipment') : null,
            $u->can('viewAny', InventoryItem::class) ? self::item('/inventory', 'inventory*', 'cart-check', 'Inventory') : null,
            $u->can('viewAny', Document::class) ? self::item('/documents', 'documents*', 'folder', 'Documents') : null,
        ];

        $insights = [
            $supervisor ? self::item('/finance-watch', 'finance-watch*', 'receipt', 'Finance watch') : null,
            $supervisor ? self::item('/team-reports', 'team-reports*', 'bar-chart-line', 'Reports') : null,
            $serviceAdmin ? self::item('/service-reports', 'service-reports*', 'bar-chart-line', 'Reports') : null,
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
