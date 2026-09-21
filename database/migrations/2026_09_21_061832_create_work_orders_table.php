<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_orders', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique(); // WO-0412
            $table->foreignId('customer_id')->constrained();
            $table->foreignId('customer_site_id')->nullable()->constrained();
            $table->foreignId('equipment_id')->nullable()->constrained();
            $table->string('equipment_description')->nullable();
            $table->string('nature_of_visit');
            $table->string('priority')->default('Medium');
            $table->foreignId('assigned_technician_id')->constrained('users');
            $table->string('vehicle')->nullable();
            $table->date('due_date');
            $table->string('status')->default('Assigned');
            $table->foreignId('source_service_request_id')->nullable()->constrained('service_requests');
            $table->foreignId('source_quotation_id')->nullable()->constrained('quotations');
            $table->string('value_type')->default('tbd'); // contract, chargeable, tbd
            $table->unsignedBigInteger('value_minor')->nullable();
            $table->char('currency_code', 3)->default('KES');
            $table->timestamps();
        });

        // quotations.converted_work_order_id was created earlier without a
        // constraint, since work_orders didn't exist yet. Attach it now.
        Schema::table('quotations', function (Blueprint $table) {
            $table->foreign('converted_work_order_id')->references('id')->on('work_orders');
        });
    }

    public function down(): void
    {
        // Drop that foreign key first, MySQL won't let us drop work_orders
        // while quotations still references it.
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropForeign(['converted_work_order_id']);
        });

        Schema::dropIfExists('work_orders');
    }
};