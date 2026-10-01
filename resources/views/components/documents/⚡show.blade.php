<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Models\Document;

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
    }

    public function post(): void
    {
        $this->authorize('post', $this->document);

        $this->document->update(['status' => 'Released']);
        $this->document->refresh();
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
        <div class="card mb-4">
            <dl class="grid grid-cols-2 gap-4 text-sm">
                <div>
                    <dt class="text-neutral-500">Contact</dt>
                    <dd class="text-neutral-900">{{ $document->reportDetail->contact_name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-neutral-500">Address</dt>
                    <dd class="text-neutral-900">{{ $document->reportDetail->address ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-neutral-500">Filed by</dt>
                    <dd class="text-neutral-900">{{ $document->filedBy->name }}</dd>
                </div>
                <div>
                    <dt class="text-neutral-500">Nature of visit</dt>
                    <dd class="text-neutral-900">{{ $document->reportDetail->nature_of_visit }}</dd>
                </div>
            </dl>
        </div>

        <div class="card mb-4">
            <h2 class="mb-3 text-sm font-semibold text-neutral-900">Job details</h2>
            <dl class="space-y-3 text-sm">
                <div>
                    <dt class="mb-1 text-neutral-500">Fault reported</dt>
                    <dd class="text-neutral-900">{{ $document->reportDetail->fault_description }}</dd>
                </div>
                <div>
                    <dt class="mb-1 text-neutral-500">Cause</dt>
                    <dd class="text-neutral-900">{{ $document->reportDetail->cause }}</dd>
                </div>
                <div>
                    <dt class="mb-1 text-neutral-500">Correction</dt>
                    <dd class="text-neutral-900">{{ $document->reportDetail->correction }}</dd>
                </div>
                <div>
                    <dt class="mb-1 text-neutral-500">Final result</dt>
                    <dd class="text-neutral-900">{{ $document->reportDetail->final_result }}</dd>
                </div>
            </dl>
        </div>

        @if ($document->reportDetail->parts->isNotEmpty())
            <div class="card mb-4">
                <h2 class="mb-3 text-sm font-semibold text-neutral-900">Parts used</h2>
                <div class="divide-y divide-neutral-100 text-sm">
                    @foreach ($document->reportDetail->parts as $part)
                        <div class="flex items-center justify-between py-2 first:pt-0 last:pb-0">
                            <span class="text-neutral-900">{{ $part->item }}</span>
                            <span class="text-neutral-500">{{ $part->quantity }} &middot; {{ $part->part_number ?? '—' }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        @if ($document->reportDetail->incident_type !== 'None')
            <div class="card mb-4" style="background-color: var(--color-critical-50); border-color: #f5c6cb">
                <h2 class="mb-2 text-sm font-semibold text-critical-900">Incident: {{ $document->reportDetail->incident_type }}</h2>
                <p class="text-sm text-critical-800">{{ $document->reportDetail->incident_description }}</p>
            </div>
        @endif

        <div class="card mb-4">
            <h2 class="mb-3 text-sm font-semibold text-neutral-900">Sign off</h2>
            <dl class="grid grid-cols-2 gap-4 text-sm">
                <div>
                    <dt class="text-neutral-500">Repairer</dt>
                    <dd class="text-neutral-900">{{ $document->reportDetail->repairer_name }}</dd>
                </div>
                <div>
                    <dt class="text-neutral-500">Customer</dt>
                    <dd class="text-neutral-900">{{ $document->reportDetail->customer_signoff_name ?? '—' }}</dd>
                </div>
            </dl>
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
