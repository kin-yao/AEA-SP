<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('contracts', 'frequency')) {
            Schema::table('contracts', function (Blueprint $table) {
                $table->string('frequency', 60)->nullable()->after('type');
            });
        }

        // The machines a contract covers.
        if (! Schema::hasTable('contract_equipment')) {
            Schema::create('contract_equipment', function (Blueprint $table) {
                $table->id();
                $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
                $table->foreignId('equipment_id')->constrained('equipment')->cascadeOnDelete();
                $table->timestamps();

                $table->unique(['contract_id', 'equipment_id']);
                $table->index('equipment_id');
            });
        }

        // The planned service dates. Made from the frequency, then free to edit.
        if (! Schema::hasTable('contract_service_dates')) {
            Schema::create('contract_service_dates', function (Blueprint $table) {
                $table->id();
                $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
                $table->date('due_on');
                $table->foreignId('work_order_id')->nullable()->constrained('work_orders')->nullOnDelete();
                $table->timestamps();

                $table->index(['contract_id', 'due_on']);
                $table->index('due_on');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_service_dates');
        Schema::dropIfExists('contract_equipment');

        if (Schema::hasColumn('contracts', 'frequency')) {
            Schema::table('contracts', fn (Blueprint $t) => $t->dropColumn('frequency'));
        }
    }
};
