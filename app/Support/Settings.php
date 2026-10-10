<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * Every business value that used to be typed into the code lives here:
 * the definitions (label, type, built-in default) and the lookups. ICT edits
 * the values on the System settings page; the rest of the app only ever
 * calls Settings::get() or the setting() helper.
 *
 * Types: text, textarea, number, percent, money, list, timezone.
 */
class Settings
{
    private static ?array $memo = null;

    public static function groups(): array
    {
        return [
            'company' => ['Company', 'Printed on quotations, invoices and receipts.'],
            'banks' => ['Bank accounts', 'Where customers pay.'],
            'finance' => ['Money and tax', 'Defaults. Each country can override them.'],
            'documents' => ['Documents and terms', 'Due dates, validity and pick lists.'],
            'numbering' => ['Reference numbers', 'Prefix and counter for each document number.'],
            'contracts' => ['Contracts', 'Contract types and how often each machine is serviced.'],
            'certificates' => ['Certificate types', 'The three kinds of calibration certificate staff can attach to a job.'],
            'alerts' => ['Alerts and targets', 'When things count as due soon.'],
            'system' => ['Security and region', 'Password rule and time zone.'],
        ];
    }

    /** Tabs that have their own screen instead of a list of fields. */
    public static function customTab(string $tab): bool
    {
        return in_array($tab, ['banks', 'numbering', 'contracts'], true);
    }

    /** group => key => [label, type, default, help, suffix] */
    public static function definitions(): array
    {
        return [
            'company' => [
                'company_name' => ['Company name', 'text', 'AEA Limited', null, null],
                'company_kra_pin' => ['KRA PIN', 'text', '', null, null],
                'company_po_box' => ['Postal address', 'text', '', null, null],
                'company_phone' => ['Phone', 'text', '', null, null],
                'company_email' => ['Email', 'text', '', null, null],
                'signatory_name' => ['Authorised signatory', 'text', '', null, null],
                'signatory_title' => ['Signatory title', 'text', '', null, null],
            ],
            'finance' => [
                'currency' => ['Default currency', 'text', 'KES', 'Used when a country has none.', null],
                'vat_rate' => ['VAT rate', 'percent', 16, 'Used when a country has none. Old documents keep theirs.', '%'],
                'approval_threshold' => ['Manager approval from', 'money', 3000000, 'Totals from here need a Manager.', null],
            ],
            'documents' => [
                'payment_terms' => ['Payment terms', 'textarea', 'Payment due within 30 days of invoice date.', null, null],
                'invoice_due_days' => ['Invoice due after', 'number', 30, null, 'days'],
                'quotation_validity_days' => ['Quotation valid for', 'number', 30, null, 'days'],
                'job_due_days' => ['Job due after', 'number', 3, null, 'days'],
                'payment_methods' => ['Payment methods', 'list', "Bank transfer\nM-Pesa\nCheque", 'One per line. First is the default.', null],
                'nature_of_visit' => ['Nature of visit', 'list', "Planned maintenance\nService\nRepairs\nNormal customer visit", 'One per line.', null],
                'part_sources' => ['Where parts come from', 'list', "Vehicle stock\nNairobi store\nCustomer supplied\nOrdered", 'One per line.', null],
                'stock_categories' => ['Stock categories', 'list', "Spare part\nEquipment\nTest equipment\nConsumable", 'One per line.', null],
            ],
            'contracts' => [
                'contract_types' => ['Contract types', 'list', "Full service\nCall out\nMaintenance only", 'One per line.', null],
                'maintenance_frequencies' => ['Maintenance frequencies', 'list', "Monthly|1|m\nEvery 2 months|2|m\nQuarterly|3|m\nEvery 4 months|4|m\nTwice a year|6|m\nYearly|12|m", 'Name|number|unit, one per line. Unit is d, w or m.', null],
            ],
            'certificates' => [
                'cert_type_1' => ['Certificate type 1', 'text', 'Certificate type 1', null, null],
                'cert_type_2' => ['Certificate type 2', 'text', 'Certificate type 2', null, null],
                'cert_type_3' => ['Certificate type 3', 'text', 'Certificate type 3', null, null],
            ],
            'alerts' => [
                'visit_due_days' => ['Machine visit due soon', 'number', 30, null, 'days'],
                'certificate_warn_days' => ['Certificate expiring soon', 'number', 30, null, 'days'],
                'document_warn_days' => ['Staff documents expiring soon', 'number', 60, null, 'days'],
                'contract_warn_days' => ['Contracts ending soon', 'number', 60, null, 'days'],
                'stage_warn_pct' => ['First warning at', 'number', 50, 'Share of the term used.', '%'],
                'stage_urgent_pct' => ['Second warning at', 'number', 75, null, '%'],
                'response_target_hours' => ['Request response target', 'number', 24, null, 'hours'],
                'response_target_pct' => ['Response compliance goal', 'number', 90, null, '%'],
            ],
            'system' => [
                'password_min' => ['Minimum password length', 'number', 8, null, 'characters'],
                'timezone' => ['Time zone', 'timezone', 'Africa/Nairobi', null, null],
            ],
        ];
    }

    /** The names ICT gave the three certificate types, in order. */
    public static function certificateTypes(): array
    {
        $names = [];
        foreach (['cert_type_1', 'cert_type_2', 'cert_type_3'] as $k) {
            $v = trim((string) self::get($k));
            if ($v !== '') {
                $names[] = $v;
            }
        }

        return array_values(array_unique($names));
    }

    /** The contract types ICT has set up, in order. */
    public static function contractTypes(): array
    {
        return array_values(array_unique(self::get('contract_types')));
    }

    /** Maintenance frequencies as name => ['every' => 3, 'unit' => 'm']. */
    public static function maintenanceFrequencies(): array
    {
        $out = [];

        foreach (self::get('maintenance_frequencies') as $line) {
            $parts = array_map('trim', explode('|', $line));

            if (count($parts) === 3 && $parts[0] !== '' && ctype_digit($parts[1]) && (int) $parts[1] >= 1 && in_array($parts[2], ['d', 'w', 'm'], true)) {
                $out[$parts[0]] = ['every' => (int) $parts[1], 'unit' => $parts[2]];
            }
        }

        return $out;
    }

    public static function meta(string $key): ?array
    {
        foreach (self::definitions() as $keys) {
            if (isset($keys[$key])) {
                return $keys[$key];
            }
        }

        return null;
    }

    /** Values ICT has saved, key => raw text. Safe before the table exists. */
    public static function stored(): array
    {
        if (self::$memo !== null) {
            return self::$memo;
        }

        try {
            return self::$memo = Cache::rememberForever('app.settings', fn () => Setting::pluck('value', 'key')->all());
        } catch (\Throwable $e) {
            return self::$memo = [];
        }
    }

    public static function flush(): void
    {
        self::$memo = null;

        try {
            Cache::forget('app.settings');
        } catch (\Throwable $e) {
            //
        }
    }

    /** The raw text for the edit form: saved value, else the default. */
    public static function raw(string $key): string
    {
        $stored = self::stored();
        $meta = self::meta($key);

        if (array_key_exists($key, $stored) && $stored[$key] !== null) {
            return (string) $stored[$key];
        }

        return (string) ($meta[2] ?? '');
    }

    public static function isCustom(string $key): bool
    {
        $stored = self::stored();

        return array_key_exists($key, $stored) && $stored[$key] !== null;
    }

    public static function get(string $key): mixed
    {
        $meta = self::meta($key);
        $raw = self::raw($key);

        return match ($meta[1] ?? 'text') {
            'number' => (int) $raw,
            'percent', 'money' => (float) $raw,
            'list' => self::lines($raw),
            default => $raw,
        };
    }

    public static function lines(string $raw): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $raw)), fn ($l) => $l !== ''));
    }

    public static function set(string $key, ?string $value, ?int $userId = null): void
    {
        if ($value === null) {
            Setting::where('key', $key)->delete();
        } else {
            Setting::updateOrCreate(['key' => $key], ['value' => $value, 'updated_by' => $userId]);
        }

        self::flush();
    }

    // ---- Shortcuts used across the app -----------------------------------

    public static function currency(): string
    {
        return strtoupper(trim((string) self::get('currency'))) ?: 'KES';
    }

    /** VAT as a fraction, 0.16 for 16%. */
    public static function vatRate(): float
    {
        return round(self::get('vat_rate') / 100, 3);
    }

    public static function approvalThresholdMinor(): int
    {
        return (int) round(self::get('approval_threshold') * 100);
    }

    public static function timezone(): string
    {
        $tz = (string) self::get('timezone');

        return in_array($tz, timezone_identifiers_list(), true) ? $tz : 'Africa/Nairobi';
    }

    /** AEA's own details in the shape the PDFs and pages expect. */
    public static function company(): array
    {
        return [
            'name' => self::get('company_name'),
            'kra_pin' => self::get('company_kra_pin'),
            'po_box' => self::get('company_po_box'),
            'phone' => self::get('company_phone'),
            'email' => self::get('company_email'),
            'default_payment_terms' => self::get('payment_terms'),
            'signatory' => [
                'name' => self::get('signatory_name'),
                'title' => self::get('signatory_title'),
            ],
        ];
    }
}
