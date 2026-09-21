<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// AEA never generates the LPO. The customer issues it; Service Admin logs it
// here once it arrives, against the quotation it authorises.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lpo_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('quotation_id')->nullable()->constrained();
            $table->string('received_via')->nullable(); // E-mail, hand delivered, ...
            $table->string('file_path')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lpo_details');
    }
};