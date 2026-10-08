<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('documents', 'file_path')) {
            Schema::table('documents', function (Blueprint $table) {
                $table->string('file_path', 500)->nullable()->after('status');
                $table->string('file_name', 255)->nullable()->after('file_path');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('documents', 'file_path')) {
            Schema::table('documents', function (Blueprint $table) {
                $table->dropColumn(['file_path', 'file_name']);
            });
        }
    }
};
