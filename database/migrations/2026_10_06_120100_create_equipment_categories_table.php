<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipment_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        // Start the list from what machines already use, plus the two examples
        // the old free-text box suggested.
        $names = DB::table('equipment')->whereNotNull('category')->where('category', '!=', '')->distinct()->pluck('category')->all();
        foreach (array_unique(array_merge($names, ['Platform scale', 'Weighbridge'])) as $name) {
            DB::table('equipment_categories')->insert(['name' => $name, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment_categories');
    }
};