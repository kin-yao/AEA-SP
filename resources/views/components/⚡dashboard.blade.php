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
            ['label' => 'Assigned', 'count' => (int) $jobCounts->get('Assigned', 0), 'stroke' => 'stroke-gray-400', 'dot' => 'bg-gray-400'],
            ['label' => 'On site', 'count' => (int) $jobCounts->get('On site', 0), 'stroke' => 'stroke-amber-400', 'dot' => 'bg-amber-400'],
            ['label' => 'Awaiting review', 'count' => (int) $jobCounts->get('Awaiting review', 0), 'stroke' => 'stroke-info-500', 'dot' => 'bg-info-500'],
            ['label' => 'Approved', 'count' => (int) $jobCounts->get('Approved', 0), 'stroke' => 'stroke-violet-400', 'dot' => 'bg-violet-400'],
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
};
?>
<div>
    <div class="mb-5">
        <p class="text-xs font-semibold uppercase tracking-wider text-primary-600">{{ auth()->user()->getRoleNames()->first() }}</p>
        <h1 class="mt-0.5 text-2xl font-semibold text-gray-900">
            Good {{ now()->hour < 12 ? 'morning' : (now()->hour < 17 ? 'afternoon' : 'evening') }}, {{ explode(' ', auth()->user()->name)[0] }}
        </h1>
    </div>

    @if (! $this->stats)
        <div class="border border-gray-200 bg-white p-6 shadow-sm">
            <p class="text-sm text-gray-500">
                You're signed in. A dedicated dashboard for {{ auth()->user()->getRoleNames()->first() }} hasn't been built yet.
            </p>
        </div>

    @elseif ($this->stats['role'] === 'Service Admin')
        <div class="mb-4 grid items-start gap-4 lg:grid-cols-12">
            <div class="bg-gray-900 p-6 shadow-sm lg:col-span-7">
                <div class="flex items-start justify-between gap-6">
                    <div class="shrink-0">
                        <p class="text-[11px] font-semibold uppercase tracking-widest text-white/40">Requests to triage</p>
                        <p class="mt-1.5 text-5xl font-bold leading-none text-white">{{ $this->stats['openRequests']->count() }}</p>
                        <p class="mt-2 text-xs text-white/40">{{ $this->stats['requestsThisWeek'] }} logged this week</p>
                        <a href="/requests" wire:navigate class="mt-4 inline-block border border-white/20 px-3.5 py-2 text-xs font-semibold text-white hover:bg-white hover:text-gray-900">
                            View all &rarr;
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

            <div class="border-l-4 border-info-500 bg-white p-5 shadow-sm lg:col-span-5">
                <span class="flex h-9 w-9 items-center justify-center bg-info-500 text-white">
                    <x-icon name="folder" class="h-4 w-4" />
                </span>
                <p class="mt-3 text-[11px] font-semibold uppercase tracking-widest text-gray-400">Ready to post</p>
                <p class="mt-1 text-4xl font-bold leading-none text-gray-900">{{ $this->stats['reportsReadyToPost'] }}</p>
                <a href="/documents" wire:navigate class="mt-3 inline-flex items-center gap-1 text-sm font-semibold text-info-600 hover:text-info-700">
                    Review and post <x-icon name="arrow-right" class="h-3.5 w-3.5" />
                </a>
            </div>
        </div>

        <div class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div class="border-l-4 border-info-400 bg-white p-4 shadow-sm">
                <p class="text-3xl font-bold text-gray-900">{{ $this->stats['quotationsAwaitingLpo'] }}</p>
                <p class="mt-0.5 text-[11px] font-medium uppercase tracking-wide text-gray-400">Awaiting LPO</p>
            </div>
            <div class="border-l-4 border-info-400 bg-white p-4 shadow-sm">
                <p class="text-3xl font-bold text-gray-900">{{ $this->stats['readyToInvoice'] }}</p>
                <p class="mt-0.5 text-[11px] font-medium uppercase tracking-wide text-gray-400">To invoice</p>
            </div>
            <div class="border-l-4 border-primary-500 bg-white p-4 shadow-sm">
                <p class="text-3xl font-bold text-gray-900">{{ $this->stats['overdueJobs'] }}</p>
                <p class="mt-0.5 text-[11px] font-medium uppercase tracking-wide text-gray-400">Overdue jobs</p>
            </div>
            <div class="border-l-4 border-success-500 bg-white p-4 shadow-sm">
                <p class="text-3xl font-bold text-gray-900">{{ $this->stats['totalCustomers'] }}</p>
                <p class="mt-0.5 text-[11px] font-medium uppercase tracking-wide text-gray-400">Customers</p>
            </div>
        </div>

        <div class="mb-4 grid items-start gap-4 lg:grid-cols-12">
            <div class="flex items-center gap-6 border border-gray-200 bg-white p-5 shadow-sm lg:col-span-7">
                <svg viewBox="0 0 100 100" class="h-28 w-28 shrink-0 -rotate-90">
                    <circle cx="50" cy="50" r="42" fill="none" stroke-width="12" class="stroke-gray-100" />
                    @foreach ($this->stats['jobSegments'] as $segment)
                        @if ($segment['count'] > 0)
                            <circle cx="50" cy="50" r="42" fill="none" stroke-width="12" stroke-linecap="butt"
                                    class="{{ $segment['stroke'] }}"
                                    stroke-dasharray="{{ $segment['dasharray'] }}"
                                    stroke-dashoffset="{{ $segment['dashoffset'] }}" />
                        @endif
                    @endforeach
                    <text x="50" y="54" text-anchor="middle" transform="rotate(90 50 50)" class="fill-gray-900 text-[22px] font-bold">{{ $this->stats['jobTotal'] }}</text>
                </svg>
                <div class="flex-1">
                    <p class="mb-2.5 text-[11px] font-semibold uppercase tracking-widest text-gray-400">Jobs by stage</p>
                    @foreach ($this->stats['jobSegments'] as $segment)
                        <div class="flex items-center justify-between border-b border-gray-50 py-1 text-sm last:border-0">
                            <span class="flex items-center gap-2 text-gray-600">
                                <span class="h-2.5 w-2.5 {{ $segment['dot'] }}"></span>
                                {{ $segment['label'] }}
                            </span>
                            <span class="font-semibold text-gray-900">{{ $segment['count'] }}</span>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="grid grid-cols-3 gap-3 lg:col-span-5">
                <a href="/quotations/create" wire:navigate class="flex flex-col items-center justify-center gap-2 border border-gray-200 bg-white p-4 text-center shadow-sm hover:border-info-400 hover:bg-info-50">
                    <span class="flex h-9 w-9 items-center justify-center bg-info-50 text-info-600">
                        <x-icon name="journal-text" class="h-4 w-4" />
                    </span>
                    <p class="text-xs font-semibold text-gray-900">New quote</p>
                </a>
                <a href="/jobs" wire:navigate class="flex flex-col items-center justify-center gap-2 border border-gray-200 bg-white p-4 text-center shadow-sm hover:border-info-400 hover:bg-info-50">
                    <span class="flex h-9 w-9 items-center justify-center bg-info-50 text-info-600">
                        <x-icon name="tools" class="h-4 w-4" />
                    </span>
                    <p class="text-xs font-semibold text-gray-900">Jobs</p>
                </a>
                <a href="/invoices" wire:navigate class="flex flex-col items-center justify-center gap-2 border border-gray-200 bg-white p-4 text-center shadow-sm hover:border-info-400 hover:bg-info-50">
                    <span class="flex h-9 w-9 items-center justify-center bg-info-50 text-info-600">
                        <x-icon name="receipt" class="h-4 w-4" />
                    </span>
                    <p class="text-xs font-semibold text-gray-900">Invoices</p>
                </a>
            </div>
        </div>

        <p class="mb-2 text-[11px] font-semibold uppercase tracking-widest text-gray-400">Compliance</p>
        <div class="mb-4 grid items-start gap-4 lg:grid-cols-2">
            <div class="border border-gray-200 bg-white p-5 shadow-sm">
                <h2 class="mb-3 text-sm font-semibold text-gray-900">Contracts nearing expiry</h2>
                <div class="space-y-3">
                    @forelse ($this->stats['contractsNeedingAttention'] as $contract)
                        <x-expiry-ring
                            :percent="$contract->percentOfTermUsedCapped()"
                            :stage="$contract->expiryStage()"
                            :title="$contract->reference.' — '.$contract->customer->name"
                            :subtitle="$contract->percentOfTermUsedCapped().'% of term elapsed'" />
                    @empty
                        <p class="text-sm text-gray-500">No contracts need attention right now.</p>
                    @endforelse
                </div>
            </div>

            <div class="border border-gray-200 bg-white p-5 shadow-sm">
                <h2 class="mb-3 text-sm font-semibold text-gray-900">Technician certificates</h2>
                <div class="space-y-3">
                    @forelse ($this->stats['techDocsNeedingAttention'] as $doc)
                        <x-expiry-ring
                            :percent="$doc->percentUsedCapped()"
                            :stage="$doc->expiryStage()"
                            :title="$doc->technician->name.' — '.$doc->document_type"
                            :subtitle="$doc->dueLabel()" />
                    @empty
                        <p class="text-sm text-gray-500">All technician documents are current.</p>
                    @endforelse
                </div>
            </div>
        </div>

        <div class="grid items-start gap-4 lg:grid-cols-2">
            <div class="border border-gray-200 bg-white p-5 shadow-sm">
                <h2 class="mb-3 text-sm font-semibold text-gray-900">Customer intake, today</h2>
                <div class="space-y-0.5">
                    @forelse ($this->stats['recentRequests'] as $request)
                        <a href="/requests/{{ $request->id }}" wire:navigate class="flex items-center justify-between border-b border-gray-50 py-2 text-sm last:border-0 hover:text-primary-600">
                            <span class="text-gray-900">{{ $request->reference }} &middot; {{ $request->customer->name }}</span>
                            <span @class([
                                'px-2 py-0.5 text-[11px] font-semibold',
                                'bg-gray-100 text-gray-600' => $request->status === 'Open',
                                'bg-info-50 text-info-700' => in_array($request->status, ['Assigned', 'Quoted']),
                                'bg-success-50 text-success-700' => $request->status === 'Converted',
                                'bg-primary-50 text-primary-700' => $request->status === 'Declined',
                            ])>
                                {{ $request->status }}
                            </span>
                        </a>
                    @empty
                        <p class="text-sm text-gray-500">No requests yet.</p>
                    @endforelse
                </div>
            </div>

            <div class="border border-gray-200 bg-white p-5 shadow-sm">
                <h2 class="mb-3 text-sm font-semibold text-gray-900">Recent reports</h2>
                <div class="space-y-0.5">
                    @forelse ($this->stats['recentReports'] as $report)
                        <a href="/documents/{{ $report->id }}" wire:navigate class="flex items-center justify-between border-b border-gray-50 py-2 text-sm last:border-0 hover:text-primary-600">
                            <span class="text-gray-900">{{ $report->reference }} &middot; {{ $report->customer->name }}</span>
                            <span @class([
                                'px-2 py-0.5 text-[11px] font-semibold',
                                'bg-gray-100 text-gray-600' => $report->status === 'Draft',
                                'bg-info-50 text-info-700' => in_array($report->status, ['Awaiting review', 'Checked, ready to post']),
                                'bg-success-50 text-success-700' => $report->status === 'Released',
                            ])>
                                {{ $report->status }}
                            </span>
                        </a>
                    @empty
                        <p class="text-sm text-gray-500">No reports yet.</p>
                    @endforelse
                </div>
            </div>
        </div>

    @elseif ($this->stats['role'] === 'Supervisor')
        <div class="mb-4 grid items-start gap-4 lg:grid-cols-12">
            <div class="bg-gray-900 p-6 shadow-sm lg:col-span-7">
                <div class="flex items-start justify-between gap-6">
                    <div class="shrink-0">
                        <p class="text-[11px] font-semibold uppercase tracking-widest text-white/40">Awaiting your approval</p>
                        <p class="mt-1.5 text-5xl font-bold leading-none text-white">{{ $this->stats['quotationsAwaiting']->count() }}</p>
                        <p class="mt-2 text-xs text-white/40">{{ $this->stats['approvalsThisWeek'] }} decided this week</p>
                        <a href="/quotations" wire:navigate class="mt-4 inline-block border border-white/20 px-3.5 py-2 text-xs font-semibold text-white hover:bg-white hover:text-gray-900">
                            View all &rarr;
                        </a>
                    </div>
                    <div class="flex-1 border-l border-white/10 pl-6">
                        <p class="mb-2 text-[11px] font-semibold uppercase tracking-widest text-white/30">Pipeline value</p>
                        <p class="text-3xl font-bold text-white">KES {{ number_format($this->stats['pipelineValueMinor'] / 100, 0) }}</p>
                        <p class="mt-2 text-xs text-white/50">Total across quotations waiting on your decision</p>
                    </div>
                </div>
            </div>

            <div class="border-l-4 border-primary-500 bg-white p-5 shadow-sm lg:col-span-5">
                <span class="flex h-9 w-9 items-center justify-center bg-primary-500 text-white">
                    <x-icon name="tools" class="h-4 w-4" />
                </span>
                <p class="mt-3 text-[11px] font-semibold uppercase tracking-widest text-gray-400">Overdue jobs</p>
                <p class="mt-1 text-4xl font-bold leading-none text-gray-900">{{ $this->stats['overdueJobsList']->count() }}</p>
                @if ($this->stats['overdueJobsList']->isNotEmpty())
                    <a href="/jobs/{{ $this->stats['overdueJobsList']->first()->id }}" wire:navigate class="mt-2 block text-xs text-gray-500 hover:text-primary-600">
                        {{ $this->stats['overdueJobsList']->first()->reference }}, {{ $this->stats['overdueJobsList']->first()->customer->name }}
                    </a>
                @endif
            </div>
        </div>

        <div class="mb-4 grid grid-cols-2 gap-3">
            <div class="border-l-4 border-success-500 bg-white p-4 shadow-sm">
                <p class="text-3xl font-bold text-gray-900">{{ $this->stats['jobsReadyToClose'] }}</p>
                <p class="mt-0.5 text-[11px] font-medium uppercase tracking-wide text-gray-400">Jobs ready to close</p>
            </div>
            <div class="border-l-4 border-info-400 bg-white p-4 shadow-sm">
                <p class="text-3xl font-bold text-gray-900">{{ $this->stats['reportsAwaitingReview']->count() }}</p>
                <p class="mt-0.5 text-[11px] font-medium uppercase tracking-wide text-gray-400">Reports to review</p>
            </div>
        </div>

        <p class="mb-2 text-[11px] font-semibold uppercase tracking-widest text-gray-400">Compliance</p>
        <div class="mb-4 grid items-start gap-4 lg:grid-cols-2">
            <div class="border border-gray-200 bg-white p-5 shadow-sm">
                <h2 class="mb-4 text-sm font-semibold text-gray-900">Workload by technician</h2>
                @if ($this->stats['dispatch']->isNotEmpty())
                    <x-bar-chart :height="110" :data="$this->stats['dispatch']->map(fn ($t) => [
                        'label' => explode(' ', $t->name)[0],
                        'value' => $t->openJobCount,
                        'valueLabel' => $t->openJobCount,
                    ])" />
                @else
                    <p class="text-sm text-gray-500">No technicians on record.</p>
                @endif
            </div>

            <div class="border border-gray-200 bg-white p-5 shadow-sm">
                <h2 class="mb-3 text-sm font-semibold text-gray-900">Contracts nearing expiry</h2>
                <div class="space-y-3">
                    @forelse ($this->stats['contractsNeedingAttention'] as $contract)
                        <x-expiry-ring
                            :percent="$contract->percentOfTermUsedCapped()"
                            :stage="$contract->expiryStage()"
                            :title="$contract->reference.' — '.$contract->customer->name"
                            :subtitle="$contract->percentOfTermUsedCapped().'% of term elapsed'" />
                    @empty
                        <p class="text-sm text-gray-500">No contracts need attention right now.</p>
                    @endforelse
                </div>
            </div>
        </div>

        <div class="grid items-start gap-4 lg:grid-cols-2">
            <div class="border border-gray-200 bg-white p-5 shadow-sm">
                <h2 class="mb-3 text-sm font-semibold text-gray-900">Quotations awaiting approval</h2>
                <div class="space-y-0.5">
                    @forelse ($this->stats['quotationsAwaiting'] as $quotation)
                        <a href="/quotations/{{ $quotation->id }}" wire:navigate class="flex items-center justify-between border-b border-gray-50 py-2 text-sm last:border-0 hover:text-primary-600">
                            <span class="text-gray-900">{{ $quotation->reference }} &middot; {{ $quotation->customer->name }}</span>
                            <span class="text-xs font-semibold text-gray-500">KES {{ number_format($quotation->totalMinor() / 100, 0) }}</span>
                        </a>
                    @empty
                        <p class="text-sm text-gray-500">Nothing awaiting your approval.</p>
                    @endforelse
                </div>
            </div>

            <div class="border border-gray-200 bg-white p-5 shadow-sm">
                <h2 class="mb-3 text-sm font-semibold text-gray-900">Reports awaiting review</h2>
                <div class="space-y-0.5">
                    @forelse ($this->stats['reportsAwaitingReview'] as $report)
                        <a href="/documents/{{ $report->id }}" wire:navigate class="flex items-center justify-between border-b border-gray-50 py-2 text-sm last:border-0 hover:text-primary-600">
                            <span class="text-gray-900">{{ $report->reference }} &middot; {{ $report->customer->name }}</span>
                            <span class="text-xs font-semibold text-gray-500">{{ $report->workOrder?->reference }}</span>
                        </a>
                    @empty
                        <p class="text-sm text-gray-500">Nothing awaiting review.</p>
                    @endforelse
                </div>
            </div>
        </div>

    @elseif ($this->stats['role'] === 'Manager')
        <div class="mb-4 grid items-start gap-4 lg:grid-cols-12">
            <div class="bg-gray-900 p-6 shadow-sm lg:col-span-7">
                <div class="flex items-start justify-between gap-6">
                    <div class="shrink-0">
                        <p class="text-[11px] font-semibold uppercase tracking-widest text-white/40">Revenue this month</p>
                        <p class="mt-1.5 text-4xl font-bold leading-none text-white">KES {{ number_format($this->stats['revenueThisMonthMinor'] / 100, 0) }}</p>
                        <p class="mt-2 text-xs {{ $this->stats['revenueDeltaPct'] === null ? 'text-white/40' : ($this->stats['revenueDeltaPct'] >= 0 ? 'text-fresh-500' : 'text-critical-500') }}">
                            @if ($this->stats['revenueDeltaPct'] !== null)
                                {{ $this->stats['revenueDeltaPct'] >= 0 ? '↑' : '↓' }} {{ abs($this->stats['revenueDeltaPct']) }}% on last month
                            @else
                                No prior month to compare
                            @endif
                        </p>
                    </div>
                    <div class="flex-1 border-l border-white/10 pl-6">
                        <p class="text-[11px] font-semibold uppercase tracking-widest text-white/30">Jobs closed</p>
                        <p class="mt-1.5 text-4xl font-bold leading-none text-white">{{ $this->stats['jobsClosedThisMonth'] }}</p>
                        <p class="mt-2 text-xs text-white/40">this month, across the whole team</p>
                    </div>
                </div>
            </div>

            <div class="border-l-4 border-urgent-500 bg-white p-5 shadow-sm lg:col-span-5">
                <span class="flex h-9 w-9 items-center justify-center bg-urgent-500 text-white">
                    <x-icon name="journal-text" class="h-4 w-4" />
                </span>
                <p class="mt-3 text-[11px] font-semibold uppercase tracking-widest text-gray-400">Needs your approval</p>
                <p class="mt-1 text-4xl font-bold leading-none text-gray-900">{{ $this->stats['quotationsAwaiting']->count() }}</p>
                <p class="mt-2 text-xs text-gray-500">KES 3,000,000 and above &middot; KES {{ number_format($this->stats['pipelineValueMinor'] / 100, 0) }} total</p>
                <a href="/quotations" wire:navigate class="mt-3 inline-flex items-center gap-1 text-sm font-semibold text-urgent-700 hover:text-urgent-800">
                    Review approvals <x-icon name="arrow-right" class="h-3.5 w-3.5" />
                </a>
            </div>
        </div>

        <div class="mb-4 grid grid-cols-3 gap-3">
            <div class="border-l-4 border-success-500 bg-white p-4 shadow-sm">
                <p class="text-3xl font-bold text-gray-900">{{ $this->stats['totalCustomers'] }}</p>
                <p class="mt-0.5 text-[11px] font-medium uppercase tracking-wide text-gray-400">Customers</p>
            </div>
            <div class="border-l-4 border-info-400 bg-white p-4 shadow-sm">
                <p class="text-3xl font-bold text-gray-900">{{ $this->stats['totalTechnicians'] }}</p>
                <p class="mt-0.5 text-[11px] font-medium uppercase tracking-wide text-gray-400">Technicians</p>
            </div>
            <div class="border-l-4 border-primary-500 bg-white p-4 shadow-sm">
                <p class="text-3xl font-bold text-gray-900">{{ $this->stats['overdueJobs'] }}</p>
                <p class="mt-0.5 text-[11px] font-medium uppercase tracking-wide text-gray-400">Overdue jobs</p>
            </div>
        </div>

        <p class="mb-2 text-[11px] font-semibold uppercase tracking-widest text-gray-400">Compliance</p>
        <div class="grid items-start gap-4 lg:grid-cols-2">
            <div class="border border-gray-200 bg-white p-5 shadow-sm">
                <h2 class="mb-4 text-sm font-semibold text-gray-900">Technician performance</h2>
                @if ($this->stats['technicianPerformance']->isNotEmpty())
                    <x-bar-chart :height="110" :data="$this->stats['technicianPerformance']->map(fn ($t) => [
                        'label' => explode(' ', $t->name)[0],
                        'value' => $t->revenueMinor,
                        'valueLabel' => number_format($t->revenueMinor / 100000, 0).'K',
                    ])" />
                @else
                    <p class="text-sm text-gray-500">No invoiced work yet.</p>
                @endif
            </div>

            <div class="border border-gray-200 bg-white p-5 shadow-sm">
                <h2 class="mb-3 text-sm font-semibold text-gray-900">Contracts nearing expiry</h2>
                <div class="space-y-3">
                    @forelse ($this->stats['contractsNeedingAttention'] as $contract)
                        <x-expiry-ring
                            :percent="$contract->percentOfTermUsedCapped()"
                            :stage="$contract->expiryStage()"
                            :title="$contract->reference.' — '.$contract->customer->name"
                            :subtitle="$contract->percentOfTermUsedCapped().'% of term elapsed'" />
                    @empty
                        <p class="text-sm text-gray-500">No contracts need attention right now.</p>
                    @endforelse
                </div>
            </div>
        </div>

    @elseif ($this->stats['role'] === 'Technician')
        @if ($this->stats['nextJob'])
            <div class="mb-4 bg-gray-900 p-6 shadow-sm">
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
                                <a href="/jobs/{{ $this->stats['nextJob']->id }}/report" wire:navigate class="inline-block bg-primary-500 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-600">
                                    File service report
                                </a>
                            @else
                                <a href="/jobs/{{ $this->stats['nextJob']->id }}" wire:navigate class="inline-block border border-white/25 px-4 py-2 text-sm font-semibold text-white hover:bg-white/10">
                                    Open job &rarr;
                                </a>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        @endif

        <div class="mb-4 grid grid-cols-2 gap-3">
            <div class="border-l-4 border-primary-500 bg-white p-4 shadow-sm">
                <p class="text-3xl font-bold text-gray-900">{{ $this->stats['dueTodayOrOverdue']->count() }}</p>
                <p class="mt-0.5 text-[11px] font-medium uppercase tracking-wide text-gray-400">Due today or overdue</p>
            </div>
            <div class="border-l-4 border-success-500 bg-white p-4 shadow-sm">
                <p class="text-3xl font-bold text-gray-900">{{ $this->stats['jobsClosedThisMonth'] }}</p>
                <p class="mt-0.5 text-[11px] font-medium uppercase tracking-wide text-gray-400">Closed this month</p>
            </div>
        </div>

        <p class="mb-2 text-[11px] font-semibold uppercase tracking-widest text-gray-400">Your compliance documents</p>
        <div class="mb-4 border border-gray-200 bg-white p-5 shadow-sm">
            <div class="grid gap-3 md:grid-cols-2">
                @forelse ($this->stats['myDocuments'] as $doc)
                    <x-expiry-ring
                        :percent="$doc->percentUsedCapped()"
                        :stage="$doc->expiryStage()"
                        :title="$doc->document_type"
                        :subtitle="$doc->dueLabel()" />
                @empty
                    <p class="text-sm text-gray-500">No documents on file.</p>
                @endforelse
            </div>
        </div>

        <div class="border border-gray-200 bg-white p-5 shadow-sm">
            <h2 class="mb-3 text-sm font-semibold text-gray-900">Rest of my list</h2>
            <div class="space-y-0.5">
                @forelse ($this->stats['myJobs']->slice(1) as $job)
                    <a href="/jobs/{{ $job->id }}" wire:navigate class="flex items-center justify-between border-b border-gray-50 py-2 text-sm last:border-0 hover:text-primary-600">
                        <span class="text-gray-900">{{ $job->reference }} &middot; {{ $job->customer->name }}</span>
                        <span class="flex items-center gap-3">
                            <span class="text-xs text-gray-400">Due {{ $job->due_date->format('d M') }}</span>
                            <span @class([
                                'px-2 py-0.5 text-[11px] font-semibold',
                                'bg-gray-100 text-gray-600' => $job->status === 'Assigned',
                                'bg-info-50 text-info-700' => in_array($job->status, ['On site', 'Awaiting review']),
                                'bg-success-50 text-success-700' => $job->status === 'Approved',
                            ])>
                                {{ $job->status }}
                            </span>
                        </span>
                    </a>
                @empty
                    <p class="text-sm text-gray-500">Nothing else on your list.</p>
                @endforelse
            </div>
        </div>

    @elseif ($this->stats['role'] === 'Finance')
        <div class="mb-4 grid items-start gap-4 lg:grid-cols-2">
            <div class="border-l-4 border-critical-500 bg-white p-6 shadow-sm">
                <div class="flex items-center gap-2">
                    <span class="flex h-9 w-9 items-center justify-center bg-critical-50 text-critical-700">
                        <x-icon name="receipt" class="h-4 w-4" />
                    </span>
                    <p class="text-[11px] font-semibold uppercase tracking-widest text-critical-700">Outstanding</p>
                </div>
                <p class="mt-3 text-4xl font-bold leading-none text-critical-700">KES {{ number_format($this->stats['outstandingBalanceMinor'] / 100, 0) }}</p>
                <p class="mt-2 text-xs text-gray-500">Across every unpaid and part-paid invoice on file</p>
            </div>

            <div class="border-l-4 border-fresh-500 bg-white p-6 shadow-sm">
                <div class="flex items-center gap-2">
                    <span class="flex h-9 w-9 items-center justify-center bg-fresh-50 text-fresh-700">
                        <x-icon name="cart-check" class="h-4 w-4" />
                    </span>
                    <p class="text-[11px] font-semibold uppercase tracking-widest text-fresh-700">Collected</p>
                </div>
                <p class="mt-3 text-4xl font-bold leading-none text-fresh-700">KES {{ number_format($this->stats['paidThisMonthMinor'] / 100, 0) }}</p>
                <p class="mt-2 text-xs text-gray-500">In payments recorded so far this month</p>
            </div>
        </div>

        <div class="mb-4 grid grid-cols-2 gap-3">
            <a href="/invoices" wire:navigate class="border-l-4 border-info-500 bg-white p-4 shadow-sm hover:bg-info-50">
                <p class="text-3xl font-bold text-gray-900">{{ $this->stats['readyToInvoice'] }}</p>
                <p class="mt-0.5 text-[11px] font-medium uppercase tracking-wide text-gray-400">
                    Ready to invoice
                    @if ($this->stats['readyToInvoiceValueMinor'] > 0)
                        &middot; KES {{ number_format($this->stats['readyToInvoiceValueMinor'] / 100, 0) }}
                    @endif
                </p>
            </a>
            <div class="border-l-4 border-urgent-500 bg-white p-4 shadow-sm">
                <p class="text-3xl font-bold text-urgent-700">{{ $this->stats['overdueInvoices']->count() }}</p>
                <p class="mt-0.5 text-[11px] font-medium uppercase tracking-wide text-gray-400">Overdue invoices</p>
            </div>
        </div>

        @php $ageingTotal = max(array_sum($this->stats['ageing']), 1); @endphp
        <div class="mb-4 border border-gray-200 bg-white p-5 shadow-sm">
            <h2 class="mb-3 text-sm font-semibold text-gray-900">Outstanding, by age</h2>
            <div class="space-y-3">
                <div>
                    <div class="mb-1 flex justify-between text-xs"><span class="text-gray-600">Current</span><span class="font-semibold text-gray-900">KES {{ number_format($this->stats['ageing']['current'] / 100, 0) }}</span></div>
                    <div class="h-1.5 w-full bg-gray-100"><div class="h-1.5 bg-fresh-500" style="width: {{ $this->stats['ageing']['current'] / $ageingTotal * 100 }}%"></div></div>
                </div>
                <div>
                    <div class="mb-1 flex justify-between text-xs"><span class="text-gray-600">1 to 30 days overdue</span><span class="font-semibold text-gray-900">KES {{ number_format($this->stats['ageing']['days1to30'] / 100, 0) }}</span></div>
                    <div class="h-1.5 w-full bg-gray-100"><div class="h-1.5 bg-warn-500" style="width: {{ $this->stats['ageing']['days1to30'] / $ageingTotal * 100 }}%"></div></div>
                </div>
                <div>
                    <div class="mb-1 flex justify-between text-xs"><span class="text-gray-600">31 to 60 days overdue</span><span class="font-semibold text-gray-900">KES {{ number_format($this->stats['ageing']['days31to60'] / 100, 0) }}</span></div>
                    <div class="h-1.5 w-full bg-gray-100"><div class="h-1.5 bg-urgent-500" style="width: {{ $this->stats['ageing']['days31to60'] / $ageingTotal * 100 }}%"></div></div>
                </div>
                <div>
                    <div class="mb-1 flex justify-between text-xs"><span class="text-gray-600">Over 60 days overdue</span><span class="font-semibold text-gray-900">KES {{ number_format($this->stats['ageing']['days60plus'] / 100, 0) }}</span></div>
                    <div class="h-1.5 w-full bg-gray-100"><div class="h-1.5 bg-critical-500" style="width: {{ $this->stats['ageing']['days60plus'] / $ageingTotal * 100 }}%"></div></div>
                </div>
            </div>
        </div>

        @if ($this->stats['overdueInvoices']->isNotEmpty())
            <div class="mb-4 border border-critical-200 bg-critical-50 p-5">
                <h2 class="mb-3 flex items-center gap-1.5 text-sm font-semibold text-critical-800">
                    <x-icon name="exclamation-circle" class="h-4 w-4" /> Overdue, past their due date
                </h2>
                <div class="space-y-0.5">
                    @foreach ($this->stats['overdueInvoices'] as $invoice)
                        <a href="/invoices/{{ $invoice->id }}" wire:navigate class="flex items-center justify-between border-b border-critical-100 py-2 text-sm last:border-0 hover:underline">
                            <span class="text-critical-900">{{ $invoice->reference }} &middot; {{ $invoice->customer->name }}</span>
                            <span class="font-semibold text-critical-700">KES {{ number_format($invoice->balanceMinor() / 100, 0) }}</span>
                        </a>
                    @endforeach
                </div>
            </div>
        @endif

        <div class="grid items-start gap-4 lg:grid-cols-2">
            <div class="border border-gray-200 bg-white p-5 shadow-sm">
                <h2 class="mb-3 text-sm font-semibold text-gray-900">Recent invoices</h2>
                <div class="space-y-0.5">
                    @forelse ($this->stats['recentInvoices'] as $invoice)
                        <a href="/invoices/{{ $invoice->id }}" wire:navigate class="flex items-center justify-between border-b border-gray-50 py-2 text-sm last:border-0 hover:text-primary-600">
                            <span class="text-gray-900">{{ $invoice->reference }} &middot; {{ $invoice->customer->name }}</span>
                            <span @class([
                                'text-xs font-semibold',
                                'text-fresh-700' => $invoice->status === 'Paid',
                                'text-critical-700' => in_array($invoice->status, ['Unpaid', 'Part paid']),
                                'text-gray-500' => $invoice->status === 'Draft',
                            ])>
                                KES {{ number_format($invoice->amount_minor / 100, 0) }}
                            </span>
                        </a>
                    @empty
                        <p class="text-sm text-gray-500">No invoices yet.</p>
                    @endforelse
                </div>
            </div>

            <div class="border border-gray-200 bg-white p-5 shadow-sm">
                <h2 class="mb-3 text-sm font-semibold text-gray-900">Recent payments</h2>
                <div class="space-y-0.5">
                    @forelse ($this->stats['recentPayments'] as $payment)
                        <div class="flex items-center justify-between border-b border-gray-50 py-2 text-sm last:border-0">
                            <span class="text-gray-900">{{ $payment->reference }} &middot; {{ $payment->customer->name }}</span>
                            <span class="text-xs font-semibold text-fresh-700">+KES {{ number_format($payment->amount_minor / 100, 0) }}</span>
                        </div>
                    @empty
                        <p class="text-sm text-gray-500">No payments yet.</p>
                    @endforelse
                </div>
            </div>
        </div>
    @endif
</div>
