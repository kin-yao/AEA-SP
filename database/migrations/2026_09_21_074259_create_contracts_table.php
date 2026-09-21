<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Business rule: a contract cannot be edited once created, only terminated
// or superseded by a fresh contract. Enforce that in a Policy class later,
// not here, the schema doesn't need to know about it.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique(); // CT-0031
            $table->foreignId('customer_id')->constrained();
            $table->string('type'); // Full service, Planned maintenance, Calibration
            $table->date('starts_at');
            $table->date('ends_at');
            $table->unsignedSmallInteger('visits_included')->default(0);
            $table->unsignedSmallInteger('visits_used')->default(0);
            $table->unsignedBigInteger('value_minor')->nullable();
            $table->char('currency_code', 3)->default('KES');
            $table->string('status')->default('Active'); // Active, Expiring soon, Expired, Terminated
            $table->string('scan_file_path')->nullable();
            $table->timestamps();
        });

        // maintenance_voucher_details.contract_id was created earlier
        // without a constraint, since this table didn't exist yet. Attach
        // the real foreign key now, same deferred pattern as work_orders.
        Schema::table('maintenance_voucher_details', function (Blueprint $table) {
            $table->foreign('contract_id')->references('id')->on('contracts');
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_voucher_details', function (Blueprint $table) {
            $table->dropForeign(['contract_id']);
        });

        Schema::dropIfExists('contracts');
    }
};