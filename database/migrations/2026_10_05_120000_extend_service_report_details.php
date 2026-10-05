<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_report_details', function (Blueprint $table) {
            $table->date('report_date')->nullable();
            $table->string('tel_no')->nullable();
            $table->string('equipment_description')->nullable();
            $table->text('parts_to_order')->nullable();
            $table->text('customer_comments')->nullable();
            $table->string('contract_on_file')->nullable();
            $table->string('voucher_number')->nullable();
            $table->string('delivery_note_path')->nullable();
            $table->string('incident_photo_path')->nullable();
            $table->longText('repairer_signature')->nullable();
            $table->longText('customer_signature')->nullable();
            $table->longText('voucher_signature')->nullable();
        });

        Schema::table('report_parts', function (Blueprint $table) {
            $table->unsignedInteger('returned')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('report_parts', function (Blueprint $table) {
            $table->dropColumn('returned');
        });

        Schema::table('service_report_details', function (Blueprint $table) {
            $table->dropColumn([
                'report_date', 'tel_no', 'equipment_description', 'parts_to_order',
                'customer_comments', 'contract_on_file', 'voucher_number',
                'delivery_note_path', 'incident_photo_path',
                'repairer_signature', 'customer_signature', 'voucher_signature',
            ]);
        });
    }
};
