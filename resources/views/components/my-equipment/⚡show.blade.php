<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Models\CertificateDetail;
use App\Models\Document;
use App\Models\Equipment;
use App\Models\ReportPart;
use App\Models\ServiceReportDetail;
use App\Models\WorkOrder;

new #[Layout('layouts.app', ['title' => 'Equipment'])] class extends Component
{
    public Equipment $equipment;

    public function mount(Equipment $equipment): void
    {
        abort_unless(auth()->user()->hasRole('Technician'), 403);

        $worked = WorkOrder::where('assigned_technician_id', auth()->id())
            ->where('equipment_id', $equipment->id)
            ->exists();

        abort_unless($worked, 403, 'You have no job on this machine.');

        $this->equipment = $equipment->load(['customer', 'site']);
    }

    // Filed (non-draft) service reports whose job was on this machine.
    protected function reportScope($q): void
    {
        $q->where('type', Document::TYPE_REPORT)
            ->where('status', '!=', 'Draft')
            ->whereHas('workOrder', fn ($w) => $w->where('equipment_id', $this->equipment->id));
    }

    public function with(): array
    {
        $uid = auth()->id();

        $jobs = WorkOrder::with([
            'technician',
            'documents' => fn ($q) => $q->where('type', Document::TYPE_REPORT)->where('status', '!=', 'Draft'),
        ])
            ->where('equipment_id', $this->equipment->id)
            ->orderByDesc('due_date')
            ->take(30)
            ->get();

        $lastNotes = ServiceReportDetail::with(['document.workOrder.technician'])
            ->whereHas('document', fn ($q) => $this->reportScope($q))
            ->latest()
            ->first();

        $parts = ReportPart::with('reportDetail.document.workOrder')
            ->whereHas('reportDetail.document', fn ($q) => $this->reportScope($q))
            ->latest()
            ->take(20)
            ->get();

        $certificates = CertificateDetail::where('equipment_id', $this->equipment->id)
            ->orderByDesc('expires_at')
            ->get();

        return [
            'jobs' => $jobs,
            'uid' => $uid,
            'myVisits' => $jobs->where('assigned_technician_id', $uid)->count(),
            'openJob' => $jobs->where('assigned_technician_id', $uid)->where('status', 'On site')->first(),
            'lastNotes' => $lastNotes,
            'parts' => $parts,
            'certificates' => $certificates,
        ];
    }
};
?>

<div class="mx-auto" style="max-width: 56rem">
    <a href="/my-equipment" wire:navigate class="mb-3 inline-flex items-center gap-1.5 text-sm font-medium text-neutral-500 hover:text-neutral-900" style="min-height: 36px">
        <x-icon name="arrow-right" class="h-3.5 w-3.5 rotate-180" />
        Back to my equipment
    </a>

    @php
        $status = $equipment->visitStatus();
        $statusPill = match ($status) {
            'Active' => 'pill-success',
            'Due soon' => 'pill-amber',
            default => 'pill-danger',
        };
        $warranty = $equipment->warranty_expires_at;
        $mapUrl = $equipment->site && $equipment->site->hasCoordinates()
            ? 'https://www.google.com/maps?q='.$equipment->site->lat.','.$equipment->site->lng
            : null;
    @endphp

    {{-- Hero --}}
    <div class="card mb-4">
        <div class="flex flex-wrap items-start gap-4">
            <div class="icon-badge icon-badge-primary" style="height: 3.5rem; width: 3.5rem">
                <x-icon name="nut" class="h-6 w-6" />
            </div>
            <div class="min-w-0" style="flex: 1 1 220px">
                <h1 class="text-xl font-semibold text-neutral-900">{{ $equipment->model }}</h1>
                <div class="mt-1 flex flex-wrap items-center gap-2">
                    <span class="rounded-full bg-neutral-900 px-2.5 py-1 font-mono text-xs text-white">{{ $equipment->serial_number }}</span>
                    <span class="{{ $statusPill }}">{{ $status }}</span>
                </div>
                <p class="mt-2 text-sm text-neutral-600">
                    {{ $equipment->customer->name }}@if ($equipment->site) &middot; {{ $equipment->site->name }}@endif
                </p>
            </div>
        </div>

        @if ($openJob)
            <a href="/jobs/{{ $openJob->id }}/report" wire:navigate class="btn-primary mt-4 w-full" style="min-height: 48px">
                File service report for {{ $openJob->reference }}
            </a>
        @endif
    </div>

    {{-- Facts --}}
    <div class="mb-4 grid gap-3" style="grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));">
        <div class="card">
            <p class="text-xs font-medium text-neutral-500">Category</p>
            <p class="mt-1 text-sm font-semibold text-neutral-900">{{ $equipment->category ?: '-' }}</p>
        </div>
        <div class="card">
            <p class="text-xs font-medium text-neutral-500">Cover</p>
            <p class="mt-1 text-sm font-semibold text-neutral-900">{{ $equipment->cover }}</p>
        </div>
        <div class="card">
            <p class="text-xs font-medium text-neutral-500">Installed</p>
            <p class="mt-1 text-sm font-semibold text-neutral-900">
                {{ $equipment->installed_at?->format('d M Y') ?? '-' }}
            </p>
            @if ($equipment->installed_at)
                <p class="text-xs text-neutral-400">{{ $equipment->installed_at->diffForHumans() }}</p>
            @endif
        </div>
        <div class="card">
            <p class="text-xs font-medium text-neutral-500">Warranty</p>
            <p class="mt-1 text-sm font-semibold {{ $warranty && $warranty->isPast() ? 'text-critical-700' : 'text-neutral-900' }}">
                {{ $warranty ? ($warranty->isPast() ? 'Expired' : 'To '.$warranty->format('d M Y')) : '-' }}
            </p>
            @if ($warranty && $warranty->isPast())
                <p class="text-xs text-neutral-400">since {{ $warranty->format('d M Y') }}</p>
            @endif
        </div>
        <div class="card">
            <p class="text-xs font-medium text-neutral-500">Next visit due</p>
            <p class="mt-1 text-sm font-semibold text-neutral-900">{{ $equipment->next_visit_due_at?->format('d M Y') ?? 'Not set' }}</p>
        </div>
        <div class="card">
            <p class="text-xs font-medium text-neutral-500">Visits</p>
            <p class="mt-1 text-sm font-semibold text-neutral-900">{{ $jobs->count() }} total</p>
            <p class="text-xs text-neutral-400">{{ $myVisits }} by you</p>
        </div>
    </div>

    {{-- Where and who --}}
    <div class="card mb-4">
        <h2 class="mb-3 text-sm font-semibold text-neutral-900">Customer and site</h2>
        <dl class="grid gap-4 text-sm" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));">
            <div>
                <dt class="text-neutral-500">Customer</dt>
                <dd class="font-medium text-neutral-900">{{ $equipment->customer->name }}</dd>
            </div>
            <div>
                <dt class="text-neutral-500">Site</dt>
                <dd class="font-medium text-neutral-900">{{ $equipment->site?->name ?? '-' }}</dd>
            </div>
            <div>
                <dt class="text-neutral-500">Contact</dt>
                <dd class="font-medium text-neutral-900">{{ $equipment->site?->contact_name ?: ($equipment->customer->main_contact_name ?: '-') }}</dd>
            </div>
            <div>
                <dt class="text-neutral-500">Phone</dt>
                <dd class="font-medium">
                    @if ($equipment->customer->main_contact_phone)
                        <a href="tel:{{ $equipment->customer->main_contact_phone }}" class="text-info-700 hover:text-info-800">{{ $equipment->customer->main_contact_phone }}</a>
                    @else
                        <span class="text-neutral-900">-</span>
                    @endif
                </dd>
            </div>
        </dl>
        @if ($mapUrl)
            <a href="{{ $mapUrl }}" target="_blank" rel="noopener" class="btn-outline mt-4" style="min-height: 44px">Open site on the map</a>
        @endif
    </div>

    {{-- Last service notes --}}
    @if ($lastNotes)
        <div class="card mb-4">
            <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="text-sm font-semibold text-neutral-900">Last service notes</h2>
                <p class="text-xs text-neutral-400">
                    {{ $lastNotes->document->workOrder?->reference }}
                    &middot; {{ $lastNotes->report_date?->format('d M Y') ?? $lastNotes->created_at->format('d M Y') }}
                    @if ($lastNotes->document->workOrder?->technician)
                        &middot; {{ $lastNotes->document->workOrder->technician->name }}
                    @endif
                </p>
            </div>
            <dl class="space-y-3 text-sm">
                <div>
                    <dt class="mb-0.5 text-xs font-medium text-neutral-500">Fault reported</dt>
                    <dd class="text-neutral-900">{{ $lastNotes->fault_description ?: '-' }}</dd>
                </div>
                <div>
                    <dt class="mb-0.5 text-xs font-medium text-neutral-500">Cause</dt>
                    <dd class="text-neutral-900">{{ $lastNotes->cause ?: '-' }}</dd>
                </div>
                <div>
                    <dt class="mb-0.5 text-xs font-medium text-neutral-500">Correction</dt>
                    <dd class="text-neutral-900">{{ $lastNotes->correction ?: '-' }}</dd>
                </div>
                <div>
                    <dt class="mb-0.5 text-xs font-medium text-neutral-500">Final result</dt>
                    <dd class="text-neutral-900">{{ $lastNotes->final_result ?: '-' }}</dd>
                </div>
                @if ($lastNotes->parts_to_order)
                    <div>
                        <dt class="mb-0.5 text-xs font-medium text-neutral-500">Parts still to order</dt>
                        <dd class="font-medium text-amber-700">{{ $lastNotes->parts_to_order }}</dd>
                    </div>
                @endif
            </dl>
        </div>
    @endif

    {{-- Service history --}}
    <div class="card mb-4">
        <h2 class="mb-3 text-sm font-semibold text-neutral-900">Service history</h2>
        <div class="space-y-2">
            @forelse ($jobs as $job)
                @php
                    $mine = $job->assigned_technician_id === $uid;
                    $report = $job->documents->sortByDesc('id')->first();
                    $pill = match (true) {
                        $job->status === 'Assigned' => 'pill-neutral',
                        in_array($job->status, ['On site', 'Awaiting review']) => 'pill-info',
                        default => 'pill-success',
                    };
                @endphp
                <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-2 rounded-[var(--radius-md)] border border-neutral-100 px-3 py-3" wire:key="hist-{{ $job->id }}">
                    <div class="min-w-0" style="flex: 1 1 200px">
                        <p class="text-sm font-semibold text-neutral-900">
                            @if ($mine)
                                <a href="/jobs/{{ $job->id }}" wire:navigate class="hover:text-primary-600">{{ $job->reference }}</a>
                            @else
                                {{ $job->reference }}
                            @endif
                            @if ($mine)
                                <span class="ml-1 rounded-full bg-primary-50 px-2 py-0.5 text-[11px] font-semibold text-primary-700">You</span>
                            @endif
                        </p>
                        <p class="text-xs text-neutral-500">
                            {{ $job->nature_of_visit }}
                            @if (! $mine && $job->technician) &middot; {{ $job->technician->name }} @endif
                            &middot; {{ $job->due_date->format('d M Y') }}
                        </p>
                    </div>
                    @if ($mine && $report)
                        <a href="/documents/{{ $report->id }}" wire:navigate class="text-xs font-semibold text-primary-600 hover:text-primary-700">Report {{ $report->reference }}</a>
                    @endif
                    <span class="{{ $pill }}">{{ $job->status }}</span>
                </div>
            @empty
                <p class="text-sm text-neutral-500">No visits on file.</p>
            @endforelse
        </div>
    </div>

    {{-- Parts fitted --}}
    <div class="card mb-4">
        <h2 class="mb-3 text-sm font-semibold text-neutral-900">Parts fitted to this machine</h2>
        @if ($parts->isEmpty())
            <p class="text-sm text-neutral-500">No parts recorded in service reports yet.</p>
        @else
            <div class="overflow-x-auto">
                <table class="table-clean w-full" style="min-width: 480px">
                    <thead>
                        <tr><th>Item</th><th>Part number</th><th>Qty</th><th>Job</th><th>Date</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($parts as $part)
                            <tr wire:key="part-{{ $part->id }}">
                                <td class="font-medium text-neutral-900">{{ $part->item }}</td>
                                <td class="font-mono text-xs">{{ $part->part_number ?: '-' }}</td>
                                <td>{{ $part->quantity }}</td>
                                <td>{{ $part->reportDetail->document->workOrder?->reference ?? '-' }}</td>
                                <td class="whitespace-nowrap">{{ ($part->reportDetail->report_date ?? $part->created_at)->format('d M Y') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- Certificates --}}
    <div class="card">
        <h2 class="mb-3 text-sm font-semibold text-neutral-900">Calibration certificates</h2>
        @forelse ($certificates as $cert)
            @php
                $cs = $cert->expires_at?->lt(today()) ? ['Expired', 'pill-danger'] : ($cert->expires_at?->lte(today()->addDays(30)) ? ['Expiring soon', 'pill-amber'] : ['Valid', 'pill-success']);
            @endphp
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-neutral-100 py-2.5 last:border-0" wire:key="cert-{{ $cert->id }}">
                <div>
                    <p class="text-sm font-medium text-neutral-900">{{ $cert->certificate_type }}</p>
                    <p class="text-xs text-neutral-500">
                        Issued {{ $cert->issued_at?->format('d M Y') ?? '-' }} &middot; expires {{ $cert->expires_at?->format('d M Y') ?? '-' }}
                    </p>
                </div>
                @if ($cert->expires_at)
                    <span class="{{ $cs[1] }}">{{ $cs[0] }}</span>
                @endif
            </div>
        @empty
            <p class="text-sm text-neutral-500">No certificates on file for this machine.</p>
        @endforelse
    </div>
</div>
