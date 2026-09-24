<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Models\ServiceRequest;
use App\Models\Document;
use App\Models\Quotation;
use App\Models\WorkOrder;
use App\Models\Customer;

new #[Layout('layouts.app', ['title' => 'Overview'])] class extends Component
{
    public function getStatsProperty(): ?array
    {
        if (! auth()->user()->hasRole('Service Admin')) {
            return null;
        }

        $jobCounts = WorkOrder::selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $jobTotal = max($jobCounts->sum(), 1);
        $circumference = 2 * M_PI * 40;

        // Five real statuses, five distinct colors, not collapsed into
        // three, more accurate to the actual data and more legible as a
        // chart.
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
            'openRequests' => ServiceRequest::with('customer')->where('status', 'Open')->latest()->get(),
            'requestsByStatus' => ServiceRequest::selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
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
        ];
    }
};
?>

<div>
    <div class="mb-4">
        <p class="text-sm font-medium text-primary-600">{{ auth()->user()->getRoleNames()->first() }}</p>
        <h1 class="text-2xl font-semibold text-gray-900">
            Good {{ now()->hour < 12 ? 'morning' : (now()->hour < 17 ? 'afternoon' : 'evening') }}, {{ explode(' ', auth()->user()->name)[0] }}
        </h1>
    </div>

    @if ($this->stats)
        {{-- items-start stops CSS grid stretching both cards to match
             the taller one, that was the real cause of the empty space,
             not missing content. --}}
        <div class="mb-3 grid items-start gap-3 lg:grid-cols-12">
            <div class="bg-gray-900 p-5 lg:col-span-7">
                <div class="flex items-start justify-between gap-6">
                    <div class="shrink-0">
                        <p class="text-xs font-medium uppercase tracking-wide text-white/50">Requests to triage</p>
                        <p class="mt-1 text-4xl font-semibold text-white">{{ $this->stats['openRequests']->count() }}</p>
                        <a href="/requests" wire:navigate class="mt-3 inline-block border border-white/25 px-3 py-1.5 text-xs font-medium text-white hover:bg-white/10">
                            View all
                        </a>
                    </div>
                    <div class="flex-1 border-l border-white/10 pl-6">
                        <p class="mb-2 text-xs font-medium uppercase tracking-wide text-white/40">All requests</p>
                        @foreach (['Open', 'Assigned', 'Quoted', 'Converted', 'Declined'] as $status)
                            <div class="flex items-center justify-between py-0.5 text-sm">
                                <span class="text-white/70">{{ $status }}</span>
                                <span class="font-medium text-white">{{ $this->stats['requestsByStatus']->get($status, 0) }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            <div class="border border-info-200 bg-white p-4 lg:col-span-5">
                <div class="flex items-center gap-2">
                    <span class="flex h-6 w-6 items-center justify-center bg-info-500 text-white">
                        <x-icon name="folder" class="h-3.5 w-3.5" />
                    </span>
                    <p class="text-xs font-medium uppercase tracking-wide text-info-700">Ready to post</p>
                </div>
                <p class="mt-2 text-3xl font-semibold text-gray-900">{{ $this->stats['reportsReadyToPost'] }}</p>
                <a href="/documents" wire:navigate class="mt-2 inline-flex items-center gap-1 text-sm font-medium text-info-700 hover:text-info-800">
                    Review and post
                    <x-icon name="arrow-right" class="h-3.5 w-3.5" />
                </a>
            </div>
        </div>

        <div class="mb-3 grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div class="border border-gray-200 bg-white p-3">
                <span class="flex h-5 w-5 items-center justify-center bg-info-50 text-info-600">
                    <x-icon name="journal-text" class="h-3 w-3" />
                </span>
                <p class="mt-1.5 text-lg font-semibold text-gray-900">{{ $this->stats['quotationsAwaitingLpo'] }}</p>
                <p class="text-xs text-gray-500">Awaiting LPO</p>
            </div>
            <div class="border border-gray-200 bg-white p-3">
                <span class="flex h-5 w-5 items-center justify-center bg-info-50 text-info-600">
                    <x-icon name="receipt" class="h-3 w-3" />
                </span>
                <p class="mt-1.5 text-lg font-semibold text-gray-900">{{ $this->stats['readyToInvoice'] }}</p>
                <p class="text-xs text-gray-500">To invoice</p>
            </div>
            <div class="border border-gray-200 bg-white p-3">
                <span class="flex h-5 w-5 items-center justify-center bg-primary-50 text-primary-600">
                    <x-icon name="tools" class="h-3 w-3" />
                </span>
                <p class="mt-1.5 text-lg font-semibold text-gray-900">{{ $this->stats['overdueJobs'] }}</p>
                <p class="text-xs text-gray-500">Overdue</p>
            </div>
            <div class="border border-gray-200 bg-white p-3">
                <span class="flex h-5 w-5 items-center justify-center bg-success-50 text-success-600">
                    <x-icon name="envelope" class="h-3 w-3" />
                </span>
                <p class="mt-1.5 text-lg font-semibold text-gray-900">{{ $this->stats['totalCustomers'] }}</p>
                <p class="text-xs text-gray-500">Customers</p>
            </div>
        </div>

        <div class="mb-3 grid items-start gap-3 lg:grid-cols-12">
            <div class="flex items-center gap-5 border border-gray-200 bg-white p-4 lg:col-span-7">
                <svg viewBox="0 0 100 100" class="h-24 w-24 shrink-0 -rotate-90">
                    <circle cx="50" cy="50" r="40" fill="none" stroke-width="14" class="stroke-gray-100" />
                    @foreach ($this->stats['jobSegments'] as $segment)
                        @if ($segment['count'] > 0)
                            <circle cx="50" cy="50" r="40" fill="none" stroke-width="14"
                                    class="{{ $segment['stroke'] }}"
                                    stroke-dasharray="{{ $segment['dasharray'] }}"
                                    stroke-dashoffset="{{ $segment['dashoffset'] }}" />
                        @endif
                    @endforeach
                </svg>
                <div class="flex-1">
                    <p class="mb-2 text-xs font-medium uppercase tracking-wide text-gray-400">Jobs, {{ $this->stats['jobTotal'] }} total</p>
                    @foreach ($this->stats['jobSegments'] as $segment)
                        <div class="flex items-center justify-between py-0.5 text-sm">
                            <span class="flex items-center gap-1.5 text-gray-600">
                                <span class="h-2 w-2 {{ $segment['dot'] }}"></span>
                                {{ $segment['label'] }}
                            </span>
                            <span class="font-medium text-gray-900">{{ $segment['count'] }}</span>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="grid grid-cols-3 gap-2 lg:col-span-5">
                <a href="/quotations/create" wire:navigate class="flex flex-col items-center justify-center gap-1.5 border border-gray-200 bg-white p-3 text-center hover:border-info-300">
                    <x-icon name="journal-text" class="h-4 w-4 text-info-600" />
                    <p class="text-xs font-medium text-gray-900">Quote</p>
                </a>
                <a href="/jobs" wire:navigate class="flex flex-col items-center justify-center gap-1.5 border border-gray-200 bg-white p-3 text-center hover:border-info-300">
                    <x-icon name="tools" class="h-4 w-4 text-info-600" />
                    <p class="text-xs font-medium text-gray-900">Jobs</p>
                </a>
                <a href="/invoices" wire:navigate class="flex flex-col items-center justify-center gap-1.5 border border-gray-200 bg-white p-3 text-center hover:border-info-300">
                    <x-icon name="receipt" class="h-4 w-4 text-info-600" />
                    <p class="text-xs font-medium text-gray-900">Invoices</p>
                </a>
            </div>
        </div>

        <div class="grid items-start gap-3 lg:grid-cols-2">
            <div class="border border-gray-200 bg-white p-4">
                <h2 class="mb-2 text-sm font-medium text-gray-900">Customer intake, today</h2>
                <div class="space-y-0.5">
                    @forelse ($this->stats['recentRequests'] as $request)
                        <a href="/requests/{{ $request->id }}" wire:navigate class="flex items-center justify-between border-b border-gray-100 py-1.5 text-sm last:border-0 hover:text-primary-600">
                            <span class="text-gray-900">{{ $request->reference }} &middot; {{ $request->customer->name }}</span>
                            <span @class([
                                'px-1.5 py-0.5 text-xs font-medium',
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

            <div class="border border-gray-200 bg-white p-4">
                <h2 class="mb-2 text-sm font-medium text-gray-900">Recent reports</h2>
                <div class="space-y-0.5">
                    @forelse ($this->stats['recentReports'] as $report)
                        <a href="/documents/{{ $report->id }}" wire:navigate class="flex items-center justify-between border-b border-gray-100 py-1.5 text-sm last:border-0 hover:text-primary-600">
                            <span class="text-gray-900">{{ $report->reference }} &middot; {{ $report->customer->name }}</span>
                            <span @class([
                                'px-1.5 py-0.5 text-xs font-medium',
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
    @else
        <div class="border border-gray-200 bg-white p-6">
            <p class="text-sm text-gray-500">
                You're signed in. A dedicated dashboard for {{ auth()->user()->getRoleNames()->first() }} hasn't been built yet, Service Admin's is the only one done so far.
            </p>
        </div>
    @endif
</div>
