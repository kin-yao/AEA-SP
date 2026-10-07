<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Contract;
use App\Models\Document;
use App\Models\InventoryItem;
use App\Models\Invoice;
use App\Models\Quotation;
use App\Models\ServiceRequest;
use App\Models\StockMovement;
use App\Models\TechnicianDocument;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Numbers behind the Service Admin's Reports page.
 *
 * Filters (dates, customer, country, technician) apply to every tab where
 * they make sense. Dates follow each record's own date: requests and
 * quotations by created date, jobs by due date, invoices by issue date.
 * "Live" figures (pipeline, LPOs awaited, overdue jobs, reorder list,
 * reports to post, flagged documents) ignore the dates, because they are
 * things to act on today.
 */
class ServiceAdminReports
{
    public const DOC_WARN_DAYS = 60;

    public const REQUEST_ORDER = ['Open', 'Assigned', 'Quoted', 'Converted', 'Declined'];
    public const REQUEST_COLORS = ['Open' => '#e0ac2e', 'Assigned' => '#475569', 'Quoted' => '#1f2937', 'Converted' => '#15803d', 'Declined' => '#d62828'];

    public const QUOTE_ORDER = ['Awaiting Supervisor', 'Awaiting Manager', 'Approved', 'Accepted', 'Converted', 'Sent back'];
    public const QUOTE_COLORS = ['Awaiting Supervisor' => '#e0ac2e', 'Awaiting Manager' => '#c2410c', 'Approved' => '#475569', 'Accepted' => '#15803d', 'Converted' => '#0f766e', 'Sent back' => '#d62828'];

    public const REPORT_ORDER = ['Draft', 'Awaiting review', 'Checked, ready to post', 'Released'];
    public const REPORT_COLORS = ['Draft' => '#9ca3af', 'Awaiting review' => '#e0ac2e', 'Checked, ready to post' => '#475569', 'Released' => '#15803d'];

    public const CATEGORY_ORDER = ['Spare part', 'Equipment', 'Test equipment', 'Consumable'];
    public const PALETTE = ['#8f1d1d', '#e0ac2e', '#1f2937', '#15803d', '#475569', '#d62828', '#0f766e', '#9ca3af'];

    /* ------------------------------------------------------------ filters */

    /** Past this many jobs, "all dates" is too heavy to build on screen, so it shows the last 12 months. */
    public const AUTO_NARROW_AFTER = 5000;

    public static function filters(array $raw): array
    {
        $from = ! empty($raw['from']) ? Carbon::parse($raw['from'])->startOfDay() : null;
        $to = ! empty($raw['to']) ? Carbon::parse($raw['to'])->endOfDay() : null;
        $auto = false;
        if (! $from && ! $to && \App\Models\WorkOrder::count() > self::AUTO_NARROW_AFTER) {
            $from = now()->subMonths(12)->startOfDay();
            $auto = true;
        }

        return [
            'auto' => $auto,
            'from' => $from,
            'to' => $to,
            'customer' => ! empty($raw['customer']) ? (int) $raw['customer'] : null,
            'country' => ! empty($raw['country']) ? (string) $raw['country'] : null,
            'technician' => ! empty($raw['technician']) ? (int) $raw['technician'] : null,
        ];
    }

    protected static function live(array $f): array
    {
        return array_merge($f, ['from' => null, 'to' => null]);
    }

    protected static function countryOk(array $f, ?Branch $branch): bool
    {
        return $f['country'] === null || ($branch?->country?->name === $f['country']);
    }

    protected static function between($query, array $f, string $column)
    {
        if ($f['from']) {
            $query->whereDate($column, '>=', $f['from']->toDateString());
        }
        if ($f['to']) {
            $query->whereDate($column, '<=', $f['to']->toDateString());
        }

        return $query;
    }

    public static function label(array $f): string
    {
        $parts = [];
        if (! empty($f['auto'])) {
            $parts[] = 'Last 12 months (lots of records, pick dates to see more)';
        } elseif ($f['from'] || $f['to']) {
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
        if ($f['technician']) {
            $parts[] = User::find($f['technician'])?->name ?? 'one technician';
        }

        return implode(' | ', $parts);
    }

    /* ------------------------------------------------------------ queries */

    public static function requests(array $f): Collection
    {
        $q = ServiceRequest::with(['customer.branch.country', 'technician.branch.country', 'workOrder']);
        self::between($q, $f, 'created_at');
        if ($f['customer']) {
            $q->where('customer_id', $f['customer']);
        }
        if ($f['technician']) {
            $q->where('assigned_technician_id', $f['technician']);
        }

        return $q->get()->filter(fn ($r) => self::countryOk($f, $r->technician?->branch ?? $r->customer?->branch))->values();
    }

    public static function quotations(array $f): Collection
    {
        $q = Quotation::with(['items', 'customer.branch.country']);
        self::between($q, $f, 'created_at');
        if ($f['customer']) {
            $q->where('customer_id', $f['customer']);
        }

        return $q->get()->filter(fn ($x) => self::countryOk($f, $x->customer?->branch))->values();
    }

    public static function jobRows(array $f): Collection
    {
        $rows = ManagerReports::jobHistory([
            'from' => $f['from']?->toDateString(),
            'to' => $f['to']?->toDateString(),
            'customer' => $f['customer'],
            'country' => $f['country'],
        ]);

        if ($f['technician']) {
            $rows = $rows->filter(fn ($r) => (int) $r['job']->assigned_technician_id === $f['technician']);
        }

        return $rows->values();
    }

    public static function invoices(array $f): Collection
    {
        $q = Invoice::with(['customer.branch.country', 'workOrder.technician.branch.country']);
        self::between($q, $f, 'issued_at');
        if ($f['customer']) {
            $q->where('customer_id', $f['customer']);
        }
        if ($f['technician']) {
            $q->whereHas('workOrder', fn ($w) => $w->where('assigned_technician_id', $f['technician']));
        }

        return $q->get()->filter(fn ($i) => self::countryOk($f, $i->workOrder?->technician?->branch ?? $i->customer?->branch))->values();
    }

    public static function reports(array $f): Collection
    {
        $q = Document::where('type', Document::TYPE_REPORT)->with(['workOrder.technician.branch.country', 'customer.branch.country']);
        self::between($q, $f, 'created_at');
        if ($f['customer']) {
            $q->where('customer_id', $f['customer']);
        }
        if ($f['technician']) {
            $q->whereHas('workOrder', fn ($w) => $w->where('assigned_technician_id', $f['technician']));
        }

        return $q->get()->filter(fn ($d) => self::countryOk($f, $d->workOrder?->technician?->branch ?? $d->customer?->branch))->values();
    }

    public static function techDocs(array $f): Collection
    {
        $q = TechnicianDocument::with('technician.branch.country');
        if ($f['technician']) {
            $q->where('technician_id', $f['technician']);
        }

        return $q->get()
            ->filter(fn ($d) => $d->technician && self::countryOk($f, $d->technician->branch))
            ->filter(fn ($d) => $d->expiresAt()->lte(today()->addDays((int) setting('document_warn_days'))))
            ->sortBy(fn ($d) => $d->expiresAt()->timestamp)
            ->values();
    }

    public static function stock(array $f): Collection
    {
        return InventoryItem::with('branch.country')->orderBy('name')->get()
            ->filter(fn ($i) => $f['country'] === null || $i->branch?->country?->name === $f['country'])
            ->values();
    }

    public static function stockUsed(array $f): Collection
    {
        $from = $f['from'] ?? now()->startOfMonth();
        $to = $f['to'] ?? now()->endOfDay();

        $q = StockMovement::where('type', 'Issue')
            ->whereBetween('occurred_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->with('inventoryItem.branch.country');
        if ($f['technician']) {
            $q->where('recorded_by', $f['technician']);
        }

        return $q->get()
            ->filter(fn ($m) => $m->inventoryItem && ($f['country'] === null || $m->inventoryItem->branch?->country?->name === $f['country']))
            ->groupBy('inventory_item_id')
            ->map(fn ($g) => ['item' => $g->first()->inventoryItem, 'qty' => abs((int) $g->sum('quantity_delta'))])
            ->sortByDesc('qty')
            ->values();
    }

    public static function contracts(array $f): Collection
    {
        $q = Contract::with('customer.branch.country')->where('status', 'Active');
        if ($f['customer']) {
            $q->where('customer_id', $f['customer']);
        }

        return $q->get()
            ->filter(fn ($c) => self::countryOk($f, $c->customer?->branch))
            ->sortBy('ends_at')
            ->values();
    }

    /* ------------------------------------------------------------ helpers */

    /** @return array<int, array{label:string,value:int,color:string}> */
    protected static function mix(Collection $counts, array $order, array $colors): array
    {
        $out = [];
        foreach ($order as $label) {
            if (($counts[$label] ?? 0) > 0) {
                $out[] = ['label' => $label, 'value' => (int) $counts[$label], 'color' => $colors[$label]];
            }
        }
        foreach ($counts as $label => $n) {
            if (! in_array($label, $order, true) && $n > 0) {
                $out[] = ['label' => (string) $label, 'value' => (int) $n, 'color' => '#9ca3af'];
            }
        }

        return $out;
    }

    /** "3.6M", "245k" or "800" for chart labels. */
    public static function short(int $minor): string
    {
        $kes = $minor / 100;
        if ($kes >= 1_000_000) {
            return rtrim(rtrim(number_format($kes / 1_000_000, 1), '0'), '.').'M';
        }
        if ($kes >= 1_000) {
            return rtrim(rtrim(number_format($kes / 1_000, 1), '0'), '.').'k';
        }

        return (string) round($kes);
    }

    protected static function days(?Carbon $from): int
    {
        return $from ? (int) $from->copy()->startOfDay()->diffInDays(today()) : 0;
    }

    /* ------------------------------------------------------------ build */

    public static function build(array $raw): array
    {
        $f = self::filters($raw);
        $live = self::live($f);

        $requests = self::requests($f);
        $quotations = self::quotations($f);
        $jobs = self::jobRows($f);
        $invoices = self::invoices($f);
        $reports = self::reports($f);
        $techDocs = self::techDocs($f);
        $stock = self::stock($f);
        $contracts = self::contracts($f);

        $quotesLive = self::quotations($live);
        $jobsLive = self::jobRows($live);

        // ---- KPIs
        $converted = $requests->filter(fn ($r) => $r->workOrder !== null || $r->status === 'Converted')->count();
        $inFlight = $quotesLive->whereIn('status', ['Awaiting Supervisor', 'Awaiting Manager', 'Approved']);
        $lpoWait = $quotesLive->where('status', 'Approved')->filter(fn ($q) => $q->lpo_status !== 'On file');
        $reorder = $stock->filter(fn ($i) => $i->reorder_level > 0 && $i->isBelowReorderLevel());
        $toPost = self::reports($live)->where('status', 'Checked, ready to post')->count();

        $kpis = [
            'requests' => $requests->count(),
            'requestsOpen' => $requests->where('status', 'Open')->count(),
            'converted' => $converted,
            'winRate' => $requests->count() > 0 ? (int) round($converted / $requests->count() * 100) : null,
            'pipelineMinor' => (int) $inFlight->sum(fn ($q) => $q->totalMinor()),
            'pipelineCount' => $inFlight->count(),
            'lpoAwaited' => $lpoWait->count(),
            'lpoAwaitedMinor' => (int) $lpoWait->sum(fn ($q) => $q->totalMinor()),
            'overdueJobs' => $jobsLive->where('status', 'Overdue')->count(),
            'openJobs' => $jobsLive->filter(fn ($r) => $r['job']->status !== 'Closed')->count(),
            'reorder' => $reorder->count(),
            'stockItems' => $stock->count(),
        ];

        // ---- Overview
        $nature = $jobs->groupBy(fn ($r) => $r['job']->nature_of_visit ?: 'Not set')->map->count()->sortDesc();
        $natureRows = [];
        foreach ($nature->take(7) as $label => $n) {
            $natureRows[] = ['label' => (string) $label, 'value' => $n, 'valueLabel' => (string) $n, 'color' => self::PALETTE[count($natureRows) % count(self::PALETTE)]];
        }
        if ($nature->count() > 7) {
            $natureRows[] = ['label' => 'Other', 'value' => $nature->slice(7)->sum(), 'valueLabel' => (string) $nature->slice(7)->sum(), 'color' => '#9ca3af'];
        }

        $byBranch = $jobs->groupBy(fn ($r) => $r['branch'] === '-' ? 'Unassigned' : $r['branch'])->map->count()->sortDesc();
        $branchRows = [];
        foreach ($byBranch as $label => $n) {
            $branchRows[] = ['label' => (string) $label, 'value' => $n, 'valueLabel' => (string) $n, 'color' => '#8f1d1d'];
        }

        $approved = $quotations->whereIn('status', ['Approved', 'Accepted', 'Converted'])->count();
        $issued = $invoices->where('status', '!=', 'Draft')->count();
        $closed = $jobs->filter(fn ($r) => $r['job']->status === 'Closed')->count();
        $pipeline = [
            ['label' => 'Requests logged', 'value' => $requests->count(), 'color' => '#1f2937'],
            ['label' => 'Quotations raised', 'value' => $quotations->count(), 'color' => '#475569'],
            ['label' => 'Quotations approved', 'value' => $approved, 'color' => '#e0ac2e'],
            ['label' => 'Jobs scheduled', 'value' => $jobs->count(), 'color' => '#8f1d1d'],
            ['label' => 'Jobs closed', 'value' => $closed, 'color' => '#15803d'],
            ['label' => 'Invoices issued', 'value' => $issued, 'color' => '#0f766e'],
        ];
        foreach ($pipeline as &$p) {
            $p['valueLabel'] = (string) $p['value'];
        }
        unset($p);

        // ---- Quotations
        $quoteCounts = $quotations->groupBy('status')->map->count();
        $valueRows = [];
        foreach (self::QUOTE_ORDER as $status) {
            $set = $quotations->where('status', $status);
            if ($set->isNotEmpty()) {
                $sum = (int) $set->sum(fn ($q) => $q->totalMinor());
                $valueRows[] = ['label' => $status, 'value' => $sum, 'valueLabel' => self::short($sum), 'color' => self::QUOTE_COLORS[$status]];
            }
        }
        $lpoPool = $quotations->whereIn('status', ['Approved', 'Accepted', 'Converted']);
        $lpoOn = $lpoPool->where('lpo_status', 'On file')->count();
        $lpoMix = array_values(array_filter([
            ['label' => 'On file', 'value' => $lpoOn, 'color' => '#15803d'],
            ['label' => 'Awaited from customer', 'value' => $lpoPool->count() - $lpoOn, 'color' => '#d62828'],
        ], fn ($r) => $r['value'] > 0));

        $needs = $quotesLive->map(function ($q) {
            return match (true) {
                $q->status === 'Awaiting Supervisor' => ['q' => $q, 'what' => 'Waiting for Supervisor approval', 'days' => self::days($q->created_at)],
                $q->status === 'Awaiting Manager' => ['q' => $q, 'what' => 'Waiting for Manager approval', 'days' => self::days($q->created_at)],
                $q->status === 'Approved' && $q->lpo_status !== 'On file' => ['q' => $q, 'what' => 'Waiting for the customer LPO', 'days' => self::days($q->updated_at)],
                $q->status === 'Sent back' => ['q' => $q, 'what' => 'Sent back, needs your changes', 'days' => self::days($q->updated_at)],
                default => null,
            };
        })->filter()->sortByDesc('days')->values();

        $wonMinor = (int) $quotations->whereIn('status', ['Accepted', 'Converted'])->sum(fn ($q) => $q->totalMinor());
        $quoteTiles = [
            'raised' => $quotations->count(),
            'wonMinor' => $wonMinor,
            'lpoRate' => $lpoPool->count() > 0 ? (int) round($lpoOn / $lpoPool->count() * 100) : null,
        ];

        // ---- Finance
        $paid = $invoices->where('status', 'Paid');
        $draftPart = $invoices->whereIn('status', ['Draft', 'Part paid']);
        $owing = $invoices->whereIn('status', ['Unpaid', 'Part paid']);
        $overdue = $owing->filter(fn ($i) => $i->due_at->lt(today()));
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

        $finance = [
            'paidCount' => $paid->count(),
            'paidMinor' => (int) $paid->sum('amount_minor'),
            'draftPartCount' => $draftPart->count(),
            'draftPartMinor' => (int) $draftPart->sum('amount_minor'),
            'overdueCount' => $overdue->count(),
            'overdueMinor' => (int) $overdue->sum(fn ($i) => $i->balanceMinor()),
            'owingMinor' => (int) $owing->sum(fn ($i) => $i->balanceMinor()),
            'owingCount' => $owing->count(),
        ];

        $techs = User::role('Technician')->with('branch.country')->orderBy('name')->get()
            ->filter(fn ($t) => (! $f['technician'] || $t->id === $f['technician']) && self::countryOk($f, $t->branch));
        $revenueBy = $invoices->where('status', '!=', 'Draft')->groupBy(fn ($i) => $i->workOrder?->assigned_technician_id);
        $periodJobsBy = $jobs->groupBy(fn ($r) => $r['job']->assigned_technician_id);
        $liveJobsBy = $jobsLive->groupBy(fn ($r) => $r['job']->assigned_technician_id);
        $techRows = $techs->map(function (User $t) use ($revenueBy, $periodJobsBy, $liveJobsBy) {
            $mine = $periodJobsBy->get($t->id, collect());
            $open = $liveJobsBy->get($t->id, collect());

            return [
                'user' => $t,
                'location' => collect([$t->branch?->name, $t->branch?->country?->name])->filter()->unique()->implode(', '),
                'closed' => $mine->filter(fn ($r) => $r['job']->status === 'Closed')->count(),
                'open' => $open->filter(fn ($r) => $r['job']->status !== 'Closed')->count(),
                'overdue' => $open->where('status', 'Overdue')->count(),
                'revenueMinor' => (int) $revenueBy->get($t->id, collect())->sum('amount_minor'),
            ];
        })->sortByDesc('revenueMinor')->values();

        $revenueRows = $techRows->map(fn ($t) => [
            'label' => $t['user']->name, 'value' => $t['revenueMinor'], 'valueLabel' => self::short($t['revenueMinor']), 'color' => '#8f1d1d',
        ])->all();

        $workloadRows = $techRows->sortByDesc('open')->map(fn ($t) => [
            'label' => $t['user']->name, 'value' => $t['open'], 'valueLabel' => (string) $t['open'],
            'color' => $t['overdue'] > 0 ? '#d62828' : '#475569',
        ])->values()->all();

        // ---- Inventory
        $lowRows = $reorder->sortBy(fn ($i) => $i->quantity / max($i->reorder_level, 1))->take(10)->map(fn ($i) => [
            'label' => $i->name, 'value' => (int) $i->quantity, 'marker' => (int) $i->reorder_level,
            'valueLabel' => $i->quantity.' / '.$i->reorder_level,
            'color' => $i->quantity === 0 ? '#d62828' : '#e0ac2e',
        ])->values()->all();

        $catRows = [];
        foreach (setting('stock_categories') as $cat) {
            $units = (int) $stock->where('category', $cat)->sum('quantity');
            $catRows[] = ['label' => $cat, 'value' => $units, 'valueLabel' => (string) $units, 'color' => self::PALETTE[count($catRows)]];
        }

        $used = self::stockUsed($f);
        $usedRows = $used->take(8)->map(fn ($u) => [
            'label' => $u['item']->name, 'value' => $u['qty'],
            'valueLabel' => $u['qty'].' '.Str::plural(strtolower($u['item']->unit), $u['qty']),
            'color' => '#8f1d1d',
        ])->all();

        $inventory = [
            'valueMinor' => (int) $stock->sum(fn ($i) => $i->quantity * (int) $i->cost_minor),
            'units' => (int) $stock->sum('quantity'),
            'usedLabel' => $f['from'] || $f['to'] ? 'in the chosen dates' : 'this month',
        ];

        // ---- Contracts
        $contractRows = $contracts->map(function ($c) {
            $left = $c->visitsRemaining();
            $daysToEnd = (int) today()->diffInDays($c->ends_at, false);

            return [
                'c' => $c, 'left' => $left, 'daysToEnd' => $daysToEnd,
                'state' => $daysToEnd < 0 ? 'Expired' : ($daysToEnd <= (int) setting('contract_warn_days') ? 'Expiring soon' : 'Active'),
            ];
        })->values();

        $contractChart = $contractRows->sortBy('left')->take(10)->map(fn ($r) => [
            'label' => trim(($r['c']->customer?->name ?? '').' '.$r['c']->reference), 'value' => $r['left'], 'valueLabel' => (string) $r['left'],
            'color' => $r['left'] === 0 ? '#d62828' : ($r['left'] <= 3 ? '#e0ac2e' : '#15803d'),
        ])->values()->all();

        return [
            'filters' => $f,
            'label' => self::label($f),
            'kpis' => $kpis,
            'requestMix' => self::mix($requests->groupBy('status')->map->count(), self::REQUEST_ORDER, self::REQUEST_COLORS),
            'natureRows' => $natureRows,
            'branchRows' => $branchRows,
            'pipelineRows' => $pipeline,
            'quoteMix' => self::mix($quoteCounts, self::QUOTE_ORDER, self::QUOTE_COLORS),
            'valueRows' => $valueRows,
            'lpoMix' => $lpoMix,
            'needs' => $needs,
            'quoteTiles' => $quoteTiles,
            'finance' => $finance,
            'ageing' => $ageing,
            'techRows' => $techRows,
            'revenueRows' => $revenueRows,
            'workloadRows' => $workloadRows,
            'reportMix' => self::mix($reports->groupBy('status')->map->count(), self::REPORT_ORDER, self::REPORT_COLORS),
            'toPost' => $toPost,
            'techDocs' => $techDocs,
            'lowRows' => $lowRows,
            'catRows' => $catRows,
            'usedRows' => $usedRows,
            'inventory' => $inventory,
            'contractRows' => $contractRows,
            'contractChart' => $contractChart,
            'history' => $jobs,
        ];
    }

    /* ------------------------------------------------------------ csv */

    public static function jobHeaders(): array { return ['Job', 'Customer', 'Branch', 'Country', 'Technician', 'Nature of visit', 'Due date', 'Value ('.currency().')', 'Status']; }

    public static function jobCsv(Collection $rows): array
    {
        return $rows->map(fn ($r) => [
            $r['job']->reference,
            $r['job']->customer->name,
            $r['branch'],
            $r['country'],
            $r['technician'],
            $r['job']->nature_of_visit,
            $r['job']->due_date->format('Y-m-d'),
            $r['valueMinor'] > 0 ? number_format($r['valueMinor'] / 100, 2, '.', '') : '',
            $r['status'],
        ])->all();
    }

    protected static function chartCsv(array $rows, string $head, bool $money = false): array
    {
        return [$head, ['Item', $money ? 'Value ('.currency().')' : 'Value'], array_map(
            fn ($r) => [$r['label'], $money ? number_format($r['value'] / 100, 2, '.', '') : $r['value']],
            $rows
        )];
    }

    protected static function mixCsv(array $mix, string $head): array
    {
        return [$head, ['Item', 'Count'], array_map(fn ($m) => [$m['label'], $m['value']], $mix)];
    }

    /** One entry per report section: [title, headers, rows]. */
    public static function csvSections(array $d, ?string $only = null): array
    {
        $kes = fn ($m) => number_format($m / 100, 2, '.', '');
        $k = $d['kpis'];
        $fin = $d['finance'];

        $all = [
            'summary' => ['Summary', ['Measure', 'Value'], [
                ['Requests', $k['requests']],
                ['Requests still open', $k['requestsOpen']],
                ['Request to job rate %', $k['winRate'] ?? ''],
                ['Quotation pipeline ('.currency().')', $kes($k['pipelineMinor'])],
                ['LPOs awaited', $k['lpoAwaited']],
                ['Jobs overdue', $k['overdueJobs']],
                ['Items to reorder', $k['reorder']],
            ]],
            'requests' => self::mixCsv($d['requestMix'], 'Requests by status'),
            'nature' => self::chartCsv($d['natureRows'], 'Nature of visit'),
            'branches' => self::chartCsv($d['branchRows'], 'Jobs by branch'),
            'pipeline' => self::chartCsv($d['pipelineRows'], 'Work pipeline'),
            'quotations' => self::mixCsv($d['quoteMix'], 'Quotations by status'),
            'value' => self::chartCsv($d['valueRows'], 'Quotation value by status', true),
            'lpo' => self::mixCsv($d['lpoMix'], 'LPO status'),
            'needs' => ['Quotations needing action', ['Reference', 'Customer', 'Amount ('.currency().')', 'Waiting for', 'Days'], $d['needs']->map(fn ($n) => [
                $n['q']->reference, $n['q']->customer?->name, $kes($n['q']->totalMinor()), $n['what'], $n['days'],
            ])->all()],
            'invoices' => ['Invoices', ['Measure', 'Count', 'Value ('.currency().')'], [
                ['Paid', $fin['paidCount'], $kes($fin['paidMinor'])],
                ['Draft or part paid', $fin['draftPartCount'], $kes($fin['draftPartMinor'])],
                ['Overdue (balance)', $fin['overdueCount'], $kes($fin['overdueMinor'])],
                ['Outstanding (balance)', $fin['owingCount'], $kes($fin['owingMinor'])],
            ]],
            'ageing' => ['Outstanding balance by age', ['Age', 'Invoices', 'Balance ('.currency().')'], array_map(fn ($a) => [$a['label'], $a['count'], $kes($a['value'])], $d['ageing'])],
            'revenue' => ['Revenue by technician', ['Technician', 'Branch', 'Jobs closed', 'Open jobs', 'Overdue jobs', 'Revenue ('.currency().')'], $d['techRows']->map(fn ($t) => [
                $t['user']->name, $t['location'], $t['closed'], $t['open'], $t['overdue'], $kes($t['revenueMinor']),
            ])->all()],
            'reports' => self::mixCsv($d['reportMix'], 'Technician reports by status'),
            'techdocs' => ['Technician documents flagged', ['Technician', 'Document', 'Due', 'Status'], $d['techDocs']->map(fn ($t) => [
                $t->technician->name, $t->document_type, $t->dueLabel(), $t->expiresAt()->lt(today()) ? 'Overdue' : 'Due soon',
            ])->all()],
            'lowstock' => ['Stock at or below reorder level', ['Item', 'In stock', 'Reorder at'], array_map(fn ($r) => [$r['label'], $r['value'], $r['marker']], $d['lowRows'])],
            'category' => self::chartCsv($d['catRows'], 'Units in stock by category'),
            'used' => self::chartCsv($d['usedRows'], 'Stock used '.$d['inventory']['usedLabel']),
            'contracts' => ['Contracts, renewal risk', ['Contract', 'Customer', 'Visits left', 'Ends', 'Status'], $d['contractRows']->map(fn ($r) => [
                $r['c']->reference, $r['c']->customer?->name, $r['left'], $r['c']->ends_at->format('Y-m-d'), $r['state'],
            ])->all()],
            'history' => ['Job history', self::jobHeaders(), self::jobCsv($d['history'])],
        ];

        return $only === null ? $all : array_intersect_key($all, array_flip(explode(',', $only)));
    }

    /* ------------------------------------------------------------ svg */

    protected static function wrap(string $label, int $max): array
    {
        $lines = [];
        $line = '';
        foreach (preg_split('/\s+/', trim($label)) as $word) {
            $try = $line === '' ? $word : $line.' '.$word;
            if (strlen($try) > $max && $line !== '') {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $try;
            }
        }
        $lines[] = $line;

        if (count($lines) > 2) {
            $lines = [$lines[0], rtrim(substr(implode(' ', array_slice($lines, 1)), 0, $max - 2)).'..'];
        }

        return array_map(fn ($l) => strlen($l) > $max + 2 ? substr($l, 0, $max - 1).'.' : $l, $lines);
    }

    /** Vertical bars. Rows: label, value, valueLabel, color, optional marker (a dashed target tick). */
    public static function columnSvg(array $data, int $w = 440, int $h = 210): string
    {
        $n = max(count($data), 1);
        $padL = 8;
        $padR = 8;
        $padT = 18;
        $padB = 34;
        $plotH = $h - $padT - $padB;
        $max = 1;
        foreach ($data as $r) {
            $max = max($max, $r['value'], $r['marker'] ?? 0);
        }
        $slot = ($w - $padL - $padR) / $n;
        $barW = min(44, $slot * 0.6);
        $maxChars = max(6, (int) floor($slot / 5));

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="'.$w.'" height="'.$h.'" viewBox="0 0 '.$w.' '.$h.'">';
        $svg .= '<rect width="'.$w.'" height="'.$h.'" fill="#ffffff"/>';
        for ($t = 0; $t <= 3; $t++) {
            $y = round($padT + $plotH - $plotH * $t / 3, 1);
            $svg .= '<line x1="'.$padL.'" x2="'.($w - $padR).'" y1="'.$y.'" y2="'.$y.'" stroke="'.($t === 0 ? '#a1a1aa' : '#ececee').'" stroke-width="1"/>';
        }

        if (empty($data) || array_sum(array_column($data, 'value')) <= 0) {
            return $svg.'<text x="'.($w / 2).'" y="'.($h / 2).'" text-anchor="middle" font-size="10" font-family="Helvetica" fill="#71717a">No data</text></svg>';
        }

        foreach (array_values($data) as $i => $r) {
            $cx = $padL + $slot * ($i + 0.5);
            $bh = $r['value'] > 0 ? max(2, $r['value'] / $max * $plotH) : 0;
            $top = $padT + $plotH - $bh;
            if ($bh > 0) {
                $svg .= '<rect x="'.round($cx - $barW / 2, 1).'" y="'.round($top, 1).'" width="'.round($barW, 1).'" height="'.round($bh, 1).'" rx="2" fill="'.($r['color'] ?? '#8f1d1d').'"/>';
            }
            $svg .= '<text x="'.round($cx, 1).'" y="'.round($top - 4, 1).'" text-anchor="middle" font-size="9" font-weight="bold" font-family="Helvetica" fill="#18181b">'.e($r['valueLabel'] ?? $r['value']).'</text>';
            if (isset($r['marker'])) {
                $my = round($padT + $plotH - $r['marker'] / $max * $plotH, 1);
                $svg .= '<line x1="'.round($cx - $barW / 2 - 4, 1).'" x2="'.round($cx + $barW / 2 + 4, 1).'" y1="'.$my.'" y2="'.$my.'" stroke="#18181b" stroke-width="1.4" stroke-dasharray="3,2"/>';
            }
            foreach (self::wrap((string) $r['label'], $maxChars) as $k => $line) {
                $svg .= '<text x="'.round($cx, 1).'" y="'.($h - $padB + 12 + $k * 10).'" text-anchor="middle" font-size="8" font-family="Helvetica" fill="#52525b">'.e($line).'</text>';
            }
        }

        return $svg.'</svg>';
    }
}
