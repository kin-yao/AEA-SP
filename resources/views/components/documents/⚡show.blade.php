<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Models\Document;
use App\Services\Audit;
use App\Services\WorkflowNotifier;
use App\Support\Rules;

new #[Layout('layouts.app', ['title' => 'Document'])] class extends Component
{
    public Document $document;

    // Editing the LPO's prices
    public bool $editingLpo = false;
    public array $lpoItems = [];
    public string $lpoLabour = '';
    public string $lpoVat = '';
    public string $lpoNote = '';

    public array $typeLabels = [
        Document::TYPE_REPORT => 'Service report',
        Document::TYPE_CERTIFICATE => 'Calibration certificate',
        Document::TYPE_LPO => 'LPO',
        Document::TYPE_VOUCHER => 'Maintenance voucher',
        Document::TYPE_DELIVERY_NOTE => 'Delivery note',
        Document::TYPE_SCAN => 'Signed service report (hard copy)',
        Document::TYPE_OTHER => 'Other document',
    ];

    public function mount(Document $document): void
    {
        $this->authorize('view', $document);
        $document->lpoDetail?->ensureLines();
        $this->document = $document->load([
            'lpoDetail.items',
            'lpoDetail.editor',
            'lpoDetail.quotation.items',
            'lpoDetail.quotation.workOrder',
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

    public function startEditLpo(): void
    {
        $this->authorize('editLpo', $this->document);
        $lpo = $this->document->lpoDetail;

        $this->lpoItems = $lpo->items->map(fn ($i) => [
            'description' => $i->description,
            'quantity' => rtrim(rtrim(number_format($i->quantity, 2, '.', ''), '0'), '.'),
            'rate' => number_format($i->rate_minor / 100, 2, '.', ''),
        ])->all();
        $this->lpoLabour = number_format($lpo->labour_minor / 100, 2, '.', '');
        $this->lpoVat = rtrim(rtrim(number_format((float) $lpo->vat_rate * 100, 3, '.', ''), '0'), '.');
        $this->lpoNote = '';
        $this->editingLpo = true;
        $this->resetValidation();
    }

    public function cancelEditLpo(): void
    {
        $this->editingLpo = false;
        $this->resetValidation();
    }

    public function addLpoItem(): void
    {
        $this->lpoItems[] = ['description' => '', 'quantity' => '1', 'rate' => ''];
    }

    public function removeLpoItem(int $index): void
    {
        unset($this->lpoItems[$index]);
        $this->lpoItems = array_values($this->lpoItems);
    }

    public function lpoDraftMinor(): array
    {
        $sub = (int) min(collect($this->lpoItems)->sum(fn ($i) => round((float) ($i['quantity'] ?: 0) * (int) round((float) ($i['rate'] ?: 0) * 100))) + (int) round((float) ($this->lpoLabour ?: 0) * 100), 9.0e15);
        $vat = (int) round($sub * ((float) ($this->lpoVat ?: 0) / 100));

        return [$sub, $vat, $sub + $vat];
    }

    public function saveLpo(): void
    {
        $this->authorize('editLpo', $this->document);

        $this->validate([
            'lpoItems' => ['required', 'array', 'min:1', 'max:100'],
            'lpoItems.*.description' => ['required', 'string', 'min:2', 'max:500'],
            'lpoItems.*.quantity' => ['required', 'numeric', 'min:0.01', 'max:1000000', 'decimal:0,2'],
            'lpoItems.*.rate' => Rules::money(),
            'lpoLabour' => Rules::money(false),
            'lpoVat' => ['required', 'numeric', 'min:0', 'max:100'],
            'lpoNote' => Rules::text(500, true, 3),
        ], [
            'lpoNote.required' => 'Say briefly why the prices changed, for example "price agreed by phone on 8 Oct".',
        ], ['lpoNote' => 'reason']);

        [, , $total] = $this->lpoDraftMinor();
        if ($total > Rules::MAX_TOTAL_MINOR) {
            $this->addError('lpoItems', 'The LPO total is too large. Keep it under '.number_format(Rules::MAX_TOTAL_MINOR / 100, 2).'.');

            return;
        }

        $lpo = $this->document->lpoDetail;
        $before = $lpo->totalMinor();

        \Illuminate\Support\Facades\DB::transaction(function () use ($lpo, $total) {
            $lpo->items()->delete();
            foreach (array_values($this->lpoItems) as $i => $item) {
                $lpo->items()->create([
                    'description' => $item['description'],
                    'quantity' => $item['quantity'],
                    'rate_minor' => (int) round((float) $item['rate'] * 100),
                    'sort_order' => $i,
                ]);
            }
            $lpo->update([
                'labour_minor' => (int) round((float) ($this->lpoLabour ?: 0) * 100),
                'vat_rate' => ((float) $this->lpoVat) / 100,
                'change_note' => trim($this->lpoNote),
                'edited_by' => auth()->id(),
                'edited_at' => now(),
            ]);

            // The job's agreed value follows the LPO.
            $lpo->quotation?->workOrder?->update(['value_minor' => $total]);
        });

        $cur = $lpo->currency_code;
        Audit::record('updated', 'Changed LPO '.$this->document->reference.' prices: '.$cur.' '.number_format($before / 100, 2).' to '.$cur.' '.number_format($total / 100, 2), $this->document);

        $this->editingLpo = false;
        $this->document->refresh()->load(['lpoDetail.items', 'lpoDetail.editor', 'lpoDetail.quotation.items', 'lpoDetail.quotation.workOrder']);
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
                'Finance',
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
        @php
            $lpo = $document->lpoDetail;
            $cur = $lpo->currency_code;
            $qt = $lpo->quotation;
            $diff = $qt ? $lpo->totalMinor() - $qt->totalMinor() : 0;
            $invoiced = (bool) ($qt?->workOrder?->invoices()->exists());
        @endphp
        <div class="card mb-4">
            <dl class="grid grid-cols-2 gap-4 text-sm">
                <div>
                    <dt class="text-neutral-500">Original quotation</dt>
                    <dd class="text-neutral-900">
                        @if ($qt)
                            @can('view', $qt)
                                <a href="/quotations/{{ $qt->id }}" wire:navigate class="font-medium text-info-700 hover:text-info-800">{{ $qt->reference }}</a>
                            @else
                                {{ $qt->reference }}
                            @endcan
                        @else
                            —
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-neutral-500">Received via</dt>
                    <dd class="text-neutral-900">{{ $lpo->received_via }}</dd>
                </div>
            </dl>
            @if ($lpo->file_path)
                <a href="{{ Storage::url($lpo->file_path) }}" target="_blank" class="mt-4 inline-block text-xs font-medium text-info-700 hover:text-info-800">
                    View LPO file
                </a>
            @endif
        </div>

        <div class="card mb-4">
            <div class="mb-1 flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-sm font-semibold text-neutral-900">Agreed prices</h2>
                @can('editLpo', $document)
                    @if (! $editingLpo)
                        <button type="button" wire:click="startEditLpo" class="btn-outline" style="padding: 0.35rem 0.75rem; min-height: 0">Edit prices</button>
                    @endif
                @endcan
            </div>
            <p class="mb-3 text-xs text-neutral-500">This is what the customer agreed to pay. Invoices are raised from these prices.</p>

            @if (! $editingLpo)
                <div class="text-sm">
                    @foreach ($lpo->items as $item)
                        <div class="flex justify-between gap-3 border-b border-neutral-100 py-1.5">
                            <span class="text-neutral-900">{{ $item->description }} <span class="text-neutral-400">x {{ rtrim(rtrim(number_format($item->quantity, 2), '0'), '.') }}</span></span>
                            <span class="shrink-0 text-neutral-900">{{ number_format($item->amountMinor() / 100, 2) }}</span>
                        </div>
                    @endforeach
                    @if ($lpo->labour_minor > 0)
                        <div class="flex justify-between gap-3 border-b border-neutral-100 py-1.5"><span class="text-neutral-900">Labour</span><span>{{ number_format($lpo->labour_minor / 100, 2) }}</span></div>
                    @endif
                    <div class="flex justify-between py-1.5"><span class="text-neutral-500">Subtotal</span><span>{{ number_format($lpo->subtotalMinor() / 100, 2) }}</span></div>
                    <div class="flex justify-between py-1.5"><span class="text-neutral-500">VAT, {{ rtrim(rtrim(number_format((float) $lpo->vat_rate * 100, 3), '0'), '.') }}%</span><span>{{ number_format($lpo->vatMinor() / 100, 2) }}</span></div>
                    <div class="flex justify-between border-t border-neutral-200 py-2 font-semibold"><span>Total</span><span>{{ $cur }} {{ number_format($lpo->totalMinor() / 100, 2) }}</span></div>
                </div>

                @if ($qt)
                    <p class="mt-1 text-xs {{ $diff === 0 ? 'text-neutral-400' : 'text-amber-700' }}">
                        @if ($diff === 0)
                            Same as the quotation ({{ $cur }} {{ number_format($qt->totalMinor() / 100, 2) }}).
                        @else
                            Quotation was {{ $cur }} {{ number_format($qt->totalMinor() / 100, 2) }}. The LPO is {{ $diff > 0 ? 'higher' : 'lower' }} by {{ $cur }} {{ number_format(abs($diff) / 100, 2) }}.
                        @endif
                    </p>
                @endif

                @if ($lpo->edited_at)
                    <p class="mt-2 text-xs text-neutral-500">Prices changed {{ $lpo->edited_at->format('d M Y, H:i') }}@if ($lpo->editor) by {{ $lpo->editor->name }}@endif: {{ $lpo->change_note }}</p>
                @endif

                @if ($invoiced)
                    <p class="mt-2 text-xs text-neutral-400">An invoice has been raised from this LPO, so its prices are locked.</p>
                @endif
            @else
                <div class="mb-3 space-y-2">
                    @foreach ($lpoItems as $index => $item)
                        <div class="flex gap-2" wire:key="lpo-line-{{ $index }}">
                            <input wire:model.live.debounce.400ms="lpoItems.{{ $index }}.description" type="text" placeholder="Description" class="input flex-1">
                            <input wire:model.live.debounce.400ms="lpoItems.{{ $index }}.quantity" type="text" inputmode="decimal" placeholder="Qty" class="input w-16">
                            <input wire:model.live.debounce.400ms="lpoItems.{{ $index }}.rate" type="text" inputmode="decimal" placeholder="Rate" class="input w-24">
                            @if (count($lpoItems) > 1)
                                <button type="button" wire:click="removeLpoItem({{ $index }})" class="px-2 text-neutral-400 hover:text-critical-700">&times;</button>
                            @endif
                        </div>
                        @foreach (['description', 'quantity', 'rate'] as $col)
                            @error("lpoItems.$index.$col") <p class="field-error" style="margin:-0.25rem 0 0.5rem">{{ $message }}</p> @enderror
                        @endforeach
                    @endforeach
                </div>
                @error('lpoItems') <p class="field-error">{{ $message }}</p> @enderror
                <button type="button" wire:click="addLpoItem" class="mb-4 text-xs font-medium text-info-700 hover:text-info-800">+ Add line</button>

                <div class="mb-3 grid grid-cols-2 gap-4">
                    <div>
                        <label class="label">Labour, {{ $cur }}</label>
                        <input wire:model.live.debounce.400ms="lpoLabour" type="text" inputmode="decimal" class="input">
                        @error('lpoLabour') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label">VAT rate, %</label>
                        <input wire:model.live.debounce.400ms="lpoVat" type="text" inputmode="decimal" class="input">
                        @error('lpoVat') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                </div>

                @php [$dSub, $dVat, $dTot] = $this->lpoDraftMinor(); @endphp
                <div class="mb-3 flex justify-end text-sm">
                    <div class="w-56">
                        <div class="flex justify-between py-1"><span class="text-neutral-500">Subtotal</span><span>{{ number_format($dSub / 100, 2) }}</span></div>
                        <div class="flex justify-between py-1"><span class="text-neutral-500">VAT</span><span>{{ number_format($dVat / 100, 2) }}</span></div>
                        <div class="flex justify-between border-t border-neutral-200 py-2 font-semibold"><span>Total</span><span>{{ $cur }} {{ number_format($dTot / 100, 2) }}</span></div>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="label">Why did the prices change?</label>
                    <input wire:model="lpoNote" type="text" maxlength="500" class="input" placeholder="e.g. Discount agreed with the customer by phone">
                    @error('lpoNote') <p class="field-error">{{ $message }}</p> @enderror
                </div>

                <div class="flex gap-2">
                    <button type="button" wire:click="saveLpo" wire:loading.attr="disabled" wire:target="saveLpo" class="btn-primary">Save prices</button>
                    <button type="button" wire:click="cancelEditLpo" class="btn-outline">Cancel</button>
                </div>
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

    @if ($document->file_path && ! $document->reportDetail && ! $document->certificateDetail)
        <div class="card mb-4 flex flex-wrap items-center justify-between gap-3">
            <div style="min-width: 0">
                <p class="text-sm font-medium text-neutral-900">Attached file</p>
                <p class="text-xs text-neutral-500">{{ $document->title ?: ($document->file_name ?: 'Scanned copy') }}</p>
            </div>
            <a href="{{ Storage::url($document->file_path) }}" target="_blank" rel="noopener" class="btn-primary">Open file</a>
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
