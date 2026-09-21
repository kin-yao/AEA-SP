<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_requests', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('customer_id')->constrained();
            $table->foreignId('customer_site_id')->nullable()->constrained();
            $table->foreignId('equipment_id')->nullable()->constrained();
            $table->string('equipment_description')->nullable();
            $table->string('contact_name')->nullable();
            $table->text('fault_description');
            $table->string('cover')->default('Chargeable');
            $table->string('priority')->default('Medium');
            $table->string('status')->default('Open');
            $table->foreignId('assigned_technician_id')->nullable()->constrained('users');
            $table->string('nature_of_visit')->nullable();
            $table->foreignId('logged_by_id')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_requests');
    }
};