<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique(); // ITM-0142
            $table->string('name');
            $table->string('category'); // Spare part, Equipment, Test equipment, Consumable
            $table->string('manufacturer')->nullable();
            $table->string('model')->nullable();
            $table->string('unit')->default('Piece'); // Piece, Set, Roll
            $table->boolean('serial_tracked')->default(false);
            $table->unsignedInteger('quantity')->default(0);
            $table->unsignedInteger('reorder_level')->default(0);
            $table->foreignId('branch_id')->constrained();
            $table->unsignedBigInteger('cost_minor')->nullable();
            $table->unsignedBigInteger('price_minor')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_items');
    }
};