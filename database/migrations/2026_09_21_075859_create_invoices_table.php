<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique(); // INV-0088
            $table->foreignId('customer_id')->constrained();
            $table->foreignId('work_order_id')->nullable()->constrained();
            $table->foreignId('contract_id')->nullable()->constrained();
            $table->date('issued_at');
            $table->date('due_at');
            $table->unsignedBigInteger('amount_minor');
            $table->unsignedBigInteger('paid_minor')->default(0);
            $table->char('currency_code', 3)->default('KES');
            $table->string('status')->default('Draft'); // Draft, Unpaid, Part paid, Paid, Overdue
            $table->boolean('documents_required_before_send')->default(false); // per-customer policy
            $table->boolean('documents_attached')->default(false);
            $table->foreignId('raised_by')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};