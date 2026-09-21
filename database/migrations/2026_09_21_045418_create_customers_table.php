<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->string('name');
            $table->foreignId('branch_id')->constrained();
            $table->string('kra_pin')->nullable();
            $table->string('po_box')->nullable();
            $table->string('main_contact_name')->nullable();
            $table->string('main_contact_email')->nullable();
            $table->string('main_contact_phone')->nullable();
            $table->boolean('has_active_contract')->default(false);
            $table->unsignedBigInteger('balance_minor')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};