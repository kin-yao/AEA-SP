<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\Quotation;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Database\Seeder;

class DemoInvoiceChainSeeder extends Seeder
{
    /**
     * Demo-only, never part of the normal seeding chain. Builds one
     * complete quotation-to-invoice thread using the real model methods
     * (routeApproval, approve, logLpo, convertToJob), the same path the
     * screens actually use, so the new itemized invoice flow has real
     * data to test against without walking the whole UI cycle by hand.
     */
    public function run(): void
    {
        $customer = Customer::where('reference', 'CUS-0001')->first();
        $supervisor = User::where('email', 't.wanami@aealimited.com')->first();
        $serviceAdmin = User::where('email', 'h.murage@aealimited.com')->first();
        $technician = User::where('email', 'm.maneno@aealimited.com')->first();

        $quotation = Quotation::create([
            'reference' => 'QT-'.str_pad((string) (Quotation::max('id') + 1), 4, '0', STR_PAD_LEFT),
            'customer_id' => $customer->id,
            'scope' => 'Load cell replacement and calibration',
            'created_by' => $serviceAdmin->id,
        ]);

        $quotation->items()->createMany([
            ['description' => 'Load cell, 500kg capacity', 'quantity' => 2, 'rate_minor' => 4500000],
            ['description' => 'Calibration weights, certified set', 'quantity' => 1, 'rate_minor' => 1200000],
        ]);
        $quotation->update(['labour_minor' => 2000000]);
        $quotation->refresh();

        $quotation->routeApproval();
        $quotation->save();
        $quotation->approve();
        $quotation->logLpo('MEDS-LPO-'.now()->format('ym').'-01', 'E-mail', $supervisor->id);

        $workOrder = $quotation->convertToJob(
            $technician->id,
            now()->addDays(2)->toDateString(),
            'WO-'.str_pad((string) (WorkOrder::max('id') + 1), 4, '0', STR_PAD_LEFT)
        );
        $workOrder->update(['status' => 'Closed']);

        $report = Document::create([
            'reference' => (string) (Document::max('id') + 1),
            'type' => 'rep',
            'customer_id' => $customer->id,
            'work_order_id' => $workOrder->id,
            'status' => 'Released',
            'filed_by' => $technician->id,
        ]);

        $report->reportDetail()->create([
            'contact_name' => 'Ruth Wambui',
            'address' => 'MEDS Nairobi, Industrial Area',
            'nature_of_visit' => 'Load cell replacement and calibration',
            'fault_description' => 'Scale reading drift beyond tolerance on load cells 3 and 4',
            'cause' => 'Load cell wear from continuous heavy use',
            'correction' => 'Replaced both load cells, recalibrated with certified weight set',
            'final_result' => 'Scale reading within tolerance, calibration certificate issued',
            'repairer_name' => $technician->name,
            'customer_signoff_name' => 'Ruth Wambui',
            'incident_type' => 'None',
        ]);

        // Real items, summed the same way the actual invoice creation
        // screen sums them, so amount_minor genuinely matches what's
        // itemized below it rather than pulling in the quotation's
        // VAT-inclusive total and creating a mismatch.
        $lineItems = [
            ['description' => 'Load cell, 500kg capacity', 'quantity' => 2, 'rate_minor' => 4500000],
            ['description' => 'Calibration weights, certified set', 'quantity' => 1, 'rate_minor' => 1200000],
            ['description' => 'Labour', 'quantity' => 1, 'rate_minor' => 2000000],
        ];
        $amountMinor = array_sum(array_map(fn ($i) => $i['quantity'] * $i['rate_minor'], $lineItems));

        $invoice = Invoice::create([
            'reference' => 'INV-'.str_pad((string) (Invoice::max('id') + 1), 4, '0', STR_PAD_LEFT),
            'customer_id' => $customer->id,
            'work_order_id' => $workOrder->id,
            'issued_at' => now(),
            'due_at' => now()->addDays(30),
            'amount_minor' => $amountMinor,
            'raised_by' => $serviceAdmin->id,
        ]);

        $invoice->items()->createMany($lineItems);
    }
}
