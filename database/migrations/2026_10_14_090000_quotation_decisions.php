<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            foreach ([
                'rejection_reason' => fn () => $table->text('rejection_reason')->nullable(),
                'decided_by' => fn () => $table->unsignedBigInteger('decided_by')->nullable(),
                'decided_at' => fn () => $table->timestamp('decided_at')->nullable(),
                'escalated_by' => fn () => $table->unsignedBigInteger('escalated_by')->nullable(),
                'escalated_at' => fn () => $table->timestamp('escalated_at')->nullable(),
                'escalation_note' => fn () => $table->text('escalation_note')->nullable(),
            ] as $column => $add) {
                if (! Schema::hasColumn('quotations', $column)) {
                    $add();
                }
            }
        });

        // "Sent back" is now called "Rejected", and always comes with a reason.
        DB::table('quotations')->where('status', 'Sent back')->update(['status' => 'Rejected']);
    }

    public function down(): void
    {
        DB::table('quotations')->where('status', 'Rejected')->update(['status' => 'Sent back']);

        Schema::table('quotations', function (Blueprint $table) {
            $table->dropColumn(['rejection_reason', 'decided_by', 'decided_at', 'escalated_by', 'escalated_at', 'escalation_note']);
        });
    }
};
