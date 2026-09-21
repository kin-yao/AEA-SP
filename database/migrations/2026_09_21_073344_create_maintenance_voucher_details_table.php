<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_voucher_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('contract_id')->nullable();
            // No ->constrained() yet, the contracts table doesn't exist
            // until our next step, same deferred pattern we used for
            // quotations.converted_work_order_id.
            $table->foreignId('technician_id')->constrained('users');
            $table->string('client_signatory')->nullable();
            $table->timestamp('client_signed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_voucher_details');
    }
};