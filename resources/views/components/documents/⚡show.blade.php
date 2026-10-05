<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Models\Document;
use App\Services\WorkflowNotifier;

new #[Layout('layouts.app', ['title' => 'Document'])] class extends Component
{
    public Document $document;

    public array $typeLabels = [
        Document::TYPE_REPORT => 'Service report',
        Document::TYPE_CERTIFICATE => 'Calibration certificate',
        Document::TYPE_LPO => 'LPO',
        Document::TYPE_VOUCHER => 'Maintenance voucher',
        Document::TYPE_DELIVERY_NOTE => 'Delivery note',
    ];

    public function mount(Document $document): void
    {
        $this->authorize('view', $document);
        $this->document = $document->load([
            'reportDetail.parts',
            'certificateDetail.equipment',
            'lpoDetail.quotation',
            'voucherDetail.technician',
            'voucherDetail.contract',
            'deliveryNoteDetail',
            'customer',
            'workOrder',
            'filedBy',
        ]);
    }

    public function approve(): void
    {
        $this->authorize('review', $this->document);

        $this->document->update(['status' => 'Checked, ready to post']);
        $this->document->refresh();

        $this->document->workOrder?->update(['status' => 'Approved']);

        WorkflowNotifier::user(
            $this->document->filedBy,
            'Your report was approved',
            [
                "Report {$this->document->reference} has been checked and approved.",
            ],
            url("/documents/{$this->document->id}"),
            'View report',
        );
    }

    public function post(): void
    {
        $this->authorize('post', $this->document);

        $this->document->update(['status' => 'Released']);
        $this->document->refresh();

        WorkflowNotifier::customer(
            $this->document->customer,
            'Your service report is ready',
            [
                "Report {$this->document->reference} for your recent service visit has been released.",
            ],
        );

        if ($this->document->workOrder) {
            WorkflowNotifier::role(
                'Service Admin',
                'Job ready to invoice',
                [
                    "The report for job {$this->document->workOrder->reference} has been released; it can now be invoiced.",
                ],
                url("/invoices/create/{$this->document->workOrder->id}"),
                'Raise invoice',
            );
        }
    }
};
?>

<div>
    <a href="/documents" wire:navigate class="mb-4 inline-flex items-center gap-1.5 text-sm text-neutral-500 hover:text-neutral-900">
        <x-icon name="arrow-right" class="h-3.5 w-3.5 rotate-180" />
        Back to documents
    </a>

    <div class="mb-4 flex items-start justify-between">
        <div>
            <h1 class="text-xl font-semibold text-neutral-900">{{ $typeLabels[$document->type] ?? ucfirst($document->type) }} {{ $document->reference }}</h1>
            <p class="text-sm text-neutral-500">
                {{ $document->customer->name }}
                @if ($document->workOrder) &middot; {{ $document->workOrder->reference }} @endif
            </p>
        </div>
        <span @class([
            'shrink-0',
            'pill-info' => in_array($document->status, ['Awaiting review', 'Checked, ready to post']),
            'pill-success' => $document->status === 'Released',
            'pill-neutral' => ! in_array($document->status, ['Awaiting review', 'Checked, ready to post', 'Released']),
        ])>
            {{ $document->status }}
        </span>
    </div>

    @if ($document->reportDetail)
        @php $rd = $document->reportDetail; @endphp

        <div class="card mb-4" style="border-left: 4px solid var(--color-amber-500)">
            <dl class="grid gap-4 text-sm" style="grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));">
                <div>
                    <dt class="text-neutral-500">Date</dt>
                    <dd class="text-neutral-900">{{ $rd->report_date?->format('d M Y') ?? $document->created_at->format('d M Y') }}</dd>
                </div>
                <div>
                    <dt class="text-neutral-500">Vehicle No.</dt>
                    <dd class="text-neutral-900">{{ $rd->vehicle ?: '-' }}</dd>
                </div>
                <div>
                    <dt class="text-neutral-500">Branch</dt>
                    <dd class="text-neutral-900">{{ $rd->branch?->name ?? '-' }}</dd>
                </div>
                <div>
                    <dt class="text-neutral-500">Machine / equipment</dt>
                    <dd class="text-neutral-900">{{ $rd->equipment_description ?: ($document->workOrder?->equipment_description ?: '-') }}</dd>
                </div>
                <div>
                    <dt class="text-neutral-500">Filed by</dt>
                    <dd class="text-neutral-900">{{ $document->filedBy->name }}</dd>
                </div>
                <div>
                    <dt class="text-neutral-500">Nature of visit</dt>
                    <dd class="text-neutral-900">{{ $rd->nature_of_visit }}</dd>
                </div>
            </dl>
        </div>

        <div class="card mb-4">
            <h2 class="mb-3 text-sm font-semibold text-neutral-900">Customer</h2>
            <dl class="grid gap-4 text-sm" style="grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));">
                <div>
                    <dt class="text-neutral-500">Customer</dt>
                    <dd class="text-neutral-900">{{ $document->customer->name }}</dd>
                </div>
                <div>
                    <dt class="text-neutral-500">Contact person</dt>
                    <dd class="text-neutral-900">{{ $rd->contact_name ?: '-' }}</dd>
                </div>
                <div>
                    <dt class="text-neutral-500">Address</dt>
                    <dd class="text-neutral-900">{{ $rd->address ?: '-' }}</dd>
                </div>
                <div>
                    <dt class="text-neutral-500">Tel. No.</dt>
                    <dd class="text-neutral-900">{{ $rd->tel_no ?: '-' }}</dd>
                </div>
            </dl>
        </div>

        <div class="card mb-4">
            <h2 class="mb-3 text-sm font-semibold text-neutral-900">Job details</h2>
            <dl class="space-y-3 text-sm">
                <div>
                    <dt class="mb-1 text-neutral-500">Fault reported</dt>
                    <dd class="text-neutral-900">{{ $rd->fault_description ?: '-' }}</dd>
                </div>
                <div>
                    <dt class="mb-1 text-neutral-500">Cause</dt>
                    <dd class="text-neutral-900">{{ $rd->cause ?: '-' }}</dd>
                </div>
                <div>
                    <dt class="mb-1 text-neutral-500">Correction</dt>
                    <dd class="text-neutral-900">{{ $rd->correction ?: '-' }}</dd>
                </div>
                <div>
                    <dt class="mb-1 text-neutral-500">Final result</dt>
                    <dd class="text-neutral-900">{{ $rd->final_result ?: '-' }}</dd>
                </div>
                @if ($rd->parts_to_order)
                    <div>
                        <dt class="mb-1 text-neutral-500">Parts to order</dt>
                        <dd class="text-neutral-900">{{ $rd->parts_to_order }}</dd>
                    </div>
                @endif
                @if ($rd->customer_comments)
                    <div>
                        <dt class="mb-1 text-neutral-500">Customer comments</dt>
                        <dd class="text-neutral-900">{{ $rd->customer_comments }}</dd>
                    </div>
                @endif
            </dl>
        </div>

        @if ($rd->parts->isNotEmpty())
            <div class="card mb-4">
                <h2 class="mb-3 text-sm font-semibold text-neutral-900">Parts used</h2>
                <div class="overflow-x-auto">
                    <table class="table-clean w-full" style="min-width: 480px">
                        <thead>
                            <tr><th>Item</th><th>Part number</th><th>Qty</th><th>Source</th><th>Returned</th></tr>
                        </thead>
                        <tbody>
                            @foreach ($rd->parts as $part)
                                <tr>
                                    <td class="text-neutral-900">{{ $part->item }}</td>
                                    <td>{{ $part->part_number ?: '-' }}</td>
                                    <td>{{ $part->quantity }}</td>
                                    <td>{{ $part->source ?: '-' }}</td>
                                    <td>{{ $part->returned ?? 0 }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        @if ($rd->delivery_note_path)
            <div class="card mb-4">
                <h2 class="mb-2 text-sm font-semibold text-neutral-900">Delivery note</h2>
                <a href="{{ Storage::url($rd->delivery_note_path) }}" target="_blank" class="text-xs font-medium text-info-700 hover:text-info-800">View delivery note</a>
            </div>
        @endif

        @if ($rd->voucher_number)
            <div class="card mb-4">
                <h2 class="mb-3 text-sm font-semibold text-neutral-900">Maintenance voucher</h2>
                <dl class="grid gap-4 text-sm" style="grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));">
                    <div>
                        <dt class="text-neutral-500">Contract on file</dt>
                        <dd class="text-neutral-900">{{ $rd->contract_on_file ?: '-' }}</dd>
                    </div>
                    <div>
                        <dt class="text-neutral-500">Voucher number</dt>
                        <dd class="text-neutral-900">{{ $rd->voucher_number }}</dd>
                    </div>
                </dl>
                @if ($rd->voucher_signature)
                    <p class="mb-1 mt-4 text-xs text-neutral-500">Client authorised person signature and stamp</p>
                    <img src="{{ $rd->voucher_signature }}" alt="Client signature" style="max-height: 90px; border: 1px solid var(--color-neutral-200); border-radius: 8px; background: #fff;">
                @endif
            </div>
        @endif

        @if ($rd->incident_type !== 'None')
            <div class="card mb-4" style="background-color: var(--color-critical-50); border-color: #f5c6cb">
                <h2 class="mb-2 text-sm font-semibold text-critical-900">Incident: {{ $rd->incident_type }}</h2>
                <p class="text-sm text-critical-800">{{ $rd->incident_description }}</p>
                @if ($rd->incident_photo_path)
                    <a href="{{ Storage::url($rd->incident_photo_path) }}" target="_blank" class="mt-3 inline-block text-xs font-medium text-info-700 hover:text-info-800">View photo</a>
                @endif
            </div>
        @endif

        <div class="card mb-4">
            <h2 class="mb-3 text-sm font-semibold text-neutral-900">Sign off</h2>
            <div class="grid gap-4 text-sm" style="grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));">
                <div>
                    <p class="text-neutral-500">Repairer</p>
                    <p class="mb-2 text-neutral-900">{{ $rd->repairer_name }}</p>
                    @if ($rd->repairer_signature)
                        <img src="{{ $rd->repairer_signature }}" alt="Repairer signature" style="max-height: 90px; border: 1px solid var(--color-neutral-200); border-radius: 8px; background: #fff;">
                    @endif
                </div>
                <div>
                    <p class="text-neutral-500">Customer</p>
                    <p class="mb-2 text-neutral-900">{{ $rd->customer_signoff_name ?: '-' }}</p>
                    @if ($rd->customer_signature)
                        <img src="{{ $rd->customer_signature }}" alt="Customer signature" style="max-height: 90px; border: 1px solid var(--color-neutral-200); border-radius: 8px; background: #fff;">
                        @if ($rd->customer_signed_at)
                            <p class="mt-1 text-xs text-neutral-400">Signed {{ $rd->customer_signed_at->format('d M Y, H:i') }}</p>
                        @endif
                    @endif
                </div>
            </div>
        </div>
    @elseif ($document->certificateDetail)
        <div class="card mb-4">
            <dl class="grid grid-cols-2 gap-4 text-sm">
                <div>
                    <dt class="text-neutral-500">Equipment</dt>
                    <dd class="text-neutral-900">
                        @if ($document->certificateDetail->equipment)
                            {{ $document->certificateDetail->equipment->model }} &middot; {{ $document->certificateDetail->equipment->serial_number }}
                        @else
                            —
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-neutral-500">Certificate type</dt>
                    <dd class="text-neutral-900">{{ $document->certificateDetail->certificate_type }}</dd>
                </div>
                <div>
                    <dt class="text-neutral-500">Issued</dt>
                    <dd class="text-neutral-900">{{ $document->certificateDetail->issued_at?->format('d M Y') ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-neutral-500">Expires</dt>
                    <dd class="text-neutral-900">{{ $document->certificateDetail->expires_at?->format('d M Y') ?? '—' }}</dd>
                </div>
            </dl>
            @if ($document->certificateDetail->file_path)
                <a href="{{ Storage::url($document->certificateDetail->file_path) }}" target="_blank" class="mt-4 inline-block text-xs font-medium text-info-700 hover:text-info-800">
                    View certificate file
                </a>
            @endif
        </div>
    @elseif ($document->lpoDetail)
        <div class="card mb-4">
            <dl class="grid grid-cols-2 gap-4 text-sm">
                <div>
                    <dt class="text-neutral-500">Quotation</dt>
                    <dd class="text-neutral-900">{{ $document->lpoDetail->quotation->reference ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-neutral-500">Received via</dt>
                    <dd class="text-neutral-900">{{ $document->lpoDetail->received_via }}</dd>
                </div>
            </dl>
            @if ($document->lpoDetail->file_path)
                <a href="{{ Storage::url($document->lpoDetail->file_path) }}" target="_blank" class="mt-4 inline-block text-xs font-medium text-info-700 hover:text-info-800">
                    View LPO file
                </a>
            @endif
        </div>
    @elseif ($document->voucherDetail)
        <div class="card mb-4">
            <dl class="grid grid-cols-2 gap-4 text-sm">
                <div>
                    <dt class="text-neutral-500">Contract</dt>
                    <dd class="text-neutral-900">{{ $document->voucherDetail->contract->reference ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-neutral-500">Technician</dt>
                    <dd class="text-neutral-900">{{ $document->voucherDetail->technician->name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-neutral-500">Client signatory</dt>
                    <dd class="text-neutral-900">{{ $document->voucherDetail->client_signatory ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-neutral-500">Signed</dt>
                    <dd class="text-neutral-900">{{ $document->voucherDetail->client_signed_at?->format('d M Y, H:i') ?? '—' }}</dd>
                </div>
            </dl>
        </div>
    @elseif ($document->deliveryNoteDetail)
        <div class="card mb-4">
            <dl class="space-y-3 text-sm">
                <div>
                    <dt class="mb-1 text-neutral-500">Items</dt>
                    <dd class="text-neutral-900">{{ $document->deliveryNoteDetail->items_description }}</dd>
                </div>
                <div>
                    <dt class="mb-1 text-neutral-500">Note</dt>
                    <dd class="text-neutral-900">{{ $document->deliveryNoteDetail->recipient_note }}</dd>
                </div>
            </dl>
        </div>
    @endif

    @can('review', $document)
        <button wire:click="approve" wire:loading.attr="disabled" wire:target="approve" class="btn-primary w-full">
            Approve and move job forward
        </button>
    @endcan

    @can('post', $document)
        <button wire:click="post" wire:loading.attr="disabled" wire:target="post" class="btn-primary w-full">
            Post to Finance
        </button>
    @endcan
</div>
