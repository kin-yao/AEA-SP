<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Models\Document;

new #[Layout('layouts.app', ['title' => 'Document'])] class extends Component
{
    public Document $document;

    public function mount(Document $document): void
    {
        $this->authorize('view', $document);
        $this->document = $document->load(['reportDetail.parts', 'customer', 'workOrder', 'filedBy']);
    }

    public function approve(): void
    {
        $this->authorize('review', $this->document);

        $this->document->update(['status' => 'Checked, ready to post']);
        $this->document->refresh();

        $this->document->workOrder?->update(['status' => 'Approved']);
    }

    // Releasing the report is what makes the job visible to Finance as
    // ready to invoice, see Invoices/Create, which only lists jobs with a
    // Released report and no invoice yet.
    public function post(): void
    {
        $this->authorize('post', $this->document);

        $this->document->update(['status' => 'Released']);
        $this->document->refresh();
    }
};
?>

<div>
    <a href="/documents" wire:navigate class="mb-4 inline-flex items-center gap-1.5 text-sm text-gray-500 hover:text-gray-700">
        <x-icon name="arrow-right" class="h-3.5 w-3.5 rotate-180" />
        Back to documents
    </a>

    <div class="mb-4 flex items-start justify-between">
        <div>
            <h1 class="text-xl font-semibold text-gray-900">Service report {{ $document->reference }}</h1>
            <p class="text-sm text-gray-500">{{ $document->customer->name }} &middot; {{ $document->workOrder?->reference }}</p>
        </div>
        <span @class([
            'shrink-0 rounded-full px-2.5 py-1 text-xs font-medium',
            'bg-primary-50 text-primary-700' => $document->status === 'Awaiting review',
            'bg-blue-50 text-blue-700' => $document->status === 'Checked, ready to post',
            'bg-green-50 text-green-700' => $document->status === 'Released',
        ])>
            {{ $document->status }}
        </span>
    </div>

    @if ($document->reportDetail)
        <div class="mb-4 rounded-xl border border-gray-200 bg-white p-5">
            <dl class="grid grid-cols-2 gap-4 text-sm">
                <div>
                    <dt class="text-gray-500">Contact</dt>
                    <dd class="text-gray-900">{{ $document->reportDetail->contact_name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">Address</dt>
                    <dd class="text-gray-900">{{ $document->reportDetail->address ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">Filed by</dt>
                    <dd class="text-gray-900">{{ $document->filedBy->name }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">Nature of visit</dt>
                    <dd class="text-gray-900">{{ $document->reportDetail->nature_of_visit }}</dd>
                </div>
            </dl>
        </div>

        <div class="mb-4 rounded-xl border border-gray-200 bg-white p-5">
            <h2 class="mb-3 text-sm font-medium text-gray-900">Job details</h2>
            <dl class="space-y-3 text-sm">
                <div>
                    <dt class="mb-1 text-gray-500">Fault reported</dt>
                    <dd class="text-gray-900">{{ $document->reportDetail->fault_description }}</dd>
                </div>
                <div>
                    <dt class="mb-1 text-gray-500">Cause</dt>
                    <dd class="text-gray-900">{{ $document->reportDetail->cause }}</dd>
                </div>
                <div>
                    <dt class="mb-1 text-gray-500">Correction</dt>
                    <dd class="text-gray-900">{{ $document->reportDetail->correction }}</dd>
                </div>
                <div>
                    <dt class="mb-1 text-gray-500">Final result</dt>
                    <dd class="text-gray-900">{{ $document->reportDetail->final_result }}</dd>
                </div>
            </dl>
        </div>

        @if ($document->reportDetail->parts->isNotEmpty())
            <div class="mb-4 rounded-xl border border-gray-200 bg-white p-5">
                <h2 class="mb-3 text-sm font-medium text-gray-900">Parts used</h2>
                <div class="space-y-2 text-sm">
                    @foreach ($document->reportDetail->parts as $part)
                        <div class="flex items-center justify-between border-b border-gray-100 pb-2 last:border-0 last:pb-0">
                            <span class="text-gray-900">{{ $part->item }}</span>
                            <span class="text-gray-500">{{ $part->quantity }} &middot; {{ $part->part_number ?? '—' }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        @if ($document->reportDetail->incident_type !== 'None')
            <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 p-5">
                <h2 class="mb-2 text-sm font-medium text-amber-900">Incident: {{ $document->reportDetail->incident_type }}</h2>
                <p class="text-sm text-amber-800">{{ $document->reportDetail->incident_description }}</p>
            </div>
        @endif

        <div class="mb-4 rounded-xl border border-gray-200 bg-white p-5">
            <h2 class="mb-3 text-sm font-medium text-gray-900">Sign off</h2>
            <dl class="grid grid-cols-2 gap-4 text-sm">
                <div>
                    <dt class="text-gray-500">Repairer</dt>
                    <dd class="text-gray-900">{{ $document->reportDetail->repairer_name }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">Customer</dt>
                    <dd class="text-gray-900">{{ $document->reportDetail->customer_signoff_name ?? '—' }}</dd>
                </div>
            </dl>
        </div>
    @endif

    @can('review', $document)
        <button wire:click="approve" wire:loading.attr="disabled" wire:target="approve"
                class="w-full rounded-lg bg-primary-500 py-2.5 text-sm font-medium text-white hover:bg-primary-600">
            Approve and move job forward
        </button>
    @endcan

    @can('post', $document)
        <button wire:click="post" wire:loading.attr="disabled" wire:target="post"
                class="w-full rounded-lg bg-primary-500 py-2.5 text-sm font-medium text-white hover:bg-primary-600">
            Post to Finance
        </button>
    @endcan
</div>
