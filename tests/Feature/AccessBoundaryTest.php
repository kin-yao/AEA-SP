<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\Document;
use App\Models\Equipment;
use App\Models\Invoice;
use App\Models\Quotation;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Models\WorkOrder;
use Tests\TestCase;

/** People must only reach their own records, even by typing another record's address. Run against a seeded database. */
class AccessBoundaryTest extends TestCase
{
    public function test_a_customer_cannot_open_another_customers_records(): void
    {
        $me = User::where('email', 'c.otieno@meds.co.ke')->firstOrFail();
        $this->actingAs($me);
        $other = fn ($model) => $model::where('customer_id', '!=', $me->customer_id)->first();
        $urls = array_filter([
            '/invoices/'.$other(Invoice::class)?->id,
            '/quotations/'.$other(Quotation::class)?->id,
            '/jobs/'.$other(WorkOrder::class)?->id,
            '/requests/'.$other(ServiceRequest::class)?->id,
            '/contracts/'.$other(Contract::class)?->id,
            '/documents/'.$other(Document::class)?->id,
            '/equipment/'.$other(Equipment::class)?->id,
            '/my-equipment/'.$other(Equipment::class)?->id,
            '/customers/'.Customer::where('id', '!=', $me->customer_id)->value('id'),
            '/users/'.User::where('id', '!=', $me->id)->value('id'),
            '/audit-trail', '/settings', '/users', '/inventory', '/finance-reports',
        ], fn ($u) => ! str_ends_with($u, '/'));
        $leaks = [];
        foreach ($urls as $u) {
            $s = $this->get($u)->getStatusCode();
            if ($s === 200) {
                $leaks[] = $u;
            }
        }
        $this->assertSame([], $leaks, 'a customer could open: '.implode(', ', $leaks));
    }

    public function test_a_technician_cannot_open_other_technicians_jobs_or_staff_pages(): void
    {
        $me = User::where('email', 'b.otieno@aealimited.com')->firstOrFail();
        $this->actingAs($me);
        $leaks = [];
        foreach (array_filter([
            '/jobs/'.WorkOrder::where('assigned_technician_id', '!=', $me->id)->value('id'),
            '/jobs/'.WorkOrder::where('assigned_technician_id', '!=', $me->id)->value('id').'/report',
            '/users/'.User::where('id', '!=', $me->id)->value('id'),
            '/settings', '/audit-trail', '/finance-reports', '/users/create', '/customers/create', '/invoices', '/quotations', '/approvals',
        ]) as $u) {
            if ($this->get($u)->getStatusCode() === 200) {
                $leaks[] = $u;
            }
        }
        $this->assertSame([], $leaks, 'a technician could open: '.implode(', ', $leaks));
    }
}
