<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ---- Bank accounts: as many as needed, each for a currency and optionally one country
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('country_id')->nullable()->constrained('countries')->nullOnDelete();
            $table->string('currency_code', 3);
            $table->string('bank_name');
            $table->string('account_name');
            $table->string('account_number');
            $table->string('branch')->nullable();
            $table->string('swift')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        // ---- Countries: their own VAT rate and Manager approval limit (blank means use the default)
        Schema::table('countries', function (Blueprint $table) {
            $table->decimal('vat_rate', 5, 2)->nullable()->after('currency_code');
            $table->decimal('approval_threshold', 15, 2)->nullable()->after('vat_rate');
        });

        // The first seed gave every country KES. Fix the ones we know.
        foreach (['Uganda' => 'UGX', 'Tanzania' => 'TZS', 'Rwanda' => 'RWF'] as $name => $code) {
            DB::table('countries')->where('name', $name)->where('currency_code', 'KES')->update(['currency_code' => $code]);
        }

        // ---- Reference numbers: one row per series, with its own counter
        Schema::create('reference_series', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('label');
            $table->string('prefix', 10);
            $table->unsignedTinyInteger('digits')->default(4);
            $table->unsignedBigInteger('next_number')->default(1);
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });

        $saved = Schema::hasTable('settings') ? DB::table('settings')->pluck('value', 'key') : collect();
        $digits = (int) ($saved['ref_digits'] ?? 4) ?: 4;

        $series = [
            ['quotation', 'Quotations', 'QT', 'quotations', 'ref_quotation', $digits],
            ['invoice', 'Invoices', 'INV', 'invoices', 'ref_invoice', $digits],
            ['receipt', 'Receipts', 'RCP', 'payments', 'ref_receipt', $digits],
            ['work_order', 'Jobs', 'WO', 'work_orders', 'ref_work_order', $digits],
            ['request', 'Service requests', 'SR', 'service_requests', 'ref_request', $digits],
            ['customer', 'Customers', 'CUS', 'customers', null, $digits],
            ['contract', 'Contracts', 'CT', 'contracts', null, $digits],
            ['stock_movement', 'Stock movements', 'MOV', 'stock_movements', null, 5],
        ];

        foreach ($series as [$key, $label, $prefix, $tbl, $settingKey, $d]) {
            DB::table('reference_series')->insert([
                'key' => $key,
                'label' => $label,
                'prefix' => strtoupper((string) ($settingKey ? ($saved[$settingKey] ?? $prefix) : $prefix)),
                'digits' => $d,
                'next_number' => Schema::hasTable($tbl) ? ((int) DB::table($tbl)->max('id') + 1) : 1,
                'is_system' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // ---- Carry the single bank account from the old settings across, then retire those keys
        if (! empty($saved['bank_account_number'])) {
            DB::table('bank_accounts')->insert([
                'country_id' => null,
                'currency_code' => strtoupper((string) ($saved['currency'] ?? 'KES')),
                'bank_name' => $saved['bank_name'] ?? '',
                'account_name' => $saved['bank_account_name'] ?? 'AEA Limited',
                'account_number' => $saved['bank_account_number'],
                'branch' => $saved['bank_branch'] ?? null,
                'swift' => $saved['bank_swift'] ?? null,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (Schema::hasTable('settings')) {
            DB::table('settings')->where(function ($q) {
                $q->where('key', 'like', 'bank_%')->orWhere('key', 'like', 'ref_%')->orWhere('key', 'currencies');
            })->delete();
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('reference_series');
        Schema::table('countries', function (Blueprint $table) {
            $table->dropColumn(['vat_rate', 'approval_threshold']);
        });
        Schema::dropIfExists('bank_accounts');
    }
};
