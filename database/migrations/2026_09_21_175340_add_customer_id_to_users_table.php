<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Nullable and only meaningful for users with the Customer role. Staff
// accounts (Manager, Supervisor, Technician, etc) leave this null, same
// pattern as branch_id being null for Super Admin.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->after('branch_id')->constrained();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_id');
        });
    }
};