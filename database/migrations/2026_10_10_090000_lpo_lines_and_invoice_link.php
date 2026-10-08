<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The LPO is the binding agreement, so it carries its own priced lines.
        if (! Schema::hasTable('lpo_items')) {
            Schema::create('lpo_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('lpo_detail_id')->constrained('lpo_details')->cascadeOnDelete();
                $table->string('description', 500);
                $table->decimal('quantity', 12, 2)->default(1);
                $table->unsignedBigInteger('rate_minor')->default(0);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasColumn('lpo_details', 'labour_minor')) {
            Schema::table('lpo_details', function (Blueprint $table) {
                $table->unsignedBigInteger('labour_minor')->nullable(); // null until the lines are copied from the quotation
                $table->decimal('vat_rate', 6, 3)->nullable();
                $table->string('currency_code', 3)->nullable();
                $table->text('change_note')->nullable();
                $table->unsignedBigInteger('edited_by')->nullable();
                $table->timestamp('edited_at')->nullable();
            });
        }

        if (! Schema::hasColumn('invoices', 'lpo_document_id')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->unsignedBigInteger('lpo_document_id')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('lpo_items');
        if (Schema::hasColumn('lpo_details', 'labour_minor')) {
            Schema::table('lpo_details', fn (Blueprint $t) => $t->dropColumn(['labour_minor', 'vat_rate', 'currency_code', 'change_note', 'edited_by', 'edited_at']));
        }
        if (Schema::hasColumn('invoices', 'lpo_document_id')) {
            Schema::table('invoices', fn (Blueprint $t) => $t->dropColumn('lpo_document_id'));
        }
    }
};
