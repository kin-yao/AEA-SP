<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_parts', function (Blueprint $table) {
            // The stock item this line was taken from, so submitting the report can deduct it.
            $table->unsignedBigInteger('inventory_item_id')->nullable()->after('service_report_detail_id');
        });
    }

    public function down(): void
    {
        Schema::table('report_parts', function (Blueprint $table) {
            $table->dropColumn('inventory_item_id');
        });
    }
};
