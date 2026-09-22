<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Document;
use App\Models\Equipment;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Quotation;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Database\Seeder;

class DemoJobLifecycleSeeder extends Seeder
{
    /**
     * Demo-only, same rule as DemoStaffSeeder, never part of the normal
     * chain. One complete thread from intake to payment, using the real
     * MEDS/Evans Mutiso/Michael Maneno scenario from the prototype, so
     * there's a whole story to look at, not isolated rows.
     *
     * Depends on CustomerSeeder and DemoStaffSeeder already having run.
     */
    public function run(): void
    {
        $meds = Customer::where('reference', 'CUS-0001')->first();
        $site = $meds->sites()->first();
        $hilda = User::where('email', 'h.murage@aealimited.com')->first();
        $michael = User::where('email', 'm.maneno@aealimited.com')->first();
        $timothy = User::where('email', 't.wanami@aealimited.com')->first();
        $winnie = User::where('email', 'w.achieng@aealimited.com')->first();

        $equipment = Equipment::create([
            'serial_number' => '1024-50305',
            'model' => 'Platform scale TE1005, 500 kg',
            'customer_id' => $meds->id,
            'customer_site_id' => $site->id,
            'category' => 'Platform scale',
            'cover' => 'Full service',
        ]);

        // Step 1: the request comes in, logged by Service Admin.
        $request = ServiceRequest::create([
            'reference' => 'SR-0001',
            'customer_id' => $meds->id,
            'customer_site_id' => $site->id,
            'equipment_id' => $equipment->id,
            'contact_name' => 'Evans Mutiso',
            'fault_description' => 'Scale drifts by 2 kg on load and the display flickers.',
            'cover' => 'Under contract',
            'priority' => 'High',
            'logged_by_id' => $hilda->id,
        ]);

        // Step 2: assigned to a technician, still not a job yet.
        $request->assignTechnician($michael, 'Service');

        // Step 3: converted to a real work order.
        $workOrder = WorkOrder::create([
            'reference' => 'WO-0001',
            'customer_id' => $meds->id,
            'customer_site_id' => $site->id,
            'equipment_id' => $equipment->id,
            'nature_of_visit' => $request->nature_of_visit,
            'assigned_technician_id' => $michael->id,
            'due_date' => now()->addDays(3),
            'source_service_request_id' => $request->id,
            'status' => 'Closed',
        ]);

        // Step 4: the quotation for the parts and labour, created by
        // Service Admin, routed correctly via routeApproval().
        $quotation = Quotation::create([
            'reference' => 'QT-0001',
            'customer_id' => $meds->id,
            'customer_site_id' => $site->id,
            'source_service_request_id' => $request->id,
            'scope' => 'Weighbridge load cell replacement',
            'labour_minor' => 3585000,
            'created_by' => $hilda->id,
        ]);
        $quotation->items()->create(['description' => 'Load cell, shear beam 20T', 'quantity' => 2, 'rate_minor' => 3500000]);
        $quotation->items()->create(['description' => 'Junction box, 4 cell', 'quantity' => 1, 'rate_minor' => 520000]);
        $quotation->load('items');
        $quotation->routeApproval();
        $quotation->save();
        $quotation->approve(); // Timothy, the Supervisor, approves it

        // Step 5: the service report, filed by Michael, full detail.
        $report = Document::create([
            'reference' => '26504',
            'type' => Document::TYPE_REPORT,
            'work_order_id' => $workOrder->id,
            'customer_id' => $meds->id,
            'status' => 'Released',
            'filed_by' => $michael->id,
        ]);
        $report->reportDetail()->create([
            'vehicle' => 'KBP 803V',
            'contact_name' => 'Evans Mutiso',
            'address' => $site->name,
            'nature_of_visit' => 'Service',
            'fault_description' => $request->fault_description,
            'cause' => 'Worn load cell, calibration out of tolerance.',
            'correction' => 'Replaced two load cells, recalibrated against class M1 weights.',
            'final_result' => 'Scale weighing within 0.1% tolerance.',
            'repairer_name' => 'Michael Maneno',
            'customer_signoff_name' => 'Evans Mutiso, Stores Manager',
        ])->parts()->createMany([
            ['item' => 'Load cell 500 kg', 'part_number' => 'H8C-500', 'quantity' => 2, 'source' => 'Vehicle stock'],
            ['item' => 'Junction box, 4 cell', 'part_number' => 'JB-4', 'quantity' => 1, 'source' => 'Nairobi store'],
        ]);

        // Step 6: invoiced by Finance, using the quotation's real total.
        $invoice = Invoice::create([
            'reference' => 'INV-0001',
            'customer_id' => $meds->id,
            'work_order_id' => $workOrder->id,
            'issued_at' => now()->subDays(5),
            'due_at' => now()->addDays(25),
            'amount_minor' => $quotation->totalMinor(),
            'status' => 'Unpaid',
            'raised_by' => $winnie->id,
        ]);

        // Step 7: paid in full, the one correct way, receipt and balance
        // update together.
        Payment::recordAgainst($invoice, 'RCP-0001', $invoice->amount_minor, 'Bank transfer', $winnie->id);
    }
}