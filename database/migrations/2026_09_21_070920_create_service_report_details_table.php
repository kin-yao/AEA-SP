<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_report_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('vehicle')->nullable();
            $table->foreignId('branch_id')->nullable()->constrained();
            $table->string('contact_name')->nullable();
            $table->string('address')->nullable();
            $table->string('nature_of_visit');
            $table->text('fault_description')->nullable();
            $table->text('cause')->nullable();
            $table->text('correction')->nullable();
            $table->text('final_result')->nullable();
            $table->string('incident_type')->default('None'); // None, Near miss, Damage, Safety issue
            $table->text('incident_description')->nullable();
            $table->string('repairer_name')->nullable();
            $table->string('customer_signoff_name')->nullable();
            $table->timestamp('customer_signed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('report_parts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_report_detail_id')->constrained()->cascadeOnDelete();
            $table->string('item');
            $table->string('part_number')->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->string('source')->nullable(); // Vehicle stock, Nairobi store, ...
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_parts');
        Schema::dropIfExists('service_report_details');
    }
};