<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('documents', 'title')) {
            Schema::table('documents', function (Blueprint $table) {
                $table->string('title', 120)->nullable()->after('type');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('documents', 'title')) {
            Schema::table('documents', function (Blueprint $table) {
                $table->dropColumn('title');
            });
        }
    }
};
