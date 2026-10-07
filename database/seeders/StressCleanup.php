<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/** Removes everything StressSeeder added (rows created after its marker). */
class StressCleanup extends Seeder
{
    public function run(): void
    {
        $path = storage_path('app/stress_marker.json');
        if (! File::exists($path)) {
            $this->command?->warn('No marker file, nothing to remove.');

            return;
        }
        $m = json_decode(File::get($path), true);
        foreach (['payments', 'invoice_items', 'invoices', 'quotation_items', 'quotations', 'stock_movements', 'inventory_items', 'work_orders', 'service_requests', 'contracts', 'equipment', 'customer_sites', 'customers'] as $t) {
            DB::table($t)->where('id', '>', $m[$t] ?? PHP_INT_MAX)->delete();
        }
        File::delete($path);
        $this->command?->info('Stress data removed.');
    }
}
