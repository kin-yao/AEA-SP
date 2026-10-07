<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ReferenceSeries extends Model
{
    protected $table = 'reference_series';

    protected $fillable = ['key', 'label', 'prefix', 'digits', 'next_number', 'is_system'];

    protected $casts = ['is_system' => 'boolean'];

    /** Which table already holds numbers from a built-in series, so a clash is skipped. */
    public const TABLES = [
        'quotation' => 'quotations',
        'invoice' => 'invoices',
        'receipt' => 'payments',
        'work_order' => 'work_orders',
        'request' => 'service_requests',
        'customer' => 'customers',
        'contract' => 'contracts',
        'stock_movement' => 'stock_movements',
        'lpo' => 'documents',
    ];

    /** For example INV-0042, without using it up. */
    public function format(?int $number = null): string
    {
        return $this->prefix.'-'.str_pad((string) ($number ?? $this->next_number), max(1, (int) $this->digits), '0', STR_PAD_LEFT);
    }

    /** Hand out the next number in a series and move the counter on. */
    public static function next(string $key): string
    {
        return DB::transaction(function () use ($key) {
            $series = static::where('key', $key)->lockForUpdate()->first();

            if (! $series) {
                throw new \RuntimeException("No reference series called {$key}. Add it under ICT, System settings, Reference numbers.");
            }

            $table = self::TABLES[$key] ?? null;
            $n = (int) $series->next_number;

            while ($table && Schema::hasTable($table) && DB::table($table)->where('reference', $series->format($n))->exists()) {
                $n++;
            }

            $series->update(['next_number' => $n + 1]);

            return $series->format($n);
        });
    }
}
