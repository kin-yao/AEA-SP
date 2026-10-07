<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('customer_sites', 'address')) {
            Schema::table('customer_sites', function (Blueprint $table) {
                $table->string('address', 500)->nullable()->after('name');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('customer_sites', 'address')) {
            Schema::table('customer_sites', function (Blueprint $table) {
                $table->dropColumn('address');
            });
        }
    }
};
