<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class Currency extends Model
{
    protected $fillable = ['code', 'name'];

    /** Names for the common African and trade currencies, used to label the first list. */
    public const KNOWN = [
        'KES' => 'Kenya shilling', 'UGX' => 'Uganda shilling', 'TZS' => 'Tanzania shilling', 'RWF' => 'Rwanda franc',
        'BIF' => 'Burundi franc', 'ETB' => 'Ethiopian birr', 'SSP' => 'South Sudanese pound', 'SOS' => 'Somali shilling',
        'CDF' => 'Congolese franc', 'ZMW' => 'Zambian kwacha', 'MWK' => 'Malawian kwacha', 'MZN' => 'Mozambican metical',
        'ZAR' => 'South African rand', 'NGN' => 'Nigerian naira', 'GHS' => 'Ghanaian cedi', 'XOF' => 'West African CFA franc',
        'XAF' => 'Central African CFA franc', 'EGP' => 'Egyptian pound', 'MAD' => 'Moroccan dirham', 'USD' => 'US dollar',
        'EUR' => 'Euro', 'GBP' => 'British pound', 'AED' => 'UAE dirham', 'INR' => 'Indian rupee', 'CNY' => 'Chinese yuan',
    ];

    /** Tables that keep a currency on each record. */
    public const RECORD_TABLES = ['quotations', 'invoices', 'payments', 'contracts', 'work_orders'];

    /** Make sure every currency already in use (countries, bank accounts, the default) is on the list. */
    public static function sync(): void
    {
        if (! Schema::hasTable('currencies')) {
            return;
        }

        $codes = collect();
        if (Schema::hasTable('countries')) {
            $codes = $codes->merge(DB::table('countries')->pluck('currency_code'));
        }
        if (Schema::hasTable('bank_accounts')) {
            $codes = $codes->merge(DB::table('bank_accounts')->pluck('currency_code'));
        }
        $codes->push(\App\Support\Settings::currency());

        $have = DB::table('currencies')->pluck('code')->all();

        foreach ($codes->map(fn ($c) => strtoupper(trim((string) $c)))->filter()->unique() as $code) {
            if (! in_array($code, $have, true)) {
                DB::table('currencies')->insert(['code' => $code, 'name' => self::KNOWN[$code] ?? $code, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    /** Every code people can pick from. */
    public static function codes(): array
    {
        static::sync();

        return static::orderBy('code')->pluck('code')->all();
    }

    /** What still depends on a currency: countries, bank accounts, the default, and saved documents. */
    public static function usage(string $code): array
    {
        $records = 0;
        foreach (self::RECORD_TABLES as $t) {
            if (Schema::hasTable($t)) {
                $records += DB::table($t)->where('currency_code', $code)->count();
            }
        }

        return [
            'countries' => Country::where('currency_code', $code)->orderBy('name')->pluck('name')->all(),
            'banks' => BankAccount::where('currency_code', $code)->count(),
            'default' => \App\Support\Settings::currency() === $code,
            'records' => $records,
        ];
    }
}
