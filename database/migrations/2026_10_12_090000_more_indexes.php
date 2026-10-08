<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes found missing in a review of how the app actually queries:
 * - audit_logs: the ICT dashboard counts sign-ins and failures by event within a time window.
 * - quotations: the approval queues filter by status and who must approve.
 * - work_orders: the dispatch grid reads one technician's jobs across a range of due dates.
 * - invoices.lpo_document_id and report_parts.inventory_item_id: new links with no index or constraint of their own.
 */
return new class extends Migration
{
    private function indexes(): array
    {
        return [
            'audit_logs' => [['event', 'created_at']],
            'quotations' => [['status', 'approval_threshold']],
            'work_orders' => [['assigned_technician_id', 'due_date']],
            'invoices' => [['lpo_document_id']],
            'report_parts' => [['inventory_item_id']],
        ];
    }

    public function up(): void
    {
        foreach ($this->indexes() as $table => $sets) {
            foreach ($sets as $columns) {
                if (! Schema::hasTable($table) || ! Schema::hasColumns($table, $columns) || Schema::hasIndex($table, $columns)) {
                    continue;
                }
                Schema::table($table, fn (Blueprint $t) => $t->index($columns, $table.'_'.implode('_', $columns).'_perf2'));
            }
        }
    }

    public function down(): void
    {
        foreach ($this->indexes() as $table => $sets) {
            foreach ($sets as $columns) {
                $name = $table.'_'.implode('_', $columns).'_perf2';
                if (Schema::hasTable($table) && Schema::hasIndex($table, $name)) {
                    Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
                }
            }
        }
    }
};
