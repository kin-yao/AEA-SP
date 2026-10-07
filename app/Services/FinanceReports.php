<?php

namespace App\Services;

use App\Models\Invoice;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Numbers behind the Finance Reports page.
 *
 * Every figure follows the filters (customer, country, dates). Dates follow
 * the invoice issue date. Drafts are never counted as revenue or as money
 * owed, because nothing has been sent to the customer yet. Country and
 * branch come from the customer's branch.
 */
class FinanceReports
{
    public const STATUS_ORDER = ['Draft', 'Unpaid', 'Part paid', 'Paid', 'Overdue'];
    public const STATUS_COLORS = ['Draft' => '#9ca3af', 'Unpaid' => '#475569', 'Part paid' => '#e0ac2e', 'Paid' => '#15803d', 'Overdue' => '#d62828'];

    public static function filters(array $raw): array
    {
        return [
            'from' => ! empty($raw['from']) ? Carbon::parse($raw['from'])->startOfDay() : null,
            'to' => ! empty($raw['to']) ? Carbon::parse($raw['to'])->endOfDay() : null,
            'customer' => ! empty($raw['customer']) ? (int) $raw['customer'] : null,
            'country' => ! empty($raw['country']) ? (string) $raw['country'] : null,
        ];
    }

    public static function label(array $f): string
    {
        $parts = [];
        if ($f['from'] || $f['to']) {
            $parts[] = ($f['from'] ? $f['from']->format('d M Y') : 'start').' to '.($f['to'] ? $f['to']->format('d M Y') : 'today');
        } else {
            $parts[] = 'All dates';
        }
        if ($f['customer']) {
            $parts[] = \App\Models\Customer::find($f['customer'])?->name ?? 'one customer';
        }
        if ($f['country']) {
            $parts[] = $f['country'];
        }

        return implode(' | ', $parts);
    }

    public static function invoices(array $f): Collection
    {
        $q = Invoice::with(['customer.branch.country', 'workOrder.technician']);

        if ($f['from']) {
            $q->whereDate('issued_at', '>=', $f['from']->toDateString());
        }
        if ($f['to']) {
            $q->whereDate('issued_at', '<=', $f['to']->toDateString());
        }
        if ($f['customer']) {
            $q->where('customer_id', $f['customer']);
        }

        return $q->get()
            ->filter(fn ($i) => $f['country'] === null || $i->customer?->branch?->country?->name === $f['country'])
            ->values();
    }

    public static function isOverdue(Invoice $i): bool
    {
        return in_array($i->status, ['Unpaid', 'Part paid'], true) && $i->due_at->lt(today());
    }

    /** Status as the Finance team reads it: past-due money shows as Overdue. */
    public static function shownStatus(Invoice $i): string
    {
        return self::isOverdue($i) ? 'Overdue' : $i->status;
    }

    /** VAT inside an invoice total (totals include VAT). */
    public static function vatOf(Invoice $i): int
    {
        $rate = (float) $i->vat_rate;

        return $rate > 0 ? (int) ($i->amount_minor - round($i->amount_minor / (1 + $rate))) : 0;
    }

    public static function short(int $minor): string
    {
        return ServiceAdminReports::short($minor);
    }

    public static function build(array $raw): array
    {
        $f = self::filters($raw);
        $all = self::invoices($f);

        $issued = $all->where('status', '!=', 'Draft');
        $owing = $all->whereIn('status', ['Unpaid', 'Part paid']);
        $overdue = $owing->filter(fn ($i) => self::isOverdue($i));

        // ---- Tiles
        $rangeSet = $f['from'] || $f['to'];
        $revenueSet = $rangeSet ? $issued : $issued->filter(fn ($i) => $i->issued_at->isSameMonth(today()));
        $kpis = [
            'revenueLabel' => $rangeSet ? 'Revenue, chosen dates' : 'Revenue, this month',
            'revenueMinor' => (int) $revenueSet->sum('amount_minor'),
            'revenueCount' => $revenueSet->count(),
            'outstandingMinor' => (int) $owing->sum(fn ($i) => $i->balanceMinor()),
            'outstandingCount' => $owing->count(),
            'overdueMinor' => (int) $overdue->sum(fn ($i) => $i->balanceMinor()),
            'overdueCount' => $overdue->count(),
            'vatMinor' => (int) $revenueSet->sum(fn ($i) => self::vatOf($i)),
        ];

        // ---- Revenue by customer
        $customerRows = $issued->groupBy(fn ($i) => $i->customer?->name ?? 'Unknown')
            ->map(fn ($set, $name) => ['name' => $name, 'minor' => (int) $set->sum('amount_minor'), 'count' => $set->count()])
            ->sortByDesc('minor')->values();

        // ---- Revenue by branch
        $branchRows = $issued->groupBy(fn ($i) => $i->customer?->branch?->name ?? 'Unassigned')
            ->map(fn ($set, $name) => [
                'label' => (string) $name,
                'value' => (int) $set->sum('amount_minor'),
                'valueLabel' => self::short((int) $set->sum('amount_minor')),
                'color' => '#8f1d1d',
            ])->sortByDesc('value')->values()->all();

        // ---- Revenue by technician
        $techRows = $issued->groupBy(fn ($i) => $i->workOrder?->technician?->name ?? 'No technician on the job')
            ->map(fn ($set, $name) => ['name' => $name, 'minor' => (int) $set->sum('amount_minor'), 'count' => $set->count()])
            ->sortByDesc('minor')->values();

        // ---- Invoices by status
        $counts = $all->groupBy(fn ($i) => self::shownStatus($i))->map->count();
        $statusMix = [];
        foreach (self::STATUS_ORDER as $s) {
            if (($counts[$s] ?? 0) > 0) {
                $statusMix[] = ['label' => $s, 'value' => (int) $counts[$s], 'color' => self::STATUS_COLORS[$s]];
            }
        }

        // ---- Outstanding and overdue invoices, oldest due date first
        $owingRows = $owing->sortBy(fn ($i) => $i->due_at->timestamp)->values();

        // ---- Aging
        $buckets = [
            ['label' => 'Not yet due', 'min' => null, 'max' => 0, 'color' => '#15803d'],
            ['label' => '1 to 30 days late', 'min' => 1, 'max' => 30, 'color' => '#e0ac2e'],
            ['label' => '31 to 60 days late', 'min' => 31, 'max' => 60, 'color' => '#c2410c'],
            ['label' => '61 to 90 days late', 'min' => 61, 'max' => 90, 'color' => '#d62828'],
            ['label' => 'Over 90 days late', 'min' => 91, 'max' => null, 'color' => '#8f1d1d'],
        ];
        $ageing = [];
        foreach ($buckets as $b) {
            $set = $owing->filter(function ($i) use ($b) {
                $late = (int) today()->diffInDays($i->due_at, false) * -1;

                return ($b['min'] === null ? $late <= 0 : $late >= $b['min']) && ($b['max'] === null || $late <= $b['max']);
            });
            $sum = (int) $set->sum(fn ($i) => $i->balanceMinor());
            $ageing[] = ['label' => $b['label'], 'value' => $sum, 'valueLabel' => self::short($sum), 'color' => $b['color'], 'count' => $set->count()];
        }
        $ageingMix = array_values(array_filter($ageing, fn ($a) => $a['value'] > 0));

        // ---- VAT summary, one row per country
        $vatRows = $issued->groupBy(fn ($i) => ($i->customer?->branch?->country?->name ?? 'Unassigned').'|'.$i->currency_code)
            ->map(function ($set, $key) {
                [$country, $currency] = explode('|', $key);
                $total = (int) $set->sum('amount_minor');
                $vat = (int) $set->sum(fn ($i) => self::vatOf($i));

                return ['period' => $country.' operations', 'taxable' => $total - $vat, 'vat' => $vat, 'currency' => $currency, 'count' => $set->count()];
            })->sortByDesc('taxable')->values();

        return [
            'label' => self::label($f),
            'kpis' => $kpis,
            'customerRows' => $customerRows,
            'branchRows' => $branchRows,
            'techRows' => $techRows,
            'statusMix' => $statusMix,
            'owingRows' => $owingRows,
            'ageing' => $ageing,
            'ageingMix' => $ageingMix,
            'vatRows' => $vatRows,
            'invoiceCount' => $all->count(),
        ];
    }

    /* ------------------------------------------------------------ csv */

    public static function csvSections(array $d, ?string $only = null): array
    {
        $m = fn ($minor) => number_format($minor / 100, 2, '.', '');
        $k = $d['kpis'];

        $all = [
            'summary' => ['Summary', ['Measure', 'Value'], [
                [$k['revenueLabel'].' ('.currency().')', $m($k['revenueMinor'])],
                ['Outstanding ('.currency().')', $m($k['outstandingMinor'])],
                ['Overdue ('.currency().')', $m($k['overdueMinor'])],
                ['VAT collected ('.currency().')', $m($k['vatMinor'])],
            ]],
            'customers' => ['Revenue by customer', ['Customer', 'Invoices', 'Revenue ('.currency().')'], $d['customerRows']->map(fn ($r) => [$r['name'], $r['count'], $m($r['minor'])])->all()],
            'branches' => ['Revenue by branch', ['Branch', 'Revenue ('.currency().')'], array_map(fn ($r) => [$r['label'], $m($r['value'])], $d['branchRows'])],
            'technicians' => ['Revenue by technician', ['Technician', 'Invoices', 'Revenue ('.currency().')'], $d['techRows']->map(fn ($r) => [$r['name'], $r['count'], $m($r['minor'])])->all()],
            'status' => ['Invoices by status', ['Status', 'Invoices'], array_map(fn ($r) => [$r['label'], $r['value']], $d['statusMix'])],
            'owing' => ['Outstanding and overdue invoices', ['Invoice', 'Customer', 'Due', 'Amount ('.currency().')', 'Paid ('.currency().')', 'Balance ('.currency().')', 'Status'], $d['owingRows']->map(fn ($i) => [
                $i->reference, $i->customer?->name, $i->due_at->format('Y-m-d'), $m($i->amount_minor), $m($i->paid_minor), $m($i->balanceMinor()), self::shownStatus($i),
            ])->all()],
            'ageing' => ['Aging of money still to collect', ['Age', 'Invoices', 'Balance ('.currency().')'], array_map(fn ($a) => [$a['label'], $a['count'], $m($a['value'])], $d['ageing'])],
            'vat' => ['VAT summary', ['Period', 'Taxable revenue', 'VAT', 'Currency'], $d['vatRows']->map(fn ($r) => [$r['period'], $m($r['taxable']), $m($r['vat']), $r['currency']])->all()],
        ];

        return $only === null ? $all : array_intersect_key($all, array_flip(explode(',', $only)));
    }
}
