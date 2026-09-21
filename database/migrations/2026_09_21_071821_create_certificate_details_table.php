<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certificate_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('equipment_id')->nullable()->constrained();
            $table->string('certificate_type'); // AEA internal, Government
            $table->date('issued_at');
            $table->date('expires_at');
            $table->string('file_path')->nullable(); // storage path once the scan is uploaded
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certificate_details');
    }
};