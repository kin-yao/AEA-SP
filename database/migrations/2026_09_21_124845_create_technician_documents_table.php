<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Percentage-of-validity-used and the 75%/90% alert status are computed on
// the model (see TechnicianDocument::percentUsed()), never stored, so they
// never go stale, same approach as Contract::percentOfTermUsed().
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('technician_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('technician_id')->constrained('users');
            $table->string('document_type');
            // Medical cover / insurance, Driving licence, Trade certification, Medical certificate
            $table->date('issued_at');
            $table->unsignedSmallInteger('validity_months');
            $table->string('file_path')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('technician_documents');
    }
};