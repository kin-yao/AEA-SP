<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('currencies')) {
            Schema::create('currencies', function (Blueprint $table) {
                $table->id();
                $table->string('code', 3)->unique();
                $table->string('name');
                $table->timestamps();
            });
        }

        \App\Models\Currency::sync();

        // The common currencies are always on the list, so ICT can pick them without adding them first.
        foreach (\App\Models\Currency::KNOWN as $code => $name) {
            if (! DB::table('currencies')->where('code', $code)->exists()) {
                DB::table('currencies')->insert(['code' => $code, 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        // LPOs get their own numbering, for LPOs logged without the customer's number.
        if (Schema::hasTable('reference_series') && ! DB::table('reference_series')->where('key', 'lpo')->exists()) {
            $used = Schema::hasTable('documents') ? (int) DB::table('documents')->where('type', 'lpo')->count() : 0;

            DB::table('reference_series')->insert([
                'key' => 'lpo',
                'label' => 'LPOs',
                'prefix' => 'LPO',
                'digits' => 4,
                'next_number' => $used + 1,
                'is_system' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('currencies');
        DB::table('reference_series')->where('key', 'lpo')->delete();
    }
};
