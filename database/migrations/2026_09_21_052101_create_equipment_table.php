<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipment', function (Blueprint $table) {
            $table->id();
            $table->string('serial_number')->unique();
            $table->string('model');
            $table->foreignId('customer_id')->constrained();
            $table->foreignId('customer_site_id')->nullable()->constrained();
            $table->string('category')->nullable();
            $table->date('installed_at')->nullable();
            $table->date('warranty_expires_at')->nullable();
            $table->string('cover')->default('Chargeable');
            $table->date('next_visit_due_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment');
    }
};