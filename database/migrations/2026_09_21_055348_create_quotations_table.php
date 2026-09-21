<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotations', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('customer_id')->constrained();
            $table->foreignId('customer_site_id')->nullable()->constrained();
            $table->foreignId('source_service_request_id')->nullable()->constrained('service_requests');
            $table->string('scope');
            $table->unsignedSmallInteger('validity_days')->default(30);
            $table->string('payment_terms')->nullable();
            $table->unsignedBigInteger('labour_minor')->default(0);
            $table->decimal('vat_rate', 4, 3)->default(0.160);
            $table->char('currency_code', 3)->default('KES');
            $table->string('lpo_status')->default('Not yet received');
            $table->string('lpo_reference')->nullable();
            $table->string('approval_threshold')->default('Supervisor');
            $table->string('status')->default('Awaiting Supervisor');
            $table->unsignedBigInteger('converted_work_order_id')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotations');
    }
};