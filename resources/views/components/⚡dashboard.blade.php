<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Models\ServiceRequest;
use App\Models\Document;
use App\Models\Quotation;
use App\Models\WorkOrder;
use App\Models\Customer;
use App\Models\Contract;
use App\Models\TechnicianDocument;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;

new #[Layout('layouts.app', ['title' => 'Overview'])] class extends Component
{
    public function getStatsProperty(): ?array
    {
        $user = auth()->user();

        if ($user->hasRole('Service Admin')) {
            return $this->serviceAdminStats();
        }

        if ($user->hasRole('Supervisor')) {
            return $this->supervisorStats();
        }

        if ($user->hasRole('Manager')) {
            return $this->managerStats();
        }

        if ($user->hasRole('Technician')) {
            return $this->technicianStats();
        }

        if ($user->hasRole('Finance')) {
            return $this->financeStats();
        }

        if ($user->hasRole('Customer')) {
            return $this->customerStats();
        }

        return null;
    }

    private function contractsNeedingAttention()
    {
        return Contract::with('customer')->get()
            ->filter(fn (Contract $c) => $c->expiryStage() !== 'fresh')
            ->sortByDesc(fn (Contract $c) => $c->percentOfTermUsed())
            ->values();
    }

    private function techDocsNeedingAttention()
    {
        return TechnicianDocument::with('technician')->get()
            ->filter(fn (TechnicianDocument $d) => $d->expiryStage() !== 'fresh')
            ->sortByDesc(fn (TechnicianDocument $d) => $d->percentUsed())
            ->values();
    }

    private function serviceAdminStats(): array
    {
        $jobCounts = WorkOrder::selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $jobTotal = max($jobCounts->sum(), 1);
        $circumference = 2 * M_PI * 42;

        $segments = [
            ['label' => 'Assigned', 'count' => (int) $jobCounts->get('Assigned', 0), 'stroke' => 'stroke-neutral-300', 'dot' => 'bg-neutral-300'],
            ['label' => 'On site', 'count' => (int) $jobCounts->get('On site', 0), 'stroke' => 'stroke-amber-500', 'dot' => 'bg-amber-500'],
            ['label' => 'Awaiting review', 'count' => (int) $jobCounts->get('Awaiting review', 0), 'stroke' => 'stroke-info-500', 'dot' => 'bg-info-500'],
            ['label' => 'Approved', 'count' => (int) $jobCounts->get('Approved', 0), 'stroke' => 'stroke-primary-400', 'dot' => 'bg-primary-400'],
            ['label' => 'Closed', 'count' => (int) $jobCounts->get('Closed', 0), 'stroke' => 'stroke-success-500', 'dot' => 'bg-success-500'],
        ];

        $offset = 0;
        foreach ($segments as &$segment) {
            $fraction = $segment['count'] / $jobTotal;
            $segment['dasharray'] = round($fraction * $circumference, 1).' '.round($circumference, 1);
            $segment['dashoffset'] = round(-$offset, 1);
            $offset += $fraction * $circumference;
        }
        unset($segment);

        return [
            'role' => 'Service Admin',
            'openRequests' => ServiceRequest::with('customer')->where('status', 'Open')->latest()->get(),
            'requestsByStatus' => ServiceRequest::selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
            'requestsThisWeek' => ServiceRequest::where('created_at', '>=', now()->startOfWeek())->count(),
            'convertedThisWeek' => ServiceRequest::where('status', 'Converted')->where('updated_at', '>=', now()->startOfWeek())->count(),
            'reportsReadyToPost' => Document::where('type', 'rep')->where('status', 'Checked, ready to post')->count(),
            'quotationsAwaitingLpo' => Quotation::where('status', 'Approved')->whereDoesntHave('lpoDetail')->count(),
            'readyToInvoice' => WorkOrder::whereHas('documents', fn ($q) => $q->where('type', 'rep')->where('status', 'Released'))
                ->whereDoesntHave('invoices')
                ->count(),
            'overdueJobs' => WorkOrder::where('due_date', '<', now())->whereNotIn('status', ['Closed'])->count(),
            'totalCustomers' => Customer::count(),
            'jobSegments' => $segments,
            'jobTotal' => $jobCounts->sum(),
            'recentRequests' => ServiceRequest::with('customer')->latest()->limit(5)->get(),
            'recentReports' => Document::with('customer')->where('type', 'rep')->latest()->limit(5)->get(),
            'contractsNeedingAttention' => $this->contractsNeedingAttention(),
            'techDocsNeedingAttention' => $this->techDocsNeedingAttention(),
        ];
    }

    private function supervisorStats(): array
    {
        $quotationsAwaiting = Quotation::with(['customer', 'items'])
            ->where('status', 'Awaiting Supervisor')
            ->latest()
            ->get();

        $dispatch = User::role('Technician')
            ->with(['assignedWorkOrders' => fn ($q) => $q->whereNotIn('status', ['Closed'])->orderByDesc('updated_at')])
            ->get()
            ->map(function (User $tech) {
                $tech->currentJob = $tech->assignedWorkOrders->first();
                $tech->openJobCount = $tech->assignedWorkOrders->count();

                return $tech;
            })
            ->sortByDesc('openJobCount')
            ->values();

        return [
            'role' => 'Supervisor',
            'quotationsAwaiting' => $quotationsAwaiting,
            'pipelineValueMinor' => $quotationsAwaiting->sum(fn (Quotation $q) => $q->totalMinor()),
            'reportsAwaitingReview' => Document::with(['customer', 'workOrder'])
                ->where('type', 'rep')
                ->where('status', 'Awaiting review')
                ->latest()
                ->get(),
            'dispatch' => $dispatch,
            'overdueJobsList' => WorkOrder::with('customer')->where('due_date', '<', now())->whereNotIn('status', ['Closed'])->orderBy('due_date')->get(),
            'jobsReadyToClose' => WorkOrder::where('status', 'Approved')->count(),
            'approvalsThisWeek' => Quotation::where('updated_at', '>=', now()->startOfWeek())
                ->whereIn('status', ['Approved', 'Sent back', 'Accepted', 'Converted'])
                ->count(),
            'contractsNeedingAttention' => $this->contractsNeedingAttention(),
        ];
    }

    private function managerStats(): array
    {
        $revenueThisMonthMinor = Invoice::where('issued_at', '>=', now()->startOfMonth())->sum('amount_minor');
        $revenueLastMonthMinor = Invoice::whereBetween('issued_at', [
            now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow()->endOfMonth(),
        ])->sum('amount_minor');

        $revenueDeltaPct = $revenueLastMonthMinor > 0
            ? round((($revenueThisMonthMinor - $revenueLastMonthMinor) / $revenueLastMonthMinor) * 100, 1)
            : null;

        // Real 6-month revenue trend for the hero sparkline, computed from
        // actual invoices, not fabricated.
        $monthlyRevenueTrend = [];
        for ($i = 5; $i >= 0; $i--) {
            $monthlyRevenueTrend[] = (int) Invoice::whereBetween('issued_at', [
                now()->subMonthsNoOverflow($i)->startOfMonth(),
                now()->subMonthsNoOverflow($i)->endOfMonth(),
            ])->sum('amount_minor');
        }

        $quotationsAwaiting = Quotation::with(['customer', 'items'])
            ->where('status', 'Awaiting Manager')
            ->latest()
            ->get();

        $technicianPerformance = User::role('Technician')->get()->map(function (User $tech) {
            $tech->jobsClosedCount = WorkOrder::where('assigned_technician_id', $tech->id)->where('status', 'Closed')->count();
            $tech->revenueMinor = Invoice::whereHas('workOrder', fn ($q) => $q->where('assigned_technician_id', $tech->id))->sum('amount_minor');

            return $tech;
        })->sortByDesc('revenueMinor')->take(5)->values();

        return [
            'role' => 'Manager',
            'revenueThisMonthMinor' => $revenueThisMonthMinor,
            'revenueDeltaPct' => $revenueDeltaPct,
            'monthlyRevenueTrend' => $monthlyRevenueTrend,
            'jobsClosedThisMonth' => WorkOrder::where('status', 'Closed')->where('updated_at', '>=', now()->startOfMonth())->count(),
            'quotationsAwaiting' => $quotationsAwaiting,
            'pipelineValueMinor' => $quotationsAwaiting->sum(fn (Quotation $q) => $q->totalMinor()),
            'technicianPerformance' => $technicianPerformance,
            'contractsNeedingAttention' => $this->contractsNeedingAttention(),
            'totalCustomers' => Customer::count(),
            'activeContracts' => Contract::where('status', 'Active')->count(),
            'overdueJobs' => WorkOrder::where('due_date', '<', now())->whereNotIn('status', ['Closed'])->count(),
            'totalTechnicians' => User::role('Technician')->count(),
        ];
    }

    private function technicianStats(): array
    {
        $user = auth()->user();

        $myJobs = WorkOrder::with(['customer', 'sourceRequest'])
            ->where('assigned_technician_id', $user->id)
            ->whereNotIn('status', ['Closed'])
            ->orderBy('due_date')
            ->get();

        $dueTodayOrOverdue = $myJobs->filter(fn (WorkOrder $job) => $job->due_date->lte(today()));

        return [
            'role' => 'Technician',
            'nextJob' => $myJobs->first(),
            'myJobs' => $myJobs,
            'dueTodayOrOverdue' => $dueTodayOrOverdue,
            'statusCounts' => $myJobs->countBy('status'),
            'reportsFiledThisWeek' => Document::where('type', 'rep')
                ->where('filed_by', $user->id)
                ->where('created_at', '>=', now()->startOfWeek())
                ->count(),
            'jobsClosedThisMonth' => WorkOrder::where('assigned_technician_id', $user->id)
                ->where('status', 'Closed')
                ->where('updated_at', '>=', now()->startOfMonth())
                ->count(),
            'myDocuments' => TechnicianDocument::where('technician_id', $user->id)->get(),
        ];
    }

    private function financeStats(): array
    {
        $outstanding = Invoice::with('customer')->whereIn('status', ['Unpaid', 'Part paid'])->get();

        $ageing = ['current' => 0, 'days1to30' => 0, 'days31to60' => 0, 'days60plus' => 0];
        foreach ($outstanding as $invoice) {
            $daysOverdue = now()->diffInDays($invoice->due_at, false) * -1;
            $balance = $invoice->balanceMinor();

            if ($daysOverdue <= 0) {
                $ageing['current'] += $balance;
            } elseif ($daysOverdue <= 30) {
                $ageing['days1to30'] += $balance;
            } elseif ($daysOverdue <= 60) {
                $ageing['days31to60'] += $balance;
            } else {
                $ageing['days60plus'] += $balance;
            }
        }

        $readyJobs = WorkOrder::whereHas('documents', fn ($q) => $q->where('type', 'rep')->where('status', 'Released'))
            ->whereDoesntHave('invoices')
            ->get();

        return [
            'role' => 'Finance',
            'readyToInvoice' => $readyJobs->count(),
            'readyToInvoiceValueMinor' => $readyJobs->whereNotNull('value_minor')->sum('value_minor'),
            'outstandingBalanceMinor' => $outstanding->sum(fn (Invoice $i) => $i->balanceMinor()),
            'ageing' => $ageing,
            'overdueInvoices' => Invoice::with('customer')->where('due_at', '<', now())->whereIn('status', ['Unpaid', 'Part paid'])->get(),
            'paidThisMonthMinor' => Payment::where('paid_at', '>=', now()->startOfMonth())->sum('amount_minor'),
            'recentInvoices' => Invoice::with('customer')->latest()->limit(6)->get(),
            'recentPayments' => Payment::with('customer')->latest()->limit(6)->get(),
        ];
    }

    private function customerStats(): array
    {
        $user = auth()->user();
        $customerId = $user->customer_id;

        $myRequests = ServiceRequest::where('customer_id', $customerId)->latest()->get();
        $myJobs = WorkOrder::where('customer_id', $customerId)
            ->whereNotIn('status', ['Closed'])
            ->orderBy('due_date')
            ->get();
        $myInvoices = Invoice::where('customer_id', $customerId)->latest('issued_at')->get();
        $outstanding = $myInvoices->whereIn('status', ['Unpaid', 'Part paid']);

        $contract = Contract::where('customer_id', $customerId)
            ->where('status', 'Active')
            ->latest('ends_at')
            ->first();

        $recentDocuments = Document::with('workOrder')
            ->where('customer_id', $customerId)
            ->whereIn('type', Document::scopeForRole('Customer'))
            ->latest()
            ->take(5)
            ->get();

        return [
            'role' => 'Customer',
            'customer' => Customer::find($customerId),
            'openRequestsCount' => $myRequests->whereIn('status', ['Open', 'Assigned', 'Quoted'])->count(),
            'recentRequests' => $myRequests->take(5),
            'activeJobs' => $myJobs,
            'nextJob' => $myJobs->first(),
            'outstandingBalanceMinor' => $outstanding->sum(fn (Invoice $i) => $i->balanceMinor()),
            'overdueInvoicesCount' => $outstanding->filter(fn (Invoice $i) => $i->due_at->isPast())->count(),
            'recentInvoices' => $myInvoices->take(5),
            'contract' => $contract,
            'recentDocuments' => $recentDocuments,
        ];
    }
};
?>
@php
    $s = $this->stats;
    $short = fn (int $m) => 'KES '.\App\Services\ServiceAdminReports::short($m);
    $full = fn (int $m) => 'KES '.number_format($m / 100, 0);
    $pillFor = fn (string $st) => match (true) {
        in_array($st, ['Converted', 'Released', 'Approved', 'Closed', 'Paid', 'Accepted']) => 'pill-success',
        in_array($st, ['Declined', 'Rejected']) => 'pill-danger',
        default => 'pill-neutral',
    };
    $brand = '#8f1d1d';
    $tiles = 'grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));';
    $role = auth()->user()->hasRole('Technician') ? 'My day' : auth()->user()->getRoleNames()->first();
@endphp
<div class="mx-auto" style="max-width: 72rem">
    <div class="mb-5">
        <h1 class="text-xl font-semibold text-neutral-900">Good {{ now()->hour < 12 ? 'morning' : (now()->hour < 17 ? 'afternoon' : 'evening') }}, {{ explode(' ', auth()->user()->name)[0] }}</h1>
        <p class="text-sm text-neutral-500">{{ $role }} &middot; {{ now()->format('l, d F') }}</p>
    </div>

    @if (! $s)
        <div class="card">
            <p class="text-sm text-neutral-500">You are signed in. There is no dashboard for {{ auth()->user()->getRoleNames()->first() }} yet.</p>
        </div>

    {{-- ===================== SERVICE ADMIN ===================== --}}
    @elseif ($s['role'] === 'Service Admin')
        <div class="grid gap-3" style="{{ $tiles }}">
            <x-dash.kpi label="Requests to triage" :value="$s['openRequests']->count()" :sub="$s['requestsThisWeek'].' logged this week'" href="/requests" />
            <x-dash.kpi label="Ready to post" :value="$s['reportsReadyToPost']" sub="checked reports" href="/documents" />
            <x-dash.kpi label="Awaiting LPO" :value="$s['quotationsAwaitingLpo']" sub="approved quotations" href="/lpos" />
            <x-dash.kpi label="Ready to invoice" :value="$s['readyToInvoice']" sub="released reports" />
            <x-dash.kpi label="Overdue jobs" :value="$s['overdueJobs']" :tone="$s['overdueJobs'] > 0 ? 'bad' : null" href="/jobs" />
        </div>

        <x-dash.section title="Compliance" />
        <div class="flex flex-wrap gap-4">
            <div class="card" style="flex: 1 1 320px; min-width: 0">
                <h3 class="mb-1 text-sm font-semibold text-neutral-900">Contracts nearing expiry</h3>
                <x-dash.expiring kind="contract" :items="$s['contractsNeedingAttention']" empty="No contracts need attention." />
            </div>
            <div class="card" style="flex: 1 1 320px; min-width: 0">
                <h3 class="mb-1 text-sm font-semibold text-neutral-900">Technician certificates</h3>
                <x-dash.expiring kind="cert" :items="$s['techDocsNeedingAttention']" empty="All certificates are current." />
            </div>
        </div>

        <x-dash.section title="Workload" href="/service-reports" link="Full reports" />
        <div class="flex flex-wrap gap-4">
            <div class="card" style="flex: 1 1 260px; min-width: 0">
                <h3 class="mb-4 text-sm font-semibold text-neutral-900">Jobs by stage</h3>
                @php
                    $stageColors = ['Assigned' => '#9ca3af', 'On site' => '#e0ac2e', 'Awaiting review' => '#475569', 'Approved' => '#8f1d1d', 'Closed' => '#15803d'];
                    $stagePie = collect($s['jobSegments'])->map(fn ($g) => ['label' => $g['label'], 'value' => $g['count'], 'color' => $stageColors[$g['label']] ?? '#9ca3af'])->values()->all();
                @endphp
                <x-pie-chart :data="$stagePie" :size="140" />
            </div>
            <div class="card" style="flex: 2 1 380px; min-width: 0">
                <h3 class="mb-4 text-sm font-semibold text-neutral-900">Requests by status</h3>
                <x-column-chart :height="170" :data="collect(['Open', 'Assigned', 'Quoted', 'Converted', 'Declined'])->map(fn ($st) => [
                    'label' => $st, 'value' => (int) $s['requestsByStatus']->get($st, 0), 'valueLabel' => (string) (int) $s['requestsByStatus']->get($st, 0), 'color' => $brand,
                ])->all()" />
            </div>
        </div>

        <x-dash.section title="Recent" />
        <div class="flex flex-wrap gap-4">
            <div class="card" style="flex: 1 1 320px; min-width: 0">
                <h3 class="mb-1 text-sm font-semibold text-neutral-900">Requests</h3>
                <div class="divide-y divide-neutral-100">
                    @forelse ($s['recentRequests'] as $r)
                        <x-dash.row :href="'/requests/'.$r->id" :title="$r->reference" :meta="$r->customer->name" :pill="$r->status" :pillClass="$pillFor($r->status)" />
                    @empty
                        <p class="py-3 text-sm text-neutral-400">No requests yet.</p>
                    @endforelse
                </div>
            </div>
            <div class="card" style="flex: 1 1 320px; min-width: 0">
                <h3 class="mb-1 text-sm font-semibold text-neutral-900">Service reports</h3>
                <div class="divide-y divide-neutral-100">
                    @forelse ($s['recentReports'] as $r)
                        <x-dash.row :href="'/documents/'.$r->id" :title="$r->reference" :meta="$r->customer->name" :pill="$r->status" :pillClass="$pillFor($r->status)" />
                    @empty
                        <p class="py-3 text-sm text-neutral-400">No reports yet.</p>
                    @endforelse
                </div>
            </div>
        </div>

    {{-- ===================== SUPERVISOR ===================== --}}
    @elseif ($s['role'] === 'Supervisor')
        <div class="grid gap-3" style="{{ $tiles }}">
            <x-dash.kpi label="Awaiting approval" :value="$s['quotationsAwaiting']->count()" :sub="$short($s['pipelineValueMinor']).' in value'" href="/approvals" />
            <x-dash.kpi label="Reports to review" :value="$s['reportsAwaitingReview']->count()" />
            <x-dash.kpi label="Ready to close" :value="$s['jobsReadyToClose']" sub="approved jobs" />
            <x-dash.kpi label="Overdue jobs" :value="$s['overdueJobsList']->count()" :tone="$s['overdueJobsList']->count() > 0 ? 'bad' : null" href="/jobs" />
            <x-dash.kpi label="Decided this week" :value="$s['approvalsThisWeek']" sub="quotations" />
        </div>

        <x-dash.section title="Waiting on you" href="/approvals" />
        <div class="flex flex-wrap gap-4">
            <div class="card" style="flex: 1 1 320px; min-width: 0">
                <h3 class="mb-1 text-sm font-semibold text-neutral-900">Quotations</h3>
                <div class="divide-y divide-neutral-100">
                    @forelse ($s['quotationsAwaiting'] as $q)
                        <x-dash.row :href="'/quotations/'.$q->id" :title="$q->reference" :meta="$q->customer->name" :value="$full($q->totalMinor())" />
                    @empty
                        <p class="py-3 text-sm text-neutral-400">Nothing waiting.</p>
                    @endforelse
                </div>
            </div>
            <div class="card" style="flex: 1 1 320px; min-width: 0">
                <h3 class="mb-1 text-sm font-semibold text-neutral-900">Service reports</h3>
                <div class="divide-y divide-neutral-100">
                    @forelse ($s['reportsAwaitingReview'] as $r)
                        <x-dash.row :href="'/documents/'.$r->id" :title="$r->reference" :meta="$r->customer->name" :value="$r->workOrder?->reference" />
                    @empty
                        <p class="py-3 text-sm text-neutral-400">Nothing waiting.</p>
                    @endforelse
                </div>
            </div>
        </div>

        <x-dash.section title="Team" href="/technicians" />
        <div class="flex flex-wrap gap-4">
            <div class="card" style="flex: 2 1 380px; min-width: 0">
                <h3 class="mb-4 text-sm font-semibold text-neutral-900">Open jobs by technician</h3>
                <x-column-chart :height="170" :data="$s['dispatch']->map(fn ($t) => [
                    'label' => explode(' ', $t->name)[0], 'value' => $t->openJobCount, 'valueLabel' => (string) $t->openJobCount, 'color' => $brand,
                ])->all()" />
            </div>
            <div class="card" style="flex: 1 1 300px; min-width: 0">
                <h3 class="mb-1 text-sm font-semibold text-neutral-900">Contracts nearing expiry</h3>
                <x-dash.expiring kind="contract" :items="$s['contractsNeedingAttention']" empty="No contracts need attention." />
            </div>
        </div>

    {{-- ===================== MANAGER ===================== --}}
    @elseif ($s['role'] === 'Manager')
        <div class="grid gap-3" style="{{ $tiles }}">
            <x-dash.kpi label="Revenue this month" :value="$short($s['revenueThisMonthMinor'])"
                        :sub="$s['revenueDeltaPct'] === null ? 'no earlier month' : (($s['revenueDeltaPct'] >= 0 ? 'up ' : 'down ').abs($s['revenueDeltaPct']).'% on last month')" />
            <x-dash.kpi label="Jobs closed" :value="$s['jobsClosedThisMonth']" sub="this month" />
            <x-dash.kpi label="Awaiting approval" :value="$s['quotationsAwaiting']->count()" :sub="$short($s['pipelineValueMinor']).' in value'" href="/approvals" />
            <x-dash.kpi label="Overdue jobs" :value="$s['overdueJobs']" :tone="$s['overdueJobs'] > 0 ? 'bad' : null" href="/jobs" />
            <x-dash.kpi label="Active contracts" :value="$s['activeContracts']" :sub="'of '.$s['totalCustomers'].' customers'" />
        </div>

        <x-dash.section title="Performance" href="/reports" link="Full reports" />
        <div class="flex flex-wrap gap-4">
            <div class="card" style="flex: 1 1 340px; min-width: 0">
                <h3 class="mb-1 text-sm font-semibold text-neutral-900">Revenue, last 6 months</h3>
                <p class="mb-4 text-xs text-neutral-400">KES, by invoice date</p>
                <x-column-chart :height="170" :minCol="40" :data="collect($s['monthlyRevenueTrend'])->map(fn ($v, $i) => [
                    'label' => now()->subMonthsNoOverflow(5 - $i)->format('M'), 'value' => $v, 'valueLabel' => \App\Services\ServiceAdminReports::short((int) $v), 'color' => $brand,
                ])->all()" />
            </div>
            <div class="card" style="flex: 1 1 340px; min-width: 0">
                <h3 class="mb-1 text-sm font-semibold text-neutral-900">Top technicians</h3>
                <p class="mb-4 text-xs text-neutral-400">KES invoiced on their jobs</p>
                <x-column-chart :height="170" :data="$s['technicianPerformance']->map(fn ($t) => [
                    'label' => explode(' ', $t->name)[0], 'value' => $t->revenueMinor, 'valueLabel' => \App\Services\ServiceAdminReports::short((int) $t->revenueMinor), 'color' => $brand,
                ])->all()" />
            </div>
        </div>

        <x-dash.section title="Compliance" />
        <div class="card">
            <h3 class="mb-1 text-sm font-semibold text-neutral-900">Contracts nearing expiry</h3>
            <x-dash.expiring kind="contract" :items="$s['contractsNeedingAttention']" :limit="6" empty="No contracts need attention." />
        </div>

    {{-- ===================== TECHNICIAN ===================== --}}
    @elseif ($s['role'] === 'Technician')
        @if ($s['nextJob'])
            @php $n = $s['nextJob']; @endphp
            <div class="card" style="margin-bottom: 1rem">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div style="min-width: 0">
                        <p class="text-xs font-medium text-neutral-500">Next stop</p>
                        <p class="mt-1 text-lg font-semibold text-neutral-900">{{ $n->reference }} &middot; {{ $n->customer->name }}</p>
                        <p class="mt-0.5 text-sm text-neutral-500">{{ $n->nature_of_visit }} &middot; due {{ $n->due_date->format('D, d M') }}</p>
                        @if ($n->sourceRequest)
                            <p class="mt-2 text-sm text-neutral-600">{{ $n->sourceRequest->fault_description }}</p>
                        @endif
                    </div>
                    @if ($n->status === 'On site')
                        <a href="/jobs/{{ $n->id }}/report" wire:navigate class="btn-primary">File service report</a>
                    @else
                        <a href="/jobs/{{ $n->id }}" wire:navigate class="btn-dark">Open job</a>
                    @endif
                </div>
            </div>
        @endif

        <div class="grid gap-3" style="{{ $tiles }}">
            <x-dash.kpi label="Open jobs" :value="$s['myJobs']->count()" href="/jobs" />
            <x-dash.kpi label="Due or overdue" :value="$s['dueTodayOrOverdue']->count()" :tone="$s['dueTodayOrOverdue']->count() > 0 ? 'bad' : null" />
            <x-dash.kpi label="Reports filed" :value="$s['reportsFiledThisWeek']" sub="this week" />
            <x-dash.kpi label="Closed" :value="$s['jobsClosedThisMonth']" sub="this month" />
        </div>

        <x-dash.section title="Rest of my list" href="/jobs" />
        <div class="card">
            <div class="divide-y divide-neutral-100">
                @forelse ($s['myJobs']->slice(1) as $job)
                    <x-dash.row :href="'/jobs/'.$job->id" :title="$job->reference.' · '.$job->customer->name"
                                :meta="'Due '.$job->due_date->format('d M')" :pill="$job->status" :pillClass="$pillFor($job->status)" />
                @empty
                    <p class="py-3 text-sm text-neutral-400">Nothing else on your list.</p>
                @endforelse
            </div>
        </div>

        <x-dash.section title="My documents" />
        <div class="card">
            <x-dash.expiring kind="own" :items="$s['myDocuments']" :limit="6" empty="No documents on file." />
        </div>

    {{-- ===================== FINANCE ===================== --}}
    @elseif ($s['role'] === 'Finance')
        <div class="grid gap-3" style="{{ $tiles }}">
            <x-dash.kpi label="Outstanding" :value="$short($s['outstandingBalanceMinor'])" sub="unpaid and part paid" href="/invoices" />
            <x-dash.kpi label="Collected" :value="$short($s['paidThisMonthMinor'])" sub="this month" />
            <x-dash.kpi label="Ready to invoice" :value="$s['readyToInvoice']" :sub="$s['readyToInvoiceValueMinor'] > 0 ? $short($s['readyToInvoiceValueMinor']) : null" />
            <x-dash.kpi label="Overdue invoices" :value="$s['overdueInvoices']->count()" :tone="$s['overdueInvoices']->count() > 0 ? 'bad' : null" />
        </div>

        <x-dash.section title="Outstanding by age" />
        <div class="card">
            <x-column-chart :height="170" :data="[
                ['label' => 'Current', 'value' => $s['ageing']['current'], 'valueLabel' => \App\Services\ServiceAdminReports::short((int) $s['ageing']['current']), 'color' => '#9ca3af'],
                ['label' => '1 to 30 days', 'value' => $s['ageing']['days1to30'], 'valueLabel' => \App\Services\ServiceAdminReports::short((int) $s['ageing']['days1to30']), 'color' => '#e0ac2e'],
                ['label' => '31 to 60 days', 'value' => $s['ageing']['days31to60'], 'valueLabel' => \App\Services\ServiceAdminReports::short((int) $s['ageing']['days31to60']), 'color' => '#b45309'],
                ['label' => 'Over 60 days', 'value' => $s['ageing']['days60plus'], 'valueLabel' => \App\Services\ServiceAdminReports::short((int) $s['ageing']['days60plus']), 'color' => $brand],
            ]" />
        </div>

        @if ($s['overdueInvoices']->isNotEmpty())
            <x-dash.section title="Overdue" href="/invoices" />
            <div class="card">
                <div class="divide-y divide-neutral-100">
                    @foreach ($s['overdueInvoices']->sortByDesc(fn ($i) => $i->balanceMinor())->take(6) as $inv)
                        <x-dash.row :href="'/invoices/'.$inv->id" :title="$inv->reference" :meta="$inv->customer->name.' · due '.$inv->due_at->format('d M')" :value="$full($inv->balanceMinor())" valueClass="text-critical-700" />
                    @endforeach
                </div>
                @if ($s['overdueInvoices']->count() > 6)
                    <p class="mt-2 text-xs text-neutral-400">+{{ $s['overdueInvoices']->count() - 6 }} more</p>
                @endif
            </div>
        @endif

        <x-dash.section title="Recent" />
        <div class="flex flex-wrap gap-4">
            <div class="card" style="flex: 1 1 320px; min-width: 0">
                <h3 class="mb-1 text-sm font-semibold text-neutral-900">Invoices</h3>
                <div class="divide-y divide-neutral-100">
                    @forelse ($s['recentInvoices'] as $inv)
                        <x-dash.row :href="'/invoices/'.$inv->id" :title="$inv->reference" :meta="$inv->customer->name" :value="$full($inv->amount_minor)" :pill="$inv->status" :pillClass="$pillFor($inv->status)" />
                    @empty
                        <p class="py-3 text-sm text-neutral-400">No invoices yet.</p>
                    @endforelse
                </div>
            </div>
            <div class="card" style="flex: 1 1 320px; min-width: 0">
                <h3 class="mb-1 text-sm font-semibold text-neutral-900">Payments</h3>
                <div class="divide-y divide-neutral-100">
                    @forelse ($s['recentPayments'] as $p)
                        <x-dash.row :title="$p->reference" :meta="$p->customer->name" :value="'+'.$full($p->amount_minor)" />
                    @empty
                        <p class="py-3 text-sm text-neutral-400">No payments yet.</p>
                    @endforelse
                </div>
            </div>
        </div>

    {{-- ===================== CUSTOMER ===================== --}}
    @elseif ($s['role'] === 'Customer')
        <div class="grid gap-3" style="{{ $tiles }}">
            <x-dash.kpi label="Open requests" :value="$s['openRequestsCount']"
                        :sub="$s['nextJob'] ? 'next visit '.$s['nextJob']->due_date->format('d M') : 'no visit booked'" href="/requests" />
            <x-dash.kpi label="Active jobs" :value="$s['activeJobs']->count()" href="/jobs" />
            <x-dash.kpi label="Outstanding" :value="$short($s['outstandingBalanceMinor'])"
                        :sub="$s['overdueInvoicesCount'] > 0 ? $s['overdueInvoicesCount'].' overdue' : 'nothing overdue'"
                        :tone="$s['overdueInvoicesCount'] > 0 ? 'bad' : null" href="/invoices" />
        </div>

        @if ($s['contract'])
            <x-dash.section title="Your contract" href="/contracts" />
            <div class="card">
                <x-expiry-ring :percent="$s['contract']->percentOfTermUsedCapped()" :stage="$s['contract']->expiryStage()"
                               :title="$s['contract']->reference.' · '.$s['contract']->type" :expiresAt="$s['contract']->ends_at->format('d M Y')" />
            </div>
        @endif

        <x-dash.section title="Recent" />
        <div class="flex flex-wrap gap-4">
            <div class="card" style="flex: 1 1 320px; min-width: 0">
                <h3 class="mb-1 text-sm font-semibold text-neutral-900">Requests</h3>
                <div class="divide-y divide-neutral-100">
                    @forelse ($s['recentRequests'] as $r)
                        <x-dash.row :href="'/requests/'.$r->id" :title="$r->reference" :meta="$r->fault_description" :pill="$r->status" :pillClass="$pillFor($r->status)" />
                    @empty
                        <p class="py-3 text-sm text-neutral-400">No requests yet.</p>
                    @endforelse
                </div>
            </div>
            <div class="card" style="flex: 1 1 320px; min-width: 0">
                <h3 class="mb-1 text-sm font-semibold text-neutral-900">Documents</h3>
                @php $docLabels = ['rep' => 'Service report', 'cert' => 'Calibration certificate', 'mv' => 'Maintenance voucher', 'dn' => 'Delivery note']; @endphp
                <div class="divide-y divide-neutral-100">
                    @forelse ($s['recentDocuments'] as $d)
                        <x-dash.row :href="'/documents/'.$d->id" :title="$d->reference" :meta="$docLabels[$d->type] ?? ucfirst($d->type)" />
                    @empty
                        <p class="py-3 text-sm text-neutral-400">No documents yet.</p>
                    @endforelse
                </div>
            </div>
        </div>

        <x-dash.section title="Invoices" href="/invoices" />
        <div class="card">
            <div class="divide-y divide-neutral-100">
                @forelse ($s['recentInvoices'] as $inv)
                    <x-dash.row :href="'/invoices/'.$inv->id" :title="$inv->reference" :meta="'Due '.$inv->due_at->format('d M Y')" :value="$full($inv->amount_minor)" :pill="$inv->status" :pillClass="$pillFor($inv->status)" />
                @empty
                    <p class="py-3 text-sm text-neutral-400">No invoices yet.</p>
                @endforelse
            </div>
        </div>
    @endif
</div>
