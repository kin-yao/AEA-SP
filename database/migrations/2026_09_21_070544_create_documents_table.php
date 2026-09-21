<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// One row per document, regardless of type. Type-specific fields live in the
// *_details tables we'll add next, each keyed on document_id. This keeps the
// Documents hub's list/filter queries fast and generic, while each type
// stays properly typed rather than jammed into one shared JSON column.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique(); // 26504, CRT-0188, KSM-LPO-2291, MV-2026-0412, DN-0412
            $table->string('type'); // rep, cert, lpo, mv, dn
            $table->foreignId('work_order_id')->nullable()->constrained();
            $table->foreignId('customer_id')->constrained();
            $table->string('status');
            $table->foreignId('filed_by')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};