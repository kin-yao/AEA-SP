<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Bulk test data for load and edge-case testing. Never part of the normal chain.
 *
 *   php artisan db:seed --class=StressSeeder            (scale 1: about 300 customers, 4,000 invoices)
 *   STRESS_SCALE=5 php artisan db:seed --class=StressSeeder
 *   php artisan db:seed --class=StressCleanup           (removes everything this added)
 */
class StressSeeder extends Seeder
{
    private array $first = [];

    private function mark(string $table): void
    {
        $this->first[$table] = (int) DB::table($table)->max('id');
    }

    private function bulk(string $table, array $rows): void
    {
        foreach (array_chunk($rows, 400) as $chunk) {
            DB::table($table)->insert($chunk);
        }
    }

    public function run(): void
    {
        DB::transaction(fn () => $this->seed());
    }

    private function seed(): void
    {
        mt_srand(20261007);
        $scale = max(1, (int) env('STRESS_SCALE', 1));
        $now = now();

        foreach (['customers', 'customer_sites', 'equipment', 'service_requests', 'work_orders', 'quotations', 'quotation_items', 'invoices', 'invoice_items', 'payments', 'inventory_items', 'stock_movements', 'contracts'] as $t) {
            $this->mark($t);
        }
        File::put(storage_path('app/stress_marker.json'), json_encode($this->first));

        $branches = DB::table('branches')->pluck('id')->all();
        $techs = DB::table('users')->join('model_has_roles', 'users.id', '=', 'model_has_roles.model_id')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')->where('roles.name', 'Technician')->pluck('users.id')->all();
        $admin = (int) DB::table('users')->join('model_has_roles', 'users.id', '=', 'model_has_roles.model_id')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')->where('roles.name', 'Service Admin')->value('users.id');
        $finance = (int) DB::table('users')->join('model_has_roles', 'users.id', '=', 'model_has_roles.model_id')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')->where('roles.name', 'Finance')->value('users.id') ?: $admin;
        $cats = DB::table('equipment_categories')->pluck('name')->all() ?: ['Platform scale'];

        $pick = fn (array $a) => $a[mt_rand(0, count($a) - 1)];
        $ts = fn (int $daysAgoMax, int $daysAgoMin = 0) => now()->subDays(mt_rand($daysAgoMin, $daysAgoMax))->subMinutes(mt_rand(0, 1400));

        // ---- customers
        $a = ['Nairobi', 'Mombasa', 'Kisumu', 'Nakuru', 'Eldoret', 'Naivasha', 'Thika', 'Kitale', 'Machakos', 'Nyeri', 'Kericho', 'Malindi', 'Embu', 'Meru', 'Garissa', 'Athi River'];
        $b = ['Fresh', 'Highland', 'Savannah', 'Rift', 'Lakeside', 'Mount Kenya', 'Coastal', 'Pwani', 'Jua Kali', 'Unga', 'Maziwa', 'Chai', 'Kahawa', 'Sukari', 'Mifugo', 'Bidco', 'Twiga', 'Simba', 'Tembo', 'Safari'];
        $c = ['Millers', 'Dairies', 'Flowers', 'Logistics', 'Supermarkets', 'Pharmaceuticals', 'Foods', 'Breweries', 'Cement', 'Hardware', 'Agrovet', 'Tea Estates', 'Plastics', 'Steel', 'Hospital', 'Cold Chain', 'Grain Handlers', 'Packaging'];
        $suffix = ['Ltd', 'Limited', 'Co. Ltd', 'Group', '& Sons', 'Enterprises', 'Holdings'];
        $first = ['Wanjiru', 'Kamau', 'Otieno', 'Achieng', 'Kipchumba', 'Chebet', 'Mutiso', 'Njeri', 'Omondi', 'Wafula', 'Akinyi', 'Mwangi', 'Hassan', 'Naliaka', 'Kiprop', 'Atieno', 'Maina', 'Jelimo'];
        $last = ['Kariuki', 'Ochieng', 'Mutua', 'Wekesa', 'Koech', 'Njoroge', 'Abdi', 'Owino', 'Kimani', 'Rotich', 'Barasa', 'Mohamed', 'Gitau', 'Cheruiyot'];

        $nCust = 300 * $scale;
        $custIdBase = (int) DB::table('customers')->max('id');
        $refN = 100000;
        $rows = [];
        $used = [];
        for ($i = 1; $i <= $nCust; $i++) {
            $name = $pick($a).' '.$pick($b).' '.$pick($c).' '.$pick($suffix);
            if (isset($used[$name])) {
                $name .= ' '.$i;
            }
            $used[$name] = 1;
            if ($i === 1) {
                $name = 'Müller & Söhne Engineering (East Africa) '.str_repeat('Holdings ', 12).'Ltd'; // very long, non-ASCII
                $name = mb_substr($name, 0, 150);
            }
            $contact = $pick($first).' '.$pick($last);
            $rows[] = [
                'reference' => 'CUS-'.str_pad((string) ($refN + $i - 1), 4, '0', STR_PAD_LEFT),
                'name' => $name,
                'branch_id' => $pick($branches),
                'kra_pin' => (mt_rand(0, 1) ? 'P' : 'A').str_pad((string) mt_rand(0, 999999999), 9, '0', STR_PAD_LEFT).chr(mt_rand(65, 90)),
                'po_box' => 'P.O. Box '.mt_rand(100, 99999).'-'.str_pad((string) (mt_rand(1, 999) * 100), 5, '0', STR_PAD_LEFT),
                'main_contact_name' => $contact,
                'main_contact_email' => strtolower(str_replace(["'", ' '], ['', '.'], $contact)).$i.'@'.strtolower(preg_replace('/[^a-z]/i', '', explode(' ', $name)[1] ?? 'firm')).$i.'.co.ke',
                'main_contact_phone' => '07'.mt_rand(10, 99).' '.mt_rand(100, 999).' '.mt_rand(100, 999),
                'has_active_contract' => 0,
                'balance_minor' => 0,
                'created_at' => $ts(700, 30), 'updated_at' => $now,
            ];
        }
        $this->bulk('customers', $rows);
        $custIds = DB::table('customers')->where('id', '>', $custIdBase)->pluck('id')->all();

        // ---- sites (1 to 4 each, real Kenyan coordinates around towns)
        $towns = [[-1.2921, 36.8219], [-4.0435, 39.6682], [-0.0917, 34.7680], [-0.3031, 36.0800], [0.5143, 35.2698], [-0.7172, 36.4310], [-1.0332, 37.0693], [0.0236, 37.0]];
        $rows = [];
        foreach ($custIds as $cid) {
            for ($s = 0, $n = mt_rand(1, 4); $s < $n; $s++) {
                $t = $pick($towns);
                $rows[] = ['customer_id' => $cid, 'name' => $pick(['Main factory', 'Warehouse', 'Depot', 'Head office', 'Packhouse', 'Branch', 'Weighbridge yard']).' '.($s + 1),
                    'contact_name' => $pick($first).' '.$pick($last), 'lat' => round($t[0] + mt_rand(-300, 300) / 1000, 6), 'lng' => round($t[1] + mt_rand(-300, 300) / 1000, 6),
                    'address' => 'Plot '.mt_rand(1, 900).', Industrial Area', 'created_at' => $now, 'updated_at' => $now];
            }
        }
        $this->bulk('customer_sites', $rows);
        $sitesByCust = DB::table('customer_sites')->where('id', '>', $this->first['customer_sites'])->get(['id', 'customer_id'])->groupBy('customer_id')->map->pluck('id')->all();

        // ---- equipment
        $models = ['Platform scale TE1005, 500 kg', 'Weighbridge 60t', 'Bench scale 30 kg', 'Hanging scale 1t', 'Pallet scale 2t', 'Moisture analyser MA-35', 'Truck scale 80t', 'Counting scale 6 kg'];
        $rows = [];
        $nEq = 600 * $scale;
        for ($i = 1; $i <= $nEq; $i++) {
            $cid = $pick($custIds);
            $inst = $ts(1500, 60);
            $rows[] = ['serial_number' => 'STR-'.str_pad((string) $i, 6, '0', STR_PAD_LEFT), 'model' => $pick($models), 'customer_id' => $cid,
                'customer_site_id' => ($sitesByCust[$cid] ?? [null])[0], 'category' => $pick($cats), 'installed_at' => $inst->toDateString(),
                'warranty_expires_at' => $inst->copy()->addYears(2)->toDateString(), 'cover' => $pick(['Chargeable', 'Warranty', 'Contract']),
                'next_visit_due_at' => now()->addDays(mt_rand(-60, 200))->toDateString(), 'created_at' => $now, 'updated_at' => $now];
        }
        $this->bulk('equipment', $rows);
        $eq = DB::table('equipment')->where('id', '>', $this->first['equipment'])->get(['id', 'customer_id', 'customer_site_id'])->all();

        // ---- contracts
        $rows = [];
        $cn = 100000;
        $nCt = 80 * $scale;
        for ($i = 0; $i < $nCt; $i++) {
            $st = now()->subDays(mt_rand(-30, 700));
            $rows[] = ['reference' => 'CT-'.str_pad((string) ($cn + $i), 4, '0', STR_PAD_LEFT), 'customer_id' => $pick($custIds), 'type' => $pick(['Full service', 'Call out', 'Maintenance only']),
                'starts_at' => $st->toDateString(), 'ends_at' => $st->copy()->addYear()->toDateString(), 'visits_included' => mt_rand(2, 24), 'visits_used' => mt_rand(0, 6),
                'value_minor' => mt_rand(5, 900) * 100000, 'currency_code' => 'KES', 'status' => $st->copy()->addYear()->isPast() ? 'Expired' : 'Active', 'created_at' => $now, 'updated_at' => $now];
        }
        $this->bulk('contracts', $rows);

        // ---- service requests
        $faults = ['Display flickers and reading drifts under load', 'Scale will not zero after unloading', 'Load cell error E04 on start up', 'Printer jams and prints blank tickets', 'Indicator dead after power surge', 'Platform rocking, rubber feet worn', 'Weighbridge junction box water damage', 'Calibration certificate expired, needs recalibration', 'Battery will not hold charge', 'Intermittent communication with the PC'];
        $rn = 100000;
        $nSr = 700 * $scale;
        $rows = [];
        for ($i = 0; $i < $nSr; $i++) {
            $e = $pick($eq);
            $rows[] = ['reference' => 'SR-'.str_pad((string) ($rn + $i), 4, '0', STR_PAD_LEFT), 'customer_id' => $e->customer_id, 'customer_site_id' => $e->customer_site_id, 'equipment_id' => $e->id,
                'contact_name' => $pick($first).' '.$pick($last), 'fault_description' => $pick($faults), 'cover' => $pick(['Chargeable', 'Contract']),
                'priority' => $pick(['Low', 'Medium', 'High']), 'status' => $pick(['Open', 'Open', 'Assigned', 'Quoted', 'Converted', 'Declined']),
                'assigned_technician_id' => mt_rand(0, 1) ? $pick($techs) : null, 'logged_by_id' => $admin, 'created_at' => $ts(400), 'updated_at' => $now];
        }
        $this->bulk('service_requests', $rows);

        // ---- work orders
        $wn = 100000;
        $nWo = 1500 * $scale;
        $rows = [];
        for ($i = 0; $i < $nWo; $i++) {
            $e = $pick($eq);
            $due = now()->addDays(mt_rand(-300, 30));
            $status = $due->isPast() ? $pick(['Closed', 'Closed', 'Closed', 'Awaiting review', 'On site', 'Approved']) : $pick(['Approved', 'Approved', 'On site']);
            $rows[] = ['reference' => 'WO-'.str_pad((string) ($wn + $i), 4, '0', STR_PAD_LEFT), 'customer_id' => $e->customer_id, 'customer_site_id' => $e->customer_site_id, 'equipment_id' => $e->id,
                'nature_of_visit' => $pick(['Planned maintenance', 'Service', 'Repairs', 'Normal customer visit']), 'priority' => $pick(['Low', 'Medium', 'High']),
                'assigned_technician_id' => $pick($techs), 'due_date' => $due->toDateString(), 'status' => $status, 'value_type' => 'chargeable',
                'value_minor' => mt_rand(5, 400) * 10000, 'currency_code' => 'KES', 'created_at' => $due->copy()->subDays(3), 'updated_at' => $now];
        }
        $this->bulk('work_orders', $rows);
        $wo = DB::table('work_orders')->where('id', '>', $this->first['work_orders'])->get(['id', 'customer_id'])->all();

        // ---- quotations (with 1 to 8 items)
        $qn = 100000;
        $nQ = 600 * $scale;
        $rows = [];
        for ($i = 0; $i < $nQ; $i++) {
            $rows[] = ['reference' => 'QT-'.str_pad((string) ($qn + $i), 4, '0', STR_PAD_LEFT), 'customer_id' => $pick($custIds), 'scope' => $pick($faults).'. Supply and fit parts, recalibrate and issue certificate.',
                'validity_days' => 30, 'labour_minor' => mt_rand(0, 60) * 10000, 'vat_rate' => 0.16, 'currency_code' => 'KES', 'lpo_status' => $pick(['Not yet received', 'On file']),
                'approval_threshold' => 300000000, 'status' => $pick(['Awaiting Supervisor', 'Awaiting Manager', 'Approved', 'Accepted', 'Sent back', 'Converted']), 'created_by' => $admin, 'created_at' => $ts(300), 'updated_at' => $now];
        }
        $this->bulk('quotations', $rows);
        $items = [];
        $parts = ['Load cell 500 kg', 'Indicator board', 'Junction box', 'Rubber feet set', 'Thermal printer', 'Battery 6V 4.5Ah', 'Calibration weights hire', 'Cable gland pack', 'Display ribbon', 'Labour call out'];
        foreach (DB::table('quotations')->where('id', '>', $this->first['quotations'])->pluck('id') as $qid) {
            for ($k = 0, $n = mt_rand(1, 8); $k < $n; $k++) {
                $items[] = ['quotation_id' => $qid, 'description' => $pick($parts), 'quantity' => mt_rand(1, 6), 'rate_minor' => mt_rand(5, 800) * 10000, 'sort_order' => $k, 'created_at' => $now, 'updated_at' => $now];
            }
        }
        $this->bulk('quotation_items', $items);

        // ---- invoices + items + payments
        $in = 100000;
        $rcn = 100000;
        $nInv = 4000 * $scale;
        $big = $pick($custIds); // one customer gets a very long history
        $inv = [];
        for ($i = 0; $i < $nInv; $i++) {
            $w = $pick($wo);
            $cid = $i < 400 * $scale ? $big : $w->customer_id;
            $issued = $ts(540);
            $items = mt_rand(1, 5);
            $net = 0;
            $it = [];
            for ($k = 0; $k < $items; $k++) {
                $q = mt_rand(1, 5); $r = mt_rand(5, 600) * 10000; $net += $q * $r;
                $it[] = [$pick($parts), $q, $r];
            }
            $amount = (int) round($net * 1.16);
            $status = $pick(['Paid', 'Paid', 'Paid', 'Unpaid', 'Unpaid', 'Part paid', 'Draft']);
            $paid = match ($status) { 'Paid' => $amount, 'Part paid' => (int) floor($amount * mt_rand(20, 80) / 100), default => 0 };
            if ($i === 0) { // the extremes: 100 line items, near the largest amount the form allows
                $it = []; $net = 0;
                for ($k = 0; $k < 100; $k++) { $it[] = ['Line item '.($k + 1).' with a fairly long description to test wrapping on printed invoices', 1, 1000000]; $net += 1000000; }
                $amount = (int) round($net * 1.16); $status = 'Unpaid'; $paid = 0;
            }
            $inv[] = [$cid, $w->id, $issued, $amount, $paid, $status, $it, 'INV-'.str_pad((string) ($in + $i), 4, '0', STR_PAD_LEFT)];
        }
        $rows = [];
        foreach ($inv as $x) {
            $rows[] = ['reference' => $x[7], 'customer_id' => $x[0], 'work_order_id' => $x[1], 'issued_at' => $x[2]->toDateString(), 'due_at' => $x[2]->copy()->addDays(30)->toDateString(),
                'amount_minor' => $x[3], 'paid_minor' => $x[4], 'currency_code' => 'KES', 'status' => $x[5], 'raised_by' => $finance, 'vat_rate' => 0.16, 'created_at' => $x[2], 'updated_at' => $now];
        }
        $this->bulk('invoices', $rows);
        $ids = DB::table('invoices')->where('id', '>', $this->first['invoices'])->orderBy('id')->pluck('id')->all();
        $itemRows = []; $payRows = [];
        foreach ($ids as $idx => $iid) {
            foreach ($inv[$idx][6] as $l) {
                $itemRows[] = ['invoice_id' => $iid, 'description' => $l[0], 'quantity' => $l[1], 'rate_minor' => $l[2], 'created_at' => $now, 'updated_at' => $now];
            }
            if ($inv[$idx][4] > 0) {
                $payRows[] = ['reference' => 'RCP-'.str_pad((string) ($rcn++), 4, '0', STR_PAD_LEFT), 'invoice_id' => $iid, 'customer_id' => $inv[$idx][0], 'paid_at' => $inv[$idx][2]->copy()->addDays(mt_rand(1, 60)),
                    'amount_minor' => $inv[$idx][4], 'currency_code' => 'KES', 'method' => $pick(['Bank transfer', 'M-Pesa', 'Cheque']), 'recorded_by' => $finance, 'created_at' => $now, 'updated_at' => $now];
            }
        }
        $this->bulk('invoice_items', $itemRows);
        $this->bulk('payments', $payRows);

        // ---- inventory and stock movements
        $rows = [];
        $nInv2 = 400 * $scale;
        $stockCats = \App\Support\Settings::lines(implode("\n", (array) setting('stock_categories'))) ?: ['Spare part'];
        for ($i = 1; $i <= $nInv2; $i++) {
            $rows[] = ['code' => 'STK-'.str_pad((string) $i, 5, '0', STR_PAD_LEFT), 'name' => $pick($parts).' type '.$i, 'category' => $pick($stockCats), 'manufacturer' => $pick(['Avery', 'Mettler', 'Tedea', 'Zemic', 'Local']),
                'unit' => $pick(['Piece', 'Set', 'Roll', 'Box']), 'serial_tracked' => 0, 'quantity' => $i % 9 === 0 ? 0 : mt_rand(1, 300), 'reorder_level' => mt_rand(0, 30),
                'branch_id' => $pick($branches), 'cost_minor' => mt_rand(5, 600) * 10000, 'price_minor' => mt_rand(8, 900) * 10000, 'created_at' => $now, 'updated_at' => $now];
        }
        $this->bulk('inventory_items', $rows);
        $stock = DB::table('inventory_items')->where('id', '>', $this->first['inventory_items'])->pluck('id')->all();
        $mn = 100000;
        $rows = [];
        $nMv = 3000 * $scale;
        for ($i = 0; $i < $nMv; $i++) {
            $t = $pick(['Issue', 'Stock in', 'Adjustment']);
            $rows[] = ['reference' => 'MOV-'.str_pad((string) ($mn + $i), 5, '0', STR_PAD_LEFT), 'inventory_item_id' => $pick($stock), 'type' => $t,
                'quantity_delta' => $t === 'Issue' ? -mt_rand(1, 5) : mt_rand(1, 20), 'recorded_by' => $admin, 'occurred_at' => $ts(400), 'created_at' => $now, 'updated_at' => $now];
        }
        $this->bulk('stock_movements', $rows);

        // Keep the customer summary columns in step with the rows just added.
        DB::statement("update customers set has_active_contract = case when exists (select 1 from contracts c where c.customer_id = customers.id and c.status = 'Active') then 1 else 0 end where id > ".$this->first['customers']);
        DB::statement("update customers set balance_minor = coalesce((select sum(i.amount_minor - i.paid_minor) from invoices i where i.customer_id = customers.id and i.status in ('Unpaid', 'Part paid')), 0) where id > ".$this->first['customers']);

        $this->command?->info(sprintf('Seeded %d customers, %d machines, %d jobs, %d invoices, %d payments, %d stock items.', $nCust, $nEq, $nWo, $nInv, count($payRows), $nInv2));
    }
}
