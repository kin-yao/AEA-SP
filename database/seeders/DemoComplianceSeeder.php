<?php

namespace Database\Seeders;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\TechnicianDocument;
use App\Models\User;
use Illuminate\Database\Seeder;

class DemoComplianceSeeder extends Seeder
{
    /**
     * Demo-only, same rule as DemoStaffSeeder/DemoJobLifecycleSeeder,
     * never part of the normal seeding chain. One contract and one
     * technician document per real expiry stage (fresh, warn, urgent,
     * critical), using real customers and technicians, so the new
     * dashboard widgets have something genuine to render on first look.
     */
    public function run(): void
    {
        $meds = Customer::where('reference', 'CUS-0001')->first();
        $breweries = Customer::where('reference', 'CUS-0002')->first();
        $bamburi = Customer::where('reference', 'CUS-0003')->first();
        $riftValley = Customer::where('reference', 'CUS-0004')->first();

        Contract::firstOrCreate(['reference' => 'CT-0031'], [
            'customer_id' => $meds->id,
            'type' => 'Full service',
            'starts_at' => now()->subMonths(2),
            'ends_at' => now()->addMonths(10),
            'visits_included' => 8,
            'visits_used' => 1,
        ]);

        Contract::firstOrCreate(['reference' => 'CT-0032'], [
            'customer_id' => $breweries->id,
            'type' => 'Planned maintenance',
            'starts_at' => now()->subMonths(7),
            'ends_at' => now()->addMonths(5),
            'visits_included' => 6,
            'visits_used' => 4,
        ]);

        Contract::firstOrCreate(['reference' => 'CT-0033'], [
            'customer_id' => $bamburi->id,
            'type' => 'Calibration',
            'starts_at' => now()->subMonths(10),
            'ends_at' => now()->addMonths(2),
            'visits_included' => 4,
            'visits_used' => 4,
        ]);

        Contract::firstOrCreate(['reference' => 'CT-0034'], [
            'customer_id' => $riftValley->id,
            'type' => 'Full service',
            'starts_at' => now()->subMonths(13),
            'ends_at' => now()->subMonth(),
            'visits_included' => 8,
            'visits_used' => 8,
            'status' => 'Expired',
        ]);

        $michael = User::where('email', 'm.maneno@aealimited.com')->first();
        $samuel = User::where('email', 's.njoroge@aealimited.com')->first();

        TechnicianDocument::firstOrCreate(
            ['technician_id' => $michael->id, 'document_type' => 'Medical cover / insurance'],
            ['issued_at' => now()->subMonths(2), 'validity_months' => 12]
        );

        TechnicianDocument::firstOrCreate(
            ['technician_id' => $michael->id, 'document_type' => 'Driving licence'],
            ['issued_at' => now()->subMonths(11), 'validity_months' => 12]
        );

        TechnicianDocument::firstOrCreate(
            ['technician_id' => $samuel->id, 'document_type' => 'Trade certification'],
            ['issued_at' => now()->subMonths(7), 'validity_months' => 12]
        );

        TechnicianDocument::firstOrCreate(
            ['technician_id' => $samuel->id, 'document_type' => 'Medical certificate'],
            ['issued_at' => now()->subMonths(14), 'validity_months' => 12]
        );
    }
}
