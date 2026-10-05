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
<div>
    <div class="mb-6">
        <p class="text-xs font-semibold uppercase tracking-wider text-primary-600">{{ auth()->user()->hasRole('Technician') ? 'My day' : auth()->user()->getRoleNames()->first() }}</p>
        <h1 class="mt-0.5 text-2xl font-semibold text-neutral-900">
            Good {{ now()->hour < 12 ? 'morning' : (now()->hour < 17 ? 'afternoon' : 'evening') }}, {{ explode(' ', auth()->user()->name)[0] }}
        </h1>
    </div>

    @if (! $this->stats)
        <div class="card">
            <p class="text-sm text-neutral-500">
                You're signed in. A dedicated dashboard for {{ auth()->user()->getRoleNames()->first() }} hasn't been built yet.
            </p>
        </div>

    @elseif ($this->stats['role'] === 'Service Admin')
        {{-- Hero paired with a STACK of two cards, not one lone short card,
             so the right column's content actually fills the row height. --}}
        <div class="mb-5 grid gap-4 lg:grid-cols-12">
            <div class="card-dark lg:col-span-7">
                <div class="flex items-start justify-between gap-6">
                    <div class="shrink-0">
                        <p class="text-[11px] font-semibold uppercase tracking-widest text-white/40">Requests to triage</p>
                        <p class="mt-1.5 text-5xl font-bold leading-none text-white">{{ $this->stats['openRequests']->count() }}</p>
                        <p class="mt-2 text-xs text-white/40">{{ $this->stats['requestsThisWeek'] }} logged this week</p>
                        <a href="/requests" wire:navigate class="mt-4 inline-flex items-center gap-2 rounded-[var(--radius-md)] bg-white/10 px-4 py-2.5 text-xs font-semibold text-white hover:bg-white/20">
                            View all <x-icon name="arrow-right" class="h-3.5 w-3.5" />
                        </a>
                    </div>
                    <div class="flex-1 border-l border-white/10 pl-6">
                        <p class="mb-2.5 text-[11px] font-semibold uppercase tracking-widest text-white/30">Full pipeline</p>
                        @foreach (['Open', 'Assigned', 'Quoted', 'Converted', 'Declined'] as $status)
                            <div class="flex items-center justify-between border-b border-white/5 py-1.5 text-sm last:border-0">
                                <span class="text-white/60">{{ $status }}</span>
                                <span class="font-semibold text-white">{{ $this->stats['requestsByStatus']->get($status, 0) }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            <div class="flex flex-col gap-4 lg:col-span-5">
                <div class="card flex-1">
                    <div class="flex items-center justify-between">
                        <span class="icon-badge-sm icon-badge-info"><x-icon name="folder" class="h-4 w-4" /></span>
                        <span class="text-3xl font-bold leading-none text-neutral-900">{{ $this->stats['reportsReadyToPost'] }}</span>
                    </div>
                    <p class="mt-3 text-sm font-semibold text-neutral-900">Ready to post</p>
                    <a href="/documents" wire:navigate class="mt-1 inline-flex items-center gap-1 text-xs font-semibold text-info-600 hover:text-info-700">
                        Review and post <x-icon name="arrow-right" class="h-3 w-3" />
                    </a>
                </div>
                <div class="card flex-1">
                    <div class="flex items-center justify-between">
                        <span class="icon-badge-sm icon-badge-amber"><x-icon name="journal-text" class="h-4 w-4" /></span>
                        <span class="text-3xl font-bold leading-none text-neutral-900">{{ $this->stats['quotationsAwaitingLpo'] }}</span>
                    </div>
                    <p class="mt-3 text-sm font-semibold text-neutral-900">Awaiting LPO</p>
                    <a href="/quotations" wire:navigate class="mt-1 inline-flex items-center gap-1 text-xs font-semibold text-amber-700 hover:text-amber-800">
                        View quotations <x-icon name="arrow-right" class="h-3 w-3" />
                    </a>
                </div>
            </div>
        </div>

        <div class="mb-5">
            <p class="mb-2 text-[11px] font-semibold uppercase tracking-widest text-neutral-400">Compliance &middot; top priority</p>
            <div class="grid items-start gap-4 lg:grid-cols-2">
                <div class="card">
                    <h2 class="mb-1 text-sm font-semibold text-neutral-900">Contracts nearing expiry</h2>
                    <div class="divide-y divide-neutral-100">
                        @forelse ($this->stats['contractsNeedingAttention']->take(4) as $contract)
                            <x-expiry-ring
                                :percent="$contract->percentOfTermUsedCapped()"
                                :stage="$contract->expiryStage()"
                                :title="$contract->reference.' — '.$contract->customer->name"
                                :expiresAt="$contract->ends_at->format('d M Y')" />
                        @empty
                            <p class="py-2 text-sm text-neutral-500">No contracts need attention right now.</p>
                        @endforelse
                    </div>
                    @if ($this->stats['contractsNeedingAttention']->count() > 4)
                        <p class="mt-2 text-xs text-neutral-400">+{{ $this->stats['contractsNeedingAttention']->count() - 4 }} more not shown</p>
                    @endif
                </div>

                <div class="card">
                    <h2 class="mb-1 text-sm font-semibold text-neutral-900">Technician certificates</h2>
                    <div class="divide-y divide-neutral-100">
                        @forelse ($this->stats['techDocsNeedingAttention']->take(4) as $doc)
                            <x-expiry-ring
                                :percent="$doc->percentUsedCapped()"
                                :stage="$doc->expiryStage()"
                                :title="$doc->technician->name.' — '.$doc->document_type"
                                :expiresAt="$doc->expiresAt()->format('d M Y')" />
                        @empty
                            <p class="py-2 text-sm text-neutral-500">All technician documents are current.</p>
                        @endforelse
                    </div>
                    @if ($this->stats['techDocsNeedingAttention']->count() > 4)
                        <p class="mt-2 text-xs text-neutral-400">+{{ $this->stats['techDocsNeedingAttention']->count() - 4 }} more not shown</p>
                    @endif
                </div>
            </div>
        </div>

        <div class="mb-5 grid grid-cols-3 gap-3">
            <div class="card-compact">
                <span class="icon-badge-sm icon-badge-info"><x-icon name="receipt" class="h-4 w-4" /></span>
                <p class="mt-2.5 text-2xl font-bold text-neutral-900">{{ $this->stats['readyToInvoice'] }}</p>
                <p class="mt-0.5 text-xs font-medium text-neutral-500">To invoice</p>
            </div>
            <div class="card-compact">
                <span class="icon-badge-sm icon-badge-primary"><x-icon name="exclamation-circle" class="h-4 w-4" /></span>
                <p class="mt-2.5 text-2xl font-bold text-neutral-900">{{ $this->stats['overdueJobs'] }}</p>
                <p class="mt-0.5 text-xs font-medium text-neutral-500">Overdue jobs</p>
            </div>
            <div class="card-compact">
                <span class="icon-badge-sm icon-badge-success"><x-icon name="people" class="h-4 w-4" /></span>
                <p class="mt-2.5 text-2xl font-bold text-neutral-900">{{ $this->stats['totalCustomers'] }}</p>
                <p class="mt-0.5 text-xs font-medium text-neutral-500">Customers</p>
            </div>
        </div>

        <div class="mb-5 grid items-start gap-4 lg:grid-cols-12">
            <div class="card flex items-center gap-6 lg:col-span-7">
                <svg viewBox="0 0 100 100" class="h-28 w-28 shrink-0 -rotate-90">
                    <circle cx="50" cy="50" r="42" fill="none" stroke-width="12" class="stroke-neutral-100" />
                    @foreach ($this->stats['jobSegments'] as $segment)
                        @if ($segment['count'] > 0)
                            <circle cx="50" cy="50" r="42" fill="none" stroke-width="12" stroke-linecap="round"
                                    class="{{ $segment['stroke'] }}"
                                    stroke-dasharray="{{ $segment['dasharray'] }}"
                                    stroke-dashoffset="{{ $segment['dashoffset'] }}" />
                        @endif
                    @endforeach
                    <text x="50" y="54" text-anchor="middle" transform="rotate(90 50 50)" class="fill-neutral-900 text-[22px] font-bold">{{ $this->stats['jobTotal'] }}</text>
                </svg>
                <div class="flex-1">
                    <p class="mb-2.5 text-[11px] font-semibold uppercase tracking-widest text-neutral-400">Jobs by stage</p>
                    @foreach ($this->stats['jobSegments'] as $segment)
                        <div class="flex items-center justify-between border-b border-neutral-50 py-1 text-sm last:border-0">
                            <span class="flex items-center gap-2 text-neutral-600">
                                <span class="h-2.5 w-2.5 rounded-full {{ $segment['dot'] }}"></span>
                                {{ $segment['label'] }}
                            </span>
                            <span class="font-semibold text-neutral-900">{{ $segment['count'] }}</span>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="card lg:col-span-5">
                <h2 class="mb-4 text-sm font-semibold text-neutral-900">This week</h2>
                <x-bar-chart :height="110" :data="collect([
                    ['label' => 'New requests', 'value' => $this->stats['requestsThisWeek'], 'valueLabel' => $this->stats['requestsThisWeek']],
                    ['label' => 'Converted', 'value' => $this->stats['convertedThisWeek'], 'valueLabel' => $this->stats['convertedThisWeek']],
                ])" />
            </div>
        </div>

        <div class="grid items-start gap-4 lg:grid-cols-2">
            <div class="card">
                <h2 class="mb-3 text-sm font-semibold text-neutral-900">Customer intake, today</h2>
                <div class="divide-y divide-neutral-50">
                    @forelse ($this->stats['recentRequests'] as $request)
                        <a href="/requests/{{ $request->id }}" wire:navigate class="flex items-center justify-between py-2.5 text-sm hover:text-primary-600">
                            <span class="text-neutral-900">{{ $request->reference }} &middot; {{ $request->customer->name }}</span>
                            @php
                                $pill = match (true) {
                                    $request->status === 'Converted' => 'pill-success',
                                    in_array($request->status, ['Assigned', 'Quoted']) => 'pill-info',
                                    $request->status === 'Declined' => 'pill-danger',
                                    default => 'pill-neutral',
                                };
                            @endphp
                            <span class="{{ $pill }}">{{ $request->status }}</span>
                        </a>
                    @empty
                        <p class="py-2 text-sm text-neutral-500">No requests yet.</p>
                    @endforelse
                </div>
            </div>

            <div class="card">
                <h2 class="mb-3 text-sm font-semibold text-neutral-900">Recent reports</h2>
                <div class="divide-y divide-neutral-50">
                    @forelse ($this->stats['recentReports'] as $report)
                        <a href="/documents/{{ $report->id }}" wire:navigate class="flex items-center justify-between py-2.5 text-sm hover:text-primary-600">
                            <span class="text-neutral-900">{{ $report->reference }} &middot; {{ $report->customer->name }}</span>
                            @php
                                $pill = match (true) {
                                    $report->status === 'Released' => 'pill-success',
                                    in_array($report->status, ['Awaiting review', 'Checked, ready to post']) => 'pill-info',
                                    default => 'pill-neutral',
                                };
                            @endphp
                            <span class="{{ $pill }}">{{ $report->status }}</span>
                        </a>
                    @empty
                        <p class="py-2 text-sm text-neutral-500">No reports yet.</p>
                    @endforelse
                </div>
            </div>
        </div>

    @elseif ($this->stats['role'] === 'Supervisor')
        <div class="mb-5 grid gap-4 lg:grid-cols-12">
            <div class="card-dark lg:col-span-7">
                <div class="flex items-start justify-between gap-6">
                    <div class="shrink-0">
                        <p class="text-[11px] font-semibold uppercase tracking-widest text-white/40">Awaiting your approval</p>
                        <p class="mt-1.5 text-5xl font-bold leading-none text-white">{{ $this->stats['quotationsAwaiting']->count() }}</p>
                        <p class="mt-2 text-xs text-white/40">{{ $this->stats['approvalsThisWeek'] }} decided this week</p>
                        <a href="/quotations" wire:navigate class="mt-4 inline-flex items-center gap-2 rounded-[var(--radius-md)] bg-white/10 px-4 py-2.5 text-xs font-semibold text-white hover:bg-white/20">
                            View all <x-icon name="arrow-right" class="h-3.5 w-3.5" />
                        </a>
                    </div>
                    <div class="flex-1 border-l border-white/10 pl-6">
                        <p class="mb-2 text-[11px] font-semibold uppercase tracking-widest text-white/30">Pipeline value</p>
                        <p class="text-3xl font-bold text-white">KES {{ number_format($this->stats['pipelineValueMinor'] / 100, 0) }}</p>
                        <p class="mt-2 text-xs text-white/50">Total across quotations waiting on your decision</p>
                    </div>
                </div>
            </div>

            <div class="flex flex-col gap-3 lg:col-span-5">
                <div class="card-compact flex-1">
                    <div class="flex items-center justify-between">
                        <span class="icon-badge-sm icon-badge-primary"><x-icon name="exclamation-circle" class="h-4 w-4" /></span>
                        <span class="text-2xl font-bold leading-none text-neutral-900">{{ $this->stats['overdueJobsList']->count() }}</span>
                    </div>
                    <p class="mt-2 text-xs font-medium text-neutral-500">Overdue jobs</p>
                </div>
                <div class="card-compact flex-1">
                    <div class="flex items-center justify-between">
                        <span class="icon-badge-sm icon-badge-success"><x-icon name="check2-circle" class="h-4 w-4" /></span>
                        <span class="text-2xl font-bold leading-none text-neutral-900">{{ $this->stats['jobsReadyToClose'] }}</span>
                    </div>
                    <p class="mt-2 text-xs font-medium text-neutral-500">Jobs ready to close</p>
                </div>
                <div class="card-compact flex-1">
                    <div class="flex items-center justify-between">
                        <span class="icon-badge-sm icon-badge-info"><x-icon name="folder" class="h-4 w-4" /></span>
                        <span class="text-2xl font-bold leading-none text-neutral-900">{{ $this->stats['reportsAwaitingReview']->count() }}</span>
                    </div>
                    <p class="mt-2 text-xs font-medium text-neutral-500">Reports to review</p>
                </div>
            </div>
        </div>

        <div class="mb-5">
            <p class="mb-2 text-[11px] font-semibold uppercase tracking-widest text-neutral-400">Compliance &middot; top priority</p>
            <div class="grid items-start gap-4 lg:grid-cols-2">
                <div class="card">
                    <h2 class="mb-4 text-sm font-semibold text-neutral-900">Workload by technician</h2>
                    @if ($this->stats['dispatch']->isNotEmpty())
                        <x-bar-chart :height="110" :data="$this->stats['dispatch']->map(fn ($t) => [
                            'label' => explode(' ', $t->name)[0],
                            'value' => $t->openJobCount,
                            'valueLabel' => $t->openJobCount,
                        ])" />
                    @else
                        <p class="text-sm text-neutral-500">No technicians on record.</p>
                    @endif
                </div>

                <div class="card">
                    <h2 class="mb-1 text-sm font-semibold text-neutral-900">Contracts nearing expiry</h2>
                    <div class="divide-y divide-neutral-100">
                        @forelse ($this->stats['contractsNeedingAttention']->take(4) as $contract)
                            <x-expiry-ring
                                :percent="$contract->percentOfTermUsedCapped()"
                                :stage="$contract->expiryStage()"
                                :title="$contract->reference.' — '.$contract->customer->name"
                                :expiresAt="$contract->ends_at->format('d M Y')" />
                        @empty
                            <p class="py-2 text-sm text-neutral-500">No contracts need attention right now.</p>
                        @endforelse
                    </div>
                    @if ($this->stats['contractsNeedingAttention']->count() > 4)
                        <p class="mt-2 text-xs text-neutral-400">+{{ $this->stats['contractsNeedingAttention']->count() - 4 }} more not shown</p>
                    @endif
                </div>
            </div>
        </div>

        <div class="grid items-start gap-4 lg:grid-cols-2">
            <div class="card">
                <h2 class="mb-3 text-sm font-semibold text-neutral-900">Quotations awaiting approval</h2>
                <div class="divide-y divide-neutral-50">
                    @forelse ($this->stats['quotationsAwaiting'] as $quotation)
                        <a href="/quotations/{{ $quotation->id }}" wire:navigate class="flex items-center justify-between py-2.5 text-sm hover:text-primary-600">
                            <span class="text-neutral-900">{{ $quotation->reference }} &middot; {{ $quotation->customer->name }}</span>
                            <span class="text-xs font-semibold text-neutral-500">KES {{ number_format($quotation->totalMinor() / 100, 0) }}</span>
                        </a>
                    @empty
                        <p class="py-2 text-sm text-neutral-500">Nothing awaiting your approval.</p>
                    @endforelse
                </div>
            </div>

            <div class="card">
                <h2 class="mb-3 text-sm font-semibold text-neutral-900">Reports awaiting review</h2>
                <div class="divide-y divide-neutral-50">
                    @forelse ($this->stats['reportsAwaitingReview'] as $report)
                        <a href="/documents/{{ $report->id }}" wire:navigate class="flex items-center justify-between py-2.5 text-sm hover:text-primary-600">
                            <span class="text-neutral-900">{{ $report->reference }} &middot; {{ $report->customer->name }}</span>
                            <span class="text-xs font-semibold text-neutral-500">{{ $report->workOrder?->reference }}</span>
                        </a>
                    @empty
                        <p class="py-2 text-sm text-neutral-500">Nothing awaiting review.</p>
                    @endforelse
                </div>
            </div>
        </div>

    @elseif ($this->stats['role'] === 'Manager')
        <div class="mb-5 grid gap-4 lg:grid-cols-12">
            <div class="card-dark lg:col-span-7">
                <div class="flex items-start justify-between gap-6">
                    <div class="shrink-0">
                        <p class="text-[11px] font-semibold uppercase tracking-widest text-white/40">Revenue this month</p>
                        <p class="mt-1.5 text-4xl font-bold leading-none text-white">KES {{ number_format($this->stats['revenueThisMonthMinor'] / 100, 0) }}</p>
                        <p class="mt-2 text-xs {{ $this->stats['revenueDeltaPct'] === null ? 'text-white/40' : ($this->stats['revenueDeltaPct'] >= 0 ? 'text-success-400' : 'text-primary-400') }}">
                            @if ($this->stats['revenueDeltaPct'] !== null)
                                {{ $this->stats['revenueDeltaPct'] >= 0 ? '↑' : '↓' }} {{ abs($this->stats['revenueDeltaPct']) }}% on last month
                            @else
                                No prior month to compare
                            @endif
                        </p>
                        <div class="mt-4 w-40">
                            <x-sparkline :values="array_map(fn ($v) => $v / 100, $this->stats['monthlyRevenueTrend'])" color="var(--color-success-400)" />
                        </div>
                        <p class="mt-1 text-[10px] uppercase tracking-wide text-white/30">Last 6 months</p>
                    </div>
                    <div class="flex-1 border-l border-white/10 pl-6">
                        <p class="text-[11px] font-semibold uppercase tracking-widest text-white/30">Jobs closed</p>
                        <p class="mt-1.5 text-4xl font-bold leading-none text-white">{{ $this->stats['jobsClosedThisMonth'] }}</p>
                        <p class="mt-2 text-xs text-white/40">this month, across the whole team</p>
                    </div>
                </div>
            </div>

            <div class="flex flex-col gap-4 lg:col-span-5">
                <div class="card flex-1">
                    <div class="flex items-center justify-between">
                        <span class="icon-badge-sm icon-badge-amber"><x-icon name="journal-text" class="h-4 w-4" /></span>
                        <span class="text-3xl font-bold leading-none text-neutral-900">{{ $this->stats['quotationsAwaiting']->count() }}</span>
                    </div>
                    <p class="mt-3 text-sm font-semibold text-neutral-900">Needs your approval</p>
                    <p class="text-xs text-neutral-500">KES {{ number_format($this->stats['pipelineValueMinor'] / 100, 0) }} total</p>
                    <a href="/quotations" wire:navigate class="mt-1 inline-flex items-center gap-1 text-xs font-semibold text-amber-700 hover:text-amber-800">
                        Review approvals <x-icon name="arrow-right" class="h-3 w-3" />
                    </a>
                </div>
                <div class="card flex-1">
                    <div class="flex items-center justify-between">
                        <span class="icon-badge-sm icon-badge-primary"><x-icon name="exclamation-circle" class="h-4 w-4" /></span>
                        <span class="text-3xl font-bold leading-none text-neutral-900">{{ $this->stats['overdueJobs'] }}</span>
                    </div>
                    <p class="mt-3 text-sm font-semibold text-neutral-900">Overdue jobs</p>
                    <a href="/jobs" wire:navigate class="mt-1 inline-flex items-center gap-1 text-xs font-semibold text-primary-600 hover:text-primary-700">
                        View jobs <x-icon name="arrow-right" class="h-3 w-3" />
                    </a>
                </div>
            </div>
        </div>

        <div class="mb-5">
            <p class="mb-2 text-[11px] font-semibold uppercase tracking-widest text-neutral-400">Compliance &middot; top priority</p>
            <div class="grid items-start gap-4 lg:grid-cols-2">
                <div class="card">
                    <h2 class="mb-4 text-sm font-semibold text-neutral-900">Technician performance</h2>
                    @if ($this->stats['technicianPerformance']->isNotEmpty())
                        <x-bar-chart :height="110" :data="$this->stats['technicianPerformance']->map(fn ($t) => [
                            'label' => explode(' ', $t->name)[0],
                            'value' => $t->revenueMinor,
                            'valueLabel' => number_format($t->revenueMinor / 100000, 0).'K',
                        ])" />
                    @else
                        <p class="text-sm text-neutral-500">No invoiced work yet.</p>
                    @endif
                </div>

                <div class="card">
                    <h2 class="mb-1 text-sm font-semibold text-neutral-900">Contracts nearing expiry</h2>
                    <div class="divide-y divide-neutral-100">
                        @forelse ($this->stats['contractsNeedingAttention']->take(4) as $contract)
                            <x-expiry-ring
                                :percent="$contract->percentOfTermUsedCapped()"
                                :stage="$contract->expiryStage()"
                                :title="$contract->reference.' — '.$contract->customer->name"
                                :expiresAt="$contract->ends_at->format('d M Y')" />
                        @empty
                            <p class="py-2 text-sm text-neutral-500">No contracts need attention right now.</p>
                        @endforelse
                    </div>
                    @if ($this->stats['contractsNeedingAttention']->count() > 4)
                        <p class="mt-2 text-xs text-neutral-400">+{{ $this->stats['contractsNeedingAttention']->count() - 4 }} more not shown</p>
                    @endif
                </div>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-3">
            <div class="card-compact">
                <span class="icon-badge-sm icon-badge-success"><x-icon name="people" class="h-4 w-4" /></span>
                <p class="mt-2.5 text-2xl font-bold text-neutral-900">{{ $this->stats['totalCustomers'] }}</p>
                <p class="mt-0.5 text-xs font-medium text-neutral-500">Customers</p>
            </div>
            <div class="card-compact">
                <span class="icon-badge-sm icon-badge-info"><x-icon name="tools" class="h-4 w-4" /></span>
                <p class="mt-2.5 text-2xl font-bold text-neutral-900">{{ $this->stats['totalTechnicians'] }}</p>
                <p class="mt-0.5 text-xs font-medium text-neutral-500">Technicians</p>
            </div>
        </div>

    @elseif ($this->stats['role'] === 'Technician')
        @if ($this->stats['nextJob'])
            <div class="card-dark mb-5">
                <p class="text-[11px] font-semibold uppercase tracking-widest text-white/40">Next stop</p>
                <div class="mt-3 grid gap-6 md:grid-cols-2">
                    <div>
                        <p class="text-xs text-white/40">Job</p>
                        <p class="text-xl font-bold text-white">{{ $this->stats['nextJob']->reference }}</p>
                        <p class="mt-3 text-xs text-white/40">Customer</p>
                        <p class="font-semibold text-white">{{ $this->stats['nextJob']->customer->name }}</p>
                        <p class="mt-3 text-xs text-white/40">Due</p>
                        <p class="font-semibold text-white">{{ $this->stats['nextJob']->due_date->format('D, d M') }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-white/40">Nature of visit</p>
                        <p class="font-semibold text-white">{{ $this->stats['nextJob']->nature_of_visit }}</p>
                        @if ($this->stats['nextJob']->sourceRequest)
                            <p class="mt-3 text-xs text-white/40">Fault reported</p>
                            <p class="text-sm text-white/80">{{ $this->stats['nextJob']->sourceRequest->fault_description }}</p>
                        @endif
                        <div class="mt-4">
                            @if ($this->stats['nextJob']->status === 'On site')
                                <a href="/jobs/{{ $this->stats['nextJob']->id }}/report" wire:navigate class="btn-primary">
                                    File service report
                                </a>
                            @else
                                <a href="/jobs/{{ $this->stats['nextJob']->id }}" wire:navigate class="inline-flex items-center gap-2 rounded-[var(--radius-md)] bg-white/10 px-4 py-2.5 text-sm font-medium text-white hover:bg-white/20">
                                    Open job <x-icon name="arrow-right" class="h-3.5 w-3.5" />
                                </a>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        @endif

        <div class="mb-5">
            <p class="mb-2 text-[11px] font-semibold uppercase tracking-widest text-neutral-400">Your compliance documents &middot; top priority</p>
            <div class="card">
                <div class="grid gap-x-4 divide-y divide-neutral-100 md:grid-cols-2 md:divide-y-0">
                    @forelse ($this->stats['myDocuments']->take(4) as $doc)
                        <x-expiry-ring
                            :percent="$doc->percentUsedCapped()"
                            :stage="$doc->expiryStage()"
                            :title="$doc->document_type"
                            :expiresAt="$doc->expiresAt()->format('d M Y')" />
                    @empty
                        <p class="py-2 text-sm text-neutral-500">No documents on file.</p>
                    @endforelse
                </div>
                @if ($this->stats['myDocuments']->count() > 4)
                    <p class="mt-2 text-xs text-neutral-400">+{{ $this->stats['myDocuments']->count() - 4 }} more not shown</p>
                @endif
            </div>
        </div>

        <div class="mb-5 grid grid-cols-2 gap-3">
            <div class="card-compact">
                <span class="icon-badge-sm icon-badge-primary"><x-icon name="exclamation-circle" class="h-4 w-4" /></span>
                <p class="mt-2.5 text-2xl font-bold text-neutral-900">{{ $this->stats['dueTodayOrOverdue']->count() }}</p>
                <p class="mt-0.5 text-xs font-medium text-neutral-500">Due today or overdue</p>
            </div>
            <div class="card-compact">
                <span class="icon-badge-sm icon-badge-success"><x-icon name="check2-circle" class="h-4 w-4" /></span>
                <p class="mt-2.5 text-2xl font-bold text-neutral-900">{{ $this->stats['jobsClosedThisMonth'] }}</p>
                <p class="mt-0.5 text-xs font-medium text-neutral-500">Closed this month</p>
            </div>
        </div>

        <div class="card">
            <h2 class="mb-3 text-sm font-semibold text-neutral-900">Rest of my list</h2>
            <div class="divide-y divide-neutral-50">
                @forelse ($this->stats['myJobs']->slice(1) as $job)
                    <a href="/jobs/{{ $job->id }}" wire:navigate class="flex items-center justify-between py-2.5 text-sm hover:text-primary-600">
                        <span class="text-neutral-900">{{ $job->reference }} &middot; {{ $job->customer->name }}</span>
                        <span class="flex items-center gap-3">
                            <span class="text-xs text-neutral-400">Due {{ $job->due_date->format('d M') }}</span>
                            @php
                                $pill = match (true) {
                                    $job->status === 'Approved' => 'pill-success',
                                    in_array($job->status, ['On site', 'Awaiting review']) => 'pill-info',
                                    default => 'pill-neutral',
                                };
                            @endphp
                            <span class="{{ $pill }}">{{ $job->status }}</span>
                        </span>
                    </a>
                @empty
                    <p class="py-2 text-sm text-neutral-500">Nothing else on your list.</p>
                @endforelse
            </div>
        </div>

    @elseif ($this->stats['role'] === 'Finance')
        <div class="mb-5 grid items-stretch gap-4 lg:grid-cols-2">
            <div class="card" style="border-left: 4px solid var(--color-critical-500)">
                <div class="flex items-center gap-2">
                    <span class="icon-badge-sm" style="background-color: var(--color-critical-50); color: var(--color-critical-700)"><x-icon name="receipt" class="h-4 w-4" /></span>
                    <p class="text-[11px] font-semibold uppercase tracking-widest text-critical-700">Outstanding</p>
                </div>
                <p class="mt-3 text-4xl font-bold leading-none text-critical-700">KES {{ number_format($this->stats['outstandingBalanceMinor'] / 100, 0) }}</p>
                <p class="mt-2 text-xs text-neutral-500">Across every unpaid and part-paid invoice on file</p>
            </div>

            <div class="card" style="border-left: 4px solid var(--color-fresh-500)">
                <div class="flex items-center gap-2">
                    <span class="icon-badge-sm" style="background-color: var(--color-fresh-50); color: var(--color-fresh-700)"><x-icon name="cart-check" class="h-4 w-4" /></span>
                    <p class="text-[11px] font-semibold uppercase tracking-widest text-fresh-700">Collected</p>
                </div>
                <p class="mt-3 text-4xl font-bold leading-none text-fresh-700">KES {{ number_format($this->stats['paidThisMonthMinor'] / 100, 0) }}</p>
                <p class="mt-2 text-xs text-neutral-500">In payments recorded so far this month</p>
            </div>
        </div>

        <div class="mb-5 grid grid-cols-2 gap-3">
            <a href="/invoices" wire:navigate class="card-compact hover:border-info-300">
                <span class="icon-badge-sm icon-badge-info"><x-icon name="receipt" class="h-4 w-4" /></span>
                <p class="mt-2.5 text-2xl font-bold text-neutral-900">{{ $this->stats['readyToInvoice'] }}</p>
                <p class="mt-0.5 text-xs font-medium text-neutral-500">
                    Ready to invoice
                    @if ($this->stats['readyToInvoiceValueMinor'] > 0)
                        &middot; KES {{ number_format($this->stats['readyToInvoiceValueMinor'] / 100, 0) }}
                    @endif
                </p>
            </a>
            <div class="card-compact">
                <span class="icon-badge-sm icon-badge-amber"><x-icon name="exclamation-circle" class="h-4 w-4" /></span>
                <p class="mt-2.5 text-2xl font-bold text-amber-700">{{ $this->stats['overdueInvoices']->count() }}</p>
                <p class="mt-0.5 text-xs font-medium text-neutral-500">Overdue invoices</p>
            </div>
        </div>

        @php
            $ageingBars = [
                ['label' => 'Current', 'value' => $this->stats['ageing']['current'], 'color' => 'var(--color-fresh-500)'],
                ['label' => '1-30 days', 'value' => $this->stats['ageing']['days1to30'], 'color' => 'var(--color-warn-500)'],
                ['label' => '31-60 days', 'value' => $this->stats['ageing']['days31to60'], 'color' => 'var(--color-urgent-500)'],
                ['label' => '60+ days', 'value' => $this->stats['ageing']['days60plus'], 'color' => 'var(--color-critical-500)'],
            ];
            $ageingMax = max(1, max(array_column($ageingBars, 'value') ?: [0]));
        @endphp
        <div class="card mb-5">
            <h2 class="mb-4 text-sm font-semibold text-neutral-900">Outstanding, by age</h2>
            <div class="flex items-end gap-6" style="height: 160px">
                @foreach ($ageingBars as $bar)
                    <div class="flex flex-1 flex-col items-center gap-2">
                        <span class="text-xs font-semibold text-neutral-700">KES {{ number_format($bar['value'] / 100, 0) }}</span>
                        <div class="w-full rounded-t-[var(--radius-sm)]" style="height: {{ max(4, $bar['value'] / $ageingMax * 110) }}px; background-color: {{ $bar['color'] }}"></div>
                        <span class="text-xs text-neutral-500 text-center">{{ $bar['label'] }}</span>
                    </div>
                @endforeach
            </div>
        </div>

        @if ($this->stats['overdueInvoices']->isNotEmpty())
            <div class="card mb-5" style="background-color: var(--color-critical-50); border-color: #f5c6cb">
                <h2 class="mb-3 flex items-center gap-1.5 text-sm font-semibold text-critical-700">
                    <x-icon name="exclamation-circle" class="h-4 w-4" /> Overdue, past their due date
                </h2>
                <div class="divide-y divide-critical-100">
                    @foreach ($this->stats['overdueInvoices'] as $invoice)
                        <a href="/invoices/{{ $invoice->id }}" wire:navigate class="flex items-center justify-between py-2.5 text-sm hover:underline">
                            <span class="text-critical-700">{{ $invoice->reference }} &middot; {{ $invoice->customer->name }}</span>
                            <span class="font-semibold text-critical-700">KES {{ number_format($invoice->balanceMinor() / 100, 0) }}</span>
                        </a>
                    @endforeach
                </div>
            </div>
        @endif

        <div class="grid items-start gap-4 lg:grid-cols-2">
            <div class="card">
                <h2 class="mb-3 text-sm font-semibold text-neutral-900">Recent invoices</h2>
                <div class="divide-y divide-neutral-50">
                    @forelse ($this->stats['recentInvoices'] as $invoice)
                        <a href="/invoices/{{ $invoice->id }}" wire:navigate class="flex items-center justify-between py-2.5 text-sm hover:text-primary-600">
                            <span class="text-neutral-900">{{ $invoice->reference }} &middot; {{ $invoice->customer->name }}</span>
                            @php
                                $amountClass = match (true) {
                                    $invoice->status === 'Paid' => 'text-fresh-700',
                                    in_array($invoice->status, ['Unpaid', 'Part paid']) => 'text-critical-700',
                                    default => 'text-neutral-500',
                                };
                            @endphp
                            <span class="text-xs font-semibold {{ $amountClass }}">KES {{ number_format($invoice->amount_minor / 100, 0) }}</span>
                        </a>
                    @empty
                        <p class="py-2 text-sm text-neutral-500">No invoices yet.</p>
                    @endforelse
                </div>
            </div>

            <div class="card">
                <h2 class="mb-3 text-sm font-semibold text-neutral-900">Recent payments</h2>
                <div class="divide-y divide-neutral-50">
                    @forelse ($this->stats['recentPayments'] as $payment)
                        <div class="flex items-center justify-between py-2.5 text-sm">
                            <span class="text-neutral-900">{{ $payment->reference }} &middot; {{ $payment->customer->name }}</span>
                            <span class="text-xs font-semibold text-fresh-700">+KES {{ number_format($payment->amount_minor / 100, 0) }}</span>
                        </div>
                    @empty
                        <p class="py-2 text-sm text-neutral-500">No payments yet.</p>
                    @endforelse
                </div>
            </div>
        </div>

    @elseif ($this->stats['role'] === 'Customer')
        <div class="mb-5 grid gap-4 lg:grid-cols-12">
            <div class="card-dark lg:col-span-7">
                <p class="text-[11px] font-semibold uppercase tracking-widest text-white/40">Open requests</p>
                <p class="mt-1.5 text-5xl font-bold leading-none text-white">{{ $this->stats['openRequestsCount'] }}</p>
                <p class="mt-2 text-xs text-white/40">
                    @if ($this->stats['nextJob'])
                        Next visit: {{ $this->stats['nextJob']->reference }}, due {{ $this->stats['nextJob']->due_date->format('d M Y') }}
                    @else
                        No jobs currently in progress
                    @endif
                </p>
                <a href="/requests" wire:navigate class="mt-4 inline-flex items-center gap-2 rounded-[var(--radius-md)] bg-white/10 px-4 py-2.5 text-xs font-semibold text-white hover:bg-white/20">
                    View requests <x-icon name="arrow-right" class="h-3.5 w-3.5" />
                </a>
            </div>

            <div class="flex flex-col gap-4 lg:col-span-5">
                <div class="card flex-1">
                    <div class="flex items-center justify-between">
                        <span class="icon-badge-sm icon-badge-primary"><x-icon name="tools" class="h-4 w-4" /></span>
                        <span class="text-3xl font-bold leading-none text-neutral-900">{{ $this->stats['activeJobs']->count() }}</span>
                    </div>
                    <p class="mt-3 text-sm font-semibold text-neutral-900">Active jobs</p>
                    <a href="/jobs" wire:navigate class="mt-1 inline-flex items-center gap-1 text-xs font-semibold text-primary-600 hover:text-primary-700">
                        View jobs <x-icon name="arrow-right" class="h-3 w-3" />
                    </a>
                </div>
                <div class="card flex-1">
                    <div class="flex items-center justify-between">
                        <span class="icon-badge-sm icon-badge-amber"><x-icon name="receipt" class="h-4 w-4" /></span>
                        <span class="text-3xl font-bold leading-none text-neutral-900">KES {{ number_format($this->stats['outstandingBalanceMinor'] / 100, 0) }}</span>
                    </div>
                    <p class="mt-3 text-sm font-semibold text-neutral-900">Outstanding balance</p>
                    <a href="/invoices" wire:navigate class="mt-1 inline-flex items-center gap-1 text-xs font-semibold text-amber-700 hover:text-amber-800">
                        @if ($this->stats['overdueInvoicesCount'] > 0)
                            {{ $this->stats['overdueInvoicesCount'] }} overdue
                        @else
                            View invoices
                        @endif
                        <x-icon name="arrow-right" class="h-3 w-3" />
                    </a>
                </div>
            </div>
        </div>

        @if ($this->stats['contract'])
            <div class="mb-5">
                <p class="mb-2 text-[11px] font-semibold uppercase tracking-widest text-neutral-400">Your contract</p>
                <div class="card">
                    <x-expiry-ring
                        :percent="$this->stats['contract']->percentOfTermUsedCapped()"
                        :stage="$this->stats['contract']->expiryStage()"
                        :title="$this->stats['contract']->reference.' &middot; '.$this->stats['contract']->type"
                        :expiresAt="$this->stats['contract']->ends_at->format('d M Y')" />
                </div>
            </div>
        @endif

        <div class="grid items-start gap-4 lg:grid-cols-2">
            <div class="card">
                <h2 class="mb-3 text-sm font-semibold text-neutral-900">Recent requests</h2>
                <div class="divide-y divide-neutral-50">
                    @forelse ($this->stats['recentRequests'] as $request)
                        <a href="/requests/{{ $request->id }}" wire:navigate class="flex items-center justify-between py-2.5 text-sm hover:text-primary-600">
                            <span class="min-w-0 truncate text-neutral-900">{{ $request->reference }} &middot; {{ $request->fault_description }}</span>
                            @php
                                $pill = match (true) {
                                    $request->status === 'Converted' => 'pill-success',
                                    in_array($request->status, ['Assigned', 'Quoted']) => 'pill-info',
                                    $request->status === 'Declined' => 'pill-danger',
                                    default => 'pill-neutral',
                                };
                            @endphp
                            <span class="{{ $pill }} ml-2 shrink-0">{{ $request->status }}</span>
                        </a>
                    @empty
                        <p class="py-2 text-sm text-neutral-500">No requests yet.</p>
                    @endforelse
                </div>
            </div>

            <div class="card">
                <h2 class="mb-3 text-sm font-semibold text-neutral-900">Recent documents</h2>
                <div class="divide-y divide-neutral-50">
                    @forelse ($this->stats['recentDocuments'] as $document)
                        <a href="/documents/{{ $document->id }}" wire:navigate class="flex items-center justify-between py-2.5 text-sm hover:text-primary-600">
                            <span class="text-neutral-900">{{ $document->reference }}</span>
                            <span class="text-xs text-neutral-500">
                                @php
                                    $docLabels = [
                                        'rep' => 'Service report',
                                        'cert' => 'Calibration certificate',
                                        'mv' => 'Maintenance voucher',
                                        'dn' => 'Delivery note',
                                    ];
                                @endphp
                                {{ $docLabels[$document->type] ?? ucfirst($document->type) }}
                            </span>
                        </a>
                    @empty
                        <p class="py-2 text-sm text-neutral-500">No documents yet.</p>
                    @endforelse
                </div>
            </div>
        </div>

        <div class="card mt-5">
            <h2 class="mb-3 text-sm font-semibold text-neutral-900">Recent invoices</h2>
            <div class="divide-y divide-neutral-50">
                @forelse ($this->stats['recentInvoices'] as $invoice)
                    <a href="/invoices/{{ $invoice->id }}" wire:navigate class="flex items-center justify-between py-2.5 text-sm hover:text-primary-600">
                        <span class="text-neutral-900">{{ $invoice->reference }} &middot; due {{ $invoice->due_at->format('d M Y') }}</span>
                        @php
                            $amountClass = match (true) {
                                $invoice->status === 'Paid' => 'text-fresh-700',
                                in_array($invoice->status, ['Unpaid', 'Part paid']) => 'text-critical-700',
                                default => 'text-neutral-500',
                            };
                        @endphp
                        <span class="text-xs font-semibold {{ $amountClass }}">KES {{ number_format($invoice->amount_minor / 100, 0) }}</span>
                    </a>
                @empty
                    <p class="py-2 text-sm text-neutral-500">No invoices yet.</p>
                @endforelse
            </div>
        </div>
    @endif
</div>
