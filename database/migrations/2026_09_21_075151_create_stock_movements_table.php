<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique(); // internal stock movement number
            $table->foreignId('inventory_item_id')->constrained();
            $table->string('type'); // Issue, Stock in, Adjustment
            $table->integer('quantity_delta'); // negative for issues/adjustments out, positive for stock in
            $table->string('from_location')->nullable();
            $table->string('to_location')->nullable();
            $table->foreignId('work_order_id')->nullable()->constrained();
            $table->foreignId('recorded_by')->constrained('users');
            $table->timestamp('occurred_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};