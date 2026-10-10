<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('inbox_notifications')) {
            Schema::create('inbox_notifications', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('title');
                $table->json('lines')->nullable();
                $table->string('url', 500)->nullable();
                $table->string('label', 100)->nullable();
                $table->timestamp('read_at')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'read_at', 'created_at']);
            });
        }

        if (! Schema::hasColumn('users', 'email_notifications')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('email_notifications')->default(true);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_notifications');

        if (Schema::hasColumn('users', 'email_notifications')) {
            Schema::table('users', fn (Blueprint $t) => $t->dropColumn('email_notifications'));
        }
    }
};
