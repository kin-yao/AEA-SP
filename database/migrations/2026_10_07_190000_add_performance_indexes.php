<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the columns lists, dashboards and reports filter and sort by. Without them the database
 * reads every row of a table for each page view, which is fine at 50 rows and painful at 50,000.
 */
return new class extends Migration
{
    private function indexes(): array
    {
        return [
            'work_orders' => [['contract_id', 'status'], ['status'], ['due_date'], ['status', 'due_date'], ['assigned_technician_id', 'status'], ['customer_id', 'status']],
            'invoices' => [['status'], ['due_at'], ['issued_at'], ['customer_id', 'status'], ['status', 'due_at']],
            'payments' => [['paid_at'], ['method']],
            'quotations' => [['status'], ['created_at'], ['customer_id', 'status']],
            'service_requests' => [['status'], ['created_at'], ['customer_id', 'status']],
            'customers' => [['name'], ['branch_id', 'name']],
            'equipment' => [['next_visit_due_at'], ['model'], ['customer_id']],
            'inventory_items' => [['category'], ['name'], ['branch_id']],
            'stock_movements' => [['occurred_at'], ['inventory_item_id', 'occurred_at']],
            'contracts' => [['status'], ['ends_at'], ['customer_id', 'status']],
            'documents' => [['status'], ['type', 'status'], ['customer_id']],
        ];
    }

    public function up(): void
    {
        foreach ($this->indexes() as $table => $sets) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($sets as $columns) {
                if (! Schema::hasColumns($table, $columns) || Schema::hasIndex($table, $columns)) {
                    continue;
                }
                Schema::table($table, fn (Blueprint $t) => $t->index($columns, $table.'_'.implode('_', $columns).'_perf'));
            }
        }
    }

    public function down(): void
    {
        foreach ($this->indexes() as $table => $sets) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($sets as $columns) {
                $name = $table.'_'.implode('_', $columns).'_perf';
                if (Schema::hasIndex($table, $name)) {
                    Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
                }
            }
        }
    }
};
