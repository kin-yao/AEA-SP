<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerSite;
use Illuminate\Database\Seeder;

class CustomerSeeder extends Seeder
{
    /**
     * The 8 real customers from the prototype. Only MEDS and Bamburi
     * Cement have confirmed KRA PIN / P.O. Box details from our earlier
     * work, marked below. The other six use null for those fields rather
     * than invented numbers, real values to be filled in once AEA
     * provides their actual customer register.
     */
    public function run(): void
    {
        $nairobi = Branch::where('name', 'Nairobi')->first();
        $mombasa = Branch::where('name', 'Mombasa')->first();
        $kisumu = Branch::where('name', 'Kisumu')->first();
        $uganda = Branch::where('name', 'Uganda branch')->first();
        $tanzania = Branch::where('name', 'Tanzania branch')->first();
        $rwanda = Branch::where('name', 'Rwanda branch')->first();

        $customers = [
            [
                'reference' => 'CUS-0001',
                'name' => 'MEDS',
                'branch_id' => $nairobi->id,
                'kra_pin' => 'P051123456A', // confirmed, from earlier prototype work
                'po_box' => 'Box 49442, Nairobi', // confirmed
                'main_contact_name' => 'Evans Mutiso',
                'has_active_contract' => true,
                'sites' => [
                    ['name' => 'Nairobi warehouse, Off Mombasa Road', 'contact_name' => 'Evans Mutiso', 'lat' => -1.3197, 'lng' => 36.8510],
                ],
            ],
            [
                'reference' => 'CUS-0002',
                'name' => 'Kenya Breweries Ltd',
                'branch_id' => $nairobi->id,
                'kra_pin' => null, // not yet confirmed
                'po_box' => null,
                'main_contact_name' => 'John Waweru',
                'has_active_contract' => true,
                'sites' => [],
            ],
            [
                'reference' => 'CUS-0003',
                'name' => 'Bamburi Cement',
                'branch_id' => $mombasa->id,
                'kra_pin' => 'P051144556D', // confirmed, from earlier prototype work
                'po_box' => null,
                'main_contact_name' => 'Salim Juma',
                'has_active_contract' => false,
                'sites' => [],
            ],
            [
                'reference' => 'CUS-0004',
                'name' => 'Rift Valley Bottlers',
                'branch_id' => $nairobi->id,
                'kra_pin' => null,
                'po_box' => null,
                'main_contact_name' => null,
                'has_active_contract' => true,
                'sites' => [],
            ],
            [
                'reference' => 'CUS-0005',
                'name' => 'Kisumu Sugar Millers',
                'branch_id' => $kisumu->id,
                'kra_pin' => null,
                'po_box' => null,
                'main_contact_name' => null,
                'has_active_contract' => true,
                'sites' => [],
            ],
            [
                'reference' => 'CUS-0006',
                'name' => 'Kampala Dairy Ltd',
                'branch_id' => $uganda->id,
                'kra_pin' => null,
                'po_box' => null,
                'main_contact_name' => 'Brenda Nakato',
                'has_active_contract' => true,
                'sites' => [],
            ],
            [
                'reference' => 'CUS-0007',
                'name' => 'Dar Pharma Ltd',
                'branch_id' => $tanzania->id,
                'kra_pin' => null,
                'po_box' => null,
                'main_contact_name' => 'Neema Kileo',
                'has_active_contract' => true,
                'sites' => [],
            ],
            [
                'reference' => 'CUS-0008',
                'name' => 'Kigali Grain Millers',
                'branch_id' => $rwanda->id,
                'kra_pin' => null,
                'po_box' => null,
                'main_contact_name' => 'Eric Habimana',
                'has_active_contract' => false,
                'sites' => [],
            ],
        ];

        foreach ($customers as $data) {
            $sites = $data['sites'];
            unset($data['sites']);

            $customer = Customer::create($data);

            foreach ($sites as $site) {
                $customer->sites()->create($site);
            }
        }
    }
}