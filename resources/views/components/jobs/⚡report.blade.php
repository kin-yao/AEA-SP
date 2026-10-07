<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Computed;
use Livewire\WithFileUploads;
use App\Models\WorkOrder;
use App\Models\Document;
use App\Services\WorkflowNotifier;
use Illuminate\Support\Facades\DB;

new #[Layout('layouts.app', ['title' => 'Service report'])] class extends Component
{
    use WithFileUploads;

    public WorkOrder $job;

    public ?int $documentId = null;
    public string $reportNumber = '';
    public string $statusNote = 'Not yet submitted';

    // Header
    public string $reportDate = '';
    public string $vehicle = '';
    public string $equipment = '';

    // A. Customer
    public string $contactName = '';
    public string $address = '';
    public string $telNo = '';

    // B. Nature of visit
    public array $nature = [];

    // C. Job details
    public string $faultDescription = '';
    public string $cause = '';
    public string $correction = '';
    public string $finalResult = '';
    public string $partsToOrder = '';
    public string $customerComments = '';

    public array $parts = [];

    // Uploads
    public $deliveryNote = null;
    public string $deliveryNotePath = '';
    public $incidentPhoto = null;
    public string $incidentPhotoPath = '';

    // Maintenance voucher (only under a valid contract)
    public string $voucherNumber = '';
    public string $voucherSignature = '';

    // Incident
    public string $incidentType = 'None';
    public string $incidentDescription = '';

    // D. Sign off
    public string $repairerSignature = '';
    public string $customerSignoffName = '';
    public string $customerSignature = '';

    public array $natureOptions = [];

    public function mount(WorkOrder $job): void
    {
        $this->authorize('updateStatus', $job);
        $this->natureOptions = setting('nature_of_visit');

        if ($job->assigned_technician_id !== auth()->id()) {
            abort(403, 'This job is not assigned to you.');
        }

        if ($job->status !== 'On site') {
            abort(403, 'A report can only be filed while the job is On site.');
        }

        $this->job = $job->load(['customer', 'site', 'equipment', 'contract', 'sourceRequest']);

        $customer = $job->customer;

        // Sensible defaults from what the system already knows.
        $this->reportNumber = (string) (Document::max('id') + 1);
        $this->reportDate = now()->toDateString();
        $this->vehicle = (string) $job->vehicle;
        $this->equipment = (string) ($job->equipment_description
            ?: trim(($job->equipment?->model ?? '').' '.($job->equipment?->serial_number ?? '')));
        $this->contactName = (string) ($job->site?->contact_name ?: $customer->main_contact_name);
        $this->address = (string) ($job->site?->name ?: $customer->po_box);
        $this->telNo = (string) $customer->main_contact_phone;
        $this->faultDescription = (string) ($job->sourceRequest?->fault_description ?? '');
        $this->nature = in_array($job->nature_of_visit, $this->natureOptions, true) ? [$job->nature_of_visit] : [];
        $this->parts = [$this->blankPart()];

        // Pick up a saved draft, if there is one.
        $draft = Document::with('reportDetail.parts')
            ->where('type', Document::TYPE_REPORT)
            ->where('work_order_id', $job->id)
            ->where('filed_by', auth()->id())
            ->where('status', 'Draft')
            ->latest()
            ->first();

        if ($draft && $draft->reportDetail) {
            $d = $draft->reportDetail;

            $this->documentId = $draft->id;
            $this->reportNumber = $draft->reference;
            $this->statusNote = 'Draft saved '.$draft->updated_at->format('d M, H:i');
            $this->reportDate = $d->report_date?->toDateString() ?? $this->reportDate;
            $this->vehicle = (string) $d->vehicle;
            $this->equipment = (string) $d->equipment_description;
            $this->contactName = (string) $d->contact_name;
            $this->address = (string) $d->address;
            $this->telNo = (string) $d->tel_no;
            $this->nature = array_values(array_filter(array_map('trim', explode(',', (string) $d->nature_of_visit))));
            $this->faultDescription = (string) $d->fault_description;
            $this->cause = (string) $d->cause;
            $this->correction = (string) $d->correction;
            $this->finalResult = (string) $d->final_result;
            $this->partsToOrder = (string) $d->parts_to_order;
            $this->customerComments = (string) $d->customer_comments;
            $this->deliveryNotePath = (string) $d->delivery_note_path;
            $this->incidentPhotoPath = (string) $d->incident_photo_path;
            $this->voucherNumber = (string) $d->voucher_number;
            $this->voucherSignature = (string) $d->voucher_signature;
            $this->incidentType = $d->incident_type;
            $this->incidentDescription = (string) $d->incident_description;
            $this->repairerSignature = (string) $d->repairer_signature;
            $this->customerSignoffName = (string) $d->customer_signoff_name;
            $this->customerSignature = (string) $d->customer_signature;

            if ($d->parts->isNotEmpty()) {
                $this->parts = $d->parts->map(fn ($p) => [
                    'item' => $p->item,
                    'partNumber' => (string) $p->part_number,
                    'quantity' => $p->quantity,
                    'source' => (string) $p->source,
                    'returned' => $p->returned,
                ])->all();
            }
        }
    }

    protected function blankPart(): array
    {
        return ['item' => '', 'partNumber' => '', 'quantity' => 1, 'source' => '', 'returned' => 0];
    }

    public function addPart(): void
    {
        $this->parts[] = $this->blankPart();
    }

    public function removePart(int $index): void
    {
        unset($this->parts[$index]);
        $this->parts = array_values($this->parts);

        if (empty($this->parts)) {
            $this->parts = [$this->blankPart()];
        }
    }

    public function removeDeliveryNote(): void
    {
        $this->deliveryNote = null;
        $this->deliveryNotePath = '';
    }

    public function removeIncidentPhoto(): void
    {
        $this->incidentPhoto = null;
        $this->incidentPhotoPath = '';
    }

    #[Computed]
    public function contractValid(): bool
    {
        $contract = $this->job->contract;

        return $contract
            && $contract->status === 'Active'
            && ($contract->ends_at === null || $contract->ends_at->gte(today()));
    }

    protected function baseRules(): array
    {
        return [
            'reportDate' => ['required', 'date'],
            'vehicle' => ['nullable', 'string', 'max:255'],
            'equipment' => ['nullable', 'string', 'max:255'],
            'contactName' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'telNo' => ['nullable', 'string', 'max:50'],
            'nature' => ['array'],
            'nature.*' => ['in:'.implode(',', $this->natureOptions)],
            'faultDescription' => ['nullable', 'string'],
            'cause' => ['nullable', 'string'],
            'correction' => ['nullable', 'string'],
            'finalResult' => ['nullable', 'string'],
            'partsToOrder' => ['nullable', 'string'],
            'customerComments' => ['nullable', 'string'],
            'deliveryNote' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'incidentPhoto' => ['nullable', 'image', 'max:5120'],
            'incidentType' => ['required', 'in:None,Near miss,Damage,Safety issue'],
            'customerSignoffName' => ['nullable', 'string', 'max:255'],
            'voucherNumber' => ['nullable', 'string', 'max:100'],
            'parts.*.quantity' => ['nullable', 'integer', 'min:0'],
            'parts.*.returned' => ['nullable', 'integer', 'min:0'],
        ];
    }

    protected function persist(string $status): Document
    {
        $existingVoucher = Document::where('type', Document::TYPE_VOUCHER)
            ->where('work_order_id', $this->job->id)
            ->first();

        return DB::transaction(function () use ($status, $existingVoucher) {
            if ($this->deliveryNote) {
                $this->deliveryNotePath = $this->deliveryNote->store('service-reports', 'public');
                $this->deliveryNote = null;
            }

            if ($this->incidentPhoto) {
                $this->incidentPhotoPath = $this->incidentPhoto->store('service-reports', 'public');
                $this->incidentPhoto = null;
            }

            if ($this->documentId) {
                $document = Document::findOrFail($this->documentId);
                $document->update(['status' => $status]);
            } else {
                $reference = $this->reportNumber;

                if ($reference === '' || Document::where('reference', $reference)->exists()) {
                    $reference = (string) (Document::max('id') + 1);
                }

                $document = Document::create([
                    'reference' => $reference,
                    'type' => Document::TYPE_REPORT,
                    'work_order_id' => $this->job->id,
                    'customer_id' => $this->job->customer_id,
                    'status' => $status,
                    'filed_by' => auth()->id(),
                ]);

                $this->documentId = $document->id;
                $this->reportNumber = $document->reference;
            }

            $contract = $this->contractValid ? $this->job->contract : null;

            $detail = $document->reportDetail()->updateOrCreate(
                ['document_id' => $document->id],
                [
                    'report_date' => $this->reportDate ?: null,
                    'vehicle' => $this->vehicle ?: null,
                    'branch_id' => auth()->user()->branch_id,
                    'equipment_description' => $this->equipment ?: null,
                    'contact_name' => $this->contactName ?: null,
                    'address' => $this->address ?: null,
                    'tel_no' => $this->telNo ?: null,
                    'nature_of_visit' => count($this->nature) ? implode(', ', $this->nature) : $this->job->nature_of_visit,
                    'fault_description' => $this->faultDescription ?: null,
                    'cause' => $this->cause ?: null,
                    'correction' => $this->correction ?: null,
                    'final_result' => $this->finalResult ?: null,
                    'parts_to_order' => $this->partsToOrder ?: null,
                    'customer_comments' => $this->customerComments ?: null,
                    'delivery_note_path' => $this->deliveryNotePath ?: null,
                    'incident_type' => $this->incidentType,
                    'incident_description' => $this->incidentType === 'None' ? null : ($this->incidentDescription ?: null),
                    'incident_photo_path' => $this->incidentPhotoPath ?: null,
                    'contract_on_file' => $contract ? 'Yes, '.$contract->type : 'No',
                    'voucher_number' => $contract ? ($this->voucherNumber ?: null) : null,
                    'voucher_signature' => $contract ? ($this->voucherSignature ?: null) : null,
                    'repairer_name' => auth()->user()->name,
                    'repairer_signature' => $this->repairerSignature ?: null,
                    'customer_signoff_name' => $this->customerSignoffName ?: null,
                    'customer_signature' => $this->customerSignature ?: null,
                    'customer_signed_at' => $this->customerSignature ? now() : null,
                ]
            );

            $detail->parts()->delete();

            foreach ($this->parts as $part) {
                if (blank($part['item'])) {
                    continue;
                }

                $detail->parts()->create([
                    'item' => $part['item'],
                    'part_number' => $part['partNumber'] ?: null,
                    'quantity' => (int) ($part['quantity'] ?: 1),
                    'source' => $part['source'] ?: null,
                    'returned' => (int) ($part['returned'] ?: 0),
                ]);
            }

            // A maintenance voucher is its own document, linked to the report's job.
            if ($status !== 'Draft' && $contract && filled($this->voucherNumber)) {
                $voucher = $existingVoucher ?? Document::create([
                    'reference' => $this->voucherNumber,
                    'type' => Document::TYPE_VOUCHER,
                    'work_order_id' => $this->job->id,
                    'customer_id' => $this->job->customer_id,
                    'status' => 'Filed',
                    'filed_by' => auth()->id(),
                ]);

                $voucher->voucherDetail()->updateOrCreate(
                    ['document_id' => $voucher->id],
                    [
                        'contract_id' => $contract->id,
                        'technician_id' => auth()->id(),
                        'client_signatory' => $this->customerSignoffName ?: null,
                        'client_signed_at' => $this->voucherSignature ? now() : null,
                    ]
                );
            }

            return $document;
        });
    }

    public function saveDraft(): void
    {
        $this->validate($this->baseRules(), $this->messages());

        $document = $this->persist('Draft');

        $this->statusNote = 'Draft saved '.now()->format('d M, H:i');
        session()->flash('status', "Draft saved as report {$document->reference}. You can come back to it.");
    }

    public function submit(): void
    {
        $rules = array_merge($this->baseRules(), [
            'nature' => ['required', 'array', 'min:1'],
            'faultDescription' => ['required', 'string'],
            'cause' => ['required', 'string'],
            'correction' => ['required', 'string'],
            'finalResult' => ['required', 'string'],
            'incidentDescription' => ['required_unless:incidentType,None', 'nullable', 'string'],
            'repairerSignature' => ['required', 'string', 'regex:/^data:image\/png;base64,/', 'max:400000'],
            'customerSignoffName' => ['required', 'string', 'max:255'],
            'customerSignature' => ['required', 'string', 'regex:/^data:image\/png;base64,/', 'max:400000'],
        ]);

        if ($this->contractValid) {
            $rules['voucherNumber'] = [
                'required', 'string', 'max:100',
                \Illuminate\Validation\Rule::unique('documents', 'reference')->where(
                    fn ($q) => $q->where('type', Document::TYPE_VOUCHER)->where('work_order_id', '!=', $this->job->id)
                ),
            ];
            $rules['voucherSignature'] = ['required', 'string', 'regex:/^data:image\/png;base64,/', 'max:400000'];
        }

        $this->validate($rules, $this->messages());

        $document = $this->persist('Awaiting review');

        // Only a real filed report moves the job to Awaiting review.
        $this->job->update(['status' => 'Awaiting review']);

        WorkflowNotifier::role(
            'Supervisor',
            'Service report submitted for review',
            [
                "A report for job {$this->job->reference} has been submitted by ".auth()->user()->name.'.',
            ],
            url("/documents/{$document->id}"),
            'Review report',
        );

        $this->redirect('/jobs/'.$this->job->id, navigate: true);
    }

    protected function messages(): array
    {
        return [
            'repairerSignature.required' => 'Please sign above.',
            'repairerSignature.regex' => 'Please sign above.',
            'customerSignature.required' => 'The customer needs to sign here.',
            'customerSignature.regex' => 'The customer needs to sign here.',
            'voucherSignature.required' => 'The client signature and stamp are required for the voucher.',
            'voucherSignature.regex' => 'The client signature and stamp are required for the voucher.',
            'nature.required' => 'Tick at least one nature of visit.',
            'nature.min' => 'Tick at least one nature of visit.',
            'customerSignoffName.required' => 'Enter the customer name and title.',
            'voucherNumber.required' => 'Enter the voucher number.',
            'voucherNumber.unique' => 'That voucher number is already used.',
        ];
    }

    public function with(): array
    {
        return [
            'sources' => setting('part_sources'),
        ];
    }
};
?>

<div class="rf"
     x-data
     x-init="Livewire.hook('commit', ({ succeed }) => { succeed(() => { if (!window.rfSubmitting) return; window.rfSubmitting = false; $nextTick(() => { const e = document.querySelector('.rf-err'); if (e) e.scrollIntoView({ behavior: 'smooth', block: 'center' }); }); }); })">

    <a href="/jobs/{{ $job->id }}" wire:navigate class="mb-3 inline-flex items-center gap-1.5 text-sm font-medium text-neutral-500 hover:text-neutral-900" style="min-height: 36px">
        <x-icon name="arrow-right" class="h-3.5 w-3.5 rotate-180" />
        Back to {{ $job->reference }}
    </a>

    @if (session('status'))
        <div class="mb-3 rounded-[var(--radius-md)] bg-success-50 px-4 py-3 text-sm font-medium text-success-700">{{ session('status') }}</div>
    @endif

    {{-- Report strip --}}
    <div class="rf-hero mb-4">
        <div class="flex items-start justify-between gap-3">
            <div>
                <p class="rf-hero-label">Service report No.</p>
                <p class="rf-hero-no">{{ $reportNumber }}</p>
            </div>
            <span class="rf-status">{{ $statusNote }}</span>
        </div>
        <div class="rf-hero-grid">
            <div>
                <p class="rf-hero-label">Job</p>
                <p class="rf-hero-val">{{ $job->reference }}</p>
            </div>
            <div>
                <p class="rf-hero-label">Customer</p>
                <p class="rf-hero-val">{{ $job->customer->name }}</p>
            </div>
            <div style="grid-column: 1 / -1">
                <p class="rf-hero-label">Machine</p>
                <p class="rf-hero-val">{{ $equipment !== '' ? $equipment : 'Not specified' }}</p>
            </div>
        </div>
    </div>

    @if ($errors->any())
        <div class="mb-4 flex items-start gap-3 rounded-[var(--radius-md)] border border-critical-500 bg-critical-50 px-4 py-3 text-sm text-critical-700">
            <x-icon name="exclamation-circle" class="mt-0.5 h-5 w-5 shrink-0" />
            <p><span class="font-semibold">{{ $errors->count() }} {{ $errors->count() === 1 ? 'thing needs' : 'things need' }} your attention.</span> The first one is highlighted below.</p>
        </div>
    @endif

    <form wire:submit="submit" class="space-y-4">

        {{-- Header --}}
        <section class="rf-sec">
            <div class="rf-sec-head">
                <span class="rf-badge">1</span>
                <div>
                    <p class="rf-sec-title">Header</p>
                    <p class="rf-sec-sub">Date, vehicle and machine</p>
                </div>
            </div>
            <div class="rf-body">
                <div class="rf-grid">
                    <div>
                        <label class="label">Date</label>
                        <input type="date" wire:model="reportDate" class="input">
                        @error('reportDate') <p class="rf-err">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label">Vehicle No.</label>
                        <input type="text" wire:model="vehicle" autocapitalize="characters" autocomplete="off" class="input">
                    </div>
                    <div>
                        <label class="label">Branch</label>
                        <input type="text" value="{{ auth()->user()->branch?->name }}" disabled class="input">
                    </div>
                    <div>
                        <label class="label">Machine / equipment</label>
                        <input type="text" wire:model="equipment" class="input">
                    </div>
                </div>
            </div>
        </section>

        {{-- A. Customer --}}
        <section class="rf-sec">
            <div class="rf-sec-head">
                <span class="rf-badge">A</span>
                <div>
                    <p class="rf-sec-title">Customer</p>
                    <p class="rf-sec-sub">Who you served</p>
                </div>
            </div>
            <div class="rf-body">
                <div>
                    <label class="label">Customer</label>
                    <input type="text" value="{{ $job->customer->name }}" disabled class="input">
                </div>
                <div class="rf-grid">
                    <div>
                        <label class="label">Contact person</label>
                        <input type="text" wire:model="contactName" autocomplete="off" class="input">
                    </div>
                    <div>
                        <label class="label">Tel. No.</label>
                        <input type="tel" wire:model="telNo" inputmode="tel" autocomplete="off" class="input">
                    </div>
                </div>
                <div>
                    <label class="label">Address</label>
                    <input type="text" wire:model="address" autocomplete="off" class="input">
                </div>
            </div>
        </section>

        {{-- B. Nature of visit --}}
        <section class="rf-sec">
            <div class="rf-sec-head">
                <span class="rf-badge">B</span>
                <div>
                    <p class="rf-sec-title">Nature of visit</p>
                    <p class="rf-sec-sub">Tick all that apply</p>
                </div>
            </div>
            <div class="rf-body">
                <div class="rf-chips">
                    @foreach ($natureOptions as $option)
                        <label @class(['rf-chip', 'rf-chip-on' => in_array($option, $nature, true)])>
                            <input type="checkbox" wire:model.live="nature" value="{{ $option }}">
                            <span>{{ $option }}</span>
                        </label>
                    @endforeach
                </div>
                @error('nature') <p class="rf-err">{{ $message }}</p> @enderror
            </div>
        </section>

        {{-- C. Job details --}}
        <section class="rf-sec">
            <div class="rf-sec-head">
                <span class="rf-badge">C</span>
                <div>
                    <p class="rf-sec-title">Job details</p>
                    <p class="rf-sec-sub">What was wrong and what you did</p>
                </div>
            </div>
            <div class="rf-body">
                <div>
                    <label class="label">Fault reported</label>
                    <textarea wire:model="faultDescription" rows="3" placeholder="Describe what the machine was doing and any error shown." class="input"></textarea>
                    @error('faultDescription') <p class="rf-err">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label">Cause</label>
                    <textarea wire:model="cause" rows="3" class="input"></textarea>
                    @error('cause') <p class="rf-err">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label">Correction</label>
                    <textarea wire:model="correction" rows="4" class="input"></textarea>
                    @error('correction') <p class="rf-err">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label">Final result</label>
                    <textarea wire:model="finalResult" rows="3" class="input"></textarea>
                    @error('finalResult') <p class="rf-err">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label">Parts to order <span class="rf-opt">optional</span></label>
                    <textarea wire:model="partsToOrder" rows="2" class="input"></textarea>
                </div>
                <div>
                    <label class="label">Customer comments <span class="rf-opt">optional</span></label>
                    <textarea wire:model="customerComments" rows="2" class="input"></textarea>
                </div>
            </div>
        </section>

        {{-- Parts used --}}
        <section class="rf-sec">
            <div class="rf-sec-head">
                <span class="rf-badge">
                    <x-icon name="box-seam" class="h-4 w-4" />
                </span>
                <div>
                    <p class="rf-sec-title">Parts used</p>
                    <p class="rf-sec-sub">Everything fitted or replaced</p>
                </div>
            </div>
            <div class="rf-body">
                @foreach ($parts as $index => $part)
                    <div class="rf-part" wire:key="part-{{ $index }}">
                        <div class="rf-part-head">
                            <span>Part {{ $index + 1 }}</span>
                            @if (count($parts) > 1)
                                <button type="button" wire:click="removePart({{ $index }})" class="rf-remove">Remove</button>
                            @endif
                        </div>
                        <div class="space-y-3">
                            <div>
                                <label class="label">Item</label>
                                <input type="text" wire:model="parts.{{ $index }}.item" class="input">
                            </div>
                            <div class="rf-grid2">
                                <div>
                                    <label class="label">Part number</label>
                                    <input type="text" wire:model="parts.{{ $index }}.partNumber" autocapitalize="characters" autocomplete="off" class="input">
                                </div>
                                <div>
                                    <label class="label">Source</label>
                                    <input type="text" list="part-sources" wire:model="parts.{{ $index }}.source" class="input">
                                </div>
                                <div>
                                    <label class="label">Qty</label>
                                    <input type="number" inputmode="numeric" min="0" wire:model="parts.{{ $index }}.quantity" class="input">
                                </div>
                                <div>
                                    <label class="label">Returned</label>
                                    <input type="number" inputmode="numeric" min="0" wire:model="parts.{{ $index }}.returned" class="input">
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
                <datalist id="part-sources">
                    @foreach ($sources as $source)
                        <option value="{{ $source }}"></option>
                    @endforeach
                </datalist>
                <button type="button" wire:click="addPart" class="rf-add">+ Add a part</button>
            </div>
        </section>

        {{-- Delivery note --}}
        <section class="rf-sec">
            <div class="rf-sec-head">
                <span class="rf-badge">
                    <x-icon name="file-earmark-text" class="h-4 w-4" />
                </span>
                <div>
                    <p class="rf-sec-title">Delivery note</p>
                    <p class="rf-sec-sub">No signature needed</p>
                </div>
            </div>
            <div class="rf-body">
                @if ($deliveryNote || $deliveryNotePath)
                    <div class="rf-file">
                        <span class="truncate text-sm font-semibold text-success-700">
                            {{ $deliveryNote ? $deliveryNote->getClientOriginalName() : basename($deliveryNotePath) }}
                        </span>
                        <button type="button" wire:click="removeDeliveryNote" class="rf-remove">Remove</button>
                    </div>
                @else
                    <label class="rf-drop">
                        <span class="rf-drop-title">Upload delivery note</span>
                        <span class="rf-drop-sub">Take a photo or choose a PDF</span>
                        <span wire:loading wire:target="deliveryNote" class="rf-drop-sub" style="color: var(--color-info-700)">Uploading...</span>
                        <input type="file" wire:model="deliveryNote" accept=".pdf,.jpg,.jpeg,.png,image/*" class="sr-only">
                    </label>
                @endif
                @error('deliveryNote') <p class="rf-err">{{ $message }}</p> @enderror
            </div>
        </section>

        {{-- Maintenance voucher --}}
        <section class="rf-sec">
            <div class="rf-sec-head">
                <span class="rf-badge">
                    <x-icon name="chevron-bar-contract" class="h-4 w-4" />
                </span>
                <div>
                    <p class="rf-sec-title">Maintenance voucher</p>
                    <p class="rf-sec-sub">Only for jobs under a valid contract</p>
                </div>
            </div>
            <div class="rf-body">
                @if ($this->contractValid)
                    <div>
                        <label class="label">Contract on file</label>
                        <input type="text" value="Yes, {{ $job->contract->type }} ({{ $job->contract->reference }})" disabled class="input">
                    </div>
                    <div>
                        <label class="label">Voucher number</label>
                        <input type="text" wire:model="voucherNumber" placeholder="e.g. MV-2026-0413" autocapitalize="characters" autocomplete="off" class="input">
                        @error('voucherNumber') <p class="rf-err">{{ $message }}</p> @enderror
                    </div>
                    <x-signature-pad model="voucherSignature" :initial="$voucherSignature"
                                     label="Client authorised person signature and stamp"
                                     hint="Client signs here" />
                @else
                    <div class="rf-note">This job is not under a valid contract, so no voucher is needed.</div>
                @endif
            </div>
        </section>

        {{-- Incident --}}
        <section class="rf-sec">
            <div class="rf-sec-head">
                <span class="rf-badge">
                    <x-icon name="exclamation-circle" class="h-4 w-4" />
                </span>
                <div>
                    <p class="rf-sec-title">Incident, if any</p>
                    <p class="rf-sec-sub">Near misses, damage, safety issues</p>
                </div>
            </div>
            <div class="rf-body">
                <div>
                    <label class="label">Did anything happen on site worth logging?</label>
                    <select wire:model.live="incidentType" class="input">
                        <option>None</option>
                        <option>Near miss</option>
                        <option>Damage</option>
                        <option>Safety issue</option>
                    </select>
                </div>

                @if ($incidentType !== 'None')
                    <div>
                        <label class="label">What happened, when, where and who was involved</label>
                        <textarea wire:model="incidentDescription" rows="4" class="input"></textarea>
                        @error('incidentDescription') <p class="rf-err">{{ $message }}</p> @enderror
                    </div>

                    @if ($incidentPhoto || $incidentPhotoPath)
                        <div class="rf-file">
                            <span class="truncate text-sm font-semibold text-success-700">
                                {{ $incidentPhoto ? $incidentPhoto->getClientOriginalName() : basename($incidentPhotoPath) }}
                            </span>
                            <button type="button" wire:click="removeIncidentPhoto" class="rf-remove">Remove</button>
                        </div>
                    @else
                        <label class="rf-drop">
                            <span class="rf-drop-title">Attach a photo</span>
                            <span class="rf-drop-sub">Optional, if relevant</span>
                            <span wire:loading wire:target="incidentPhoto" class="rf-drop-sub" style="color: var(--color-info-700)">Uploading...</span>
                            <input type="file" wire:model="incidentPhoto" accept="image/*" class="sr-only">
                        </label>
                    @endif
                    @error('incidentPhoto') <p class="rf-err">{{ $message }}</p> @enderror
                @endif
            </div>
        </section>

        {{-- D. Sign off --}}
        <section class="rf-sec">
            <div class="rf-sec-head">
                <span class="rf-badge">D</span>
                <div>
                    <p class="rf-sec-title">Sign off</p>
                    <p class="rf-sec-sub">You sign, then the customer signs</p>
                </div>
            </div>
            <div class="rf-body">
                <div>
                    <label class="label">Name of repairer</label>
                    <input type="text" value="{{ auth()->user()->name }}" disabled class="input">
                </div>

                <x-signature-pad model="repairerSignature" :initial="$repairerSignature" label="Repairer signature" hint="Sign here" />

                <div>
                    <label class="label">Customer name and title</label>
                    <input type="text" wire:model="customerSignoffName" placeholder="e.g. Evans Mutiso, Stores Manager" autocomplete="off" class="input">
                    @error('customerSignoffName') <p class="rf-err">{{ $message }}</p> @enderror
                </div>

                <x-signature-pad model="customerSignature" :initial="$customerSignature" label="Customer signature" hint="Hand the phone to the customer to sign" />
            </div>
        </section>

        {{-- Action bar --}}
        <div class="rf-bar">
            <p class="rf-bar-hint">Submitting sends this report to the Supervisor to check.</p>
            <div class="rf-bar-row">
                <button type="button" wire:click="saveDraft" wire:loading.attr="disabled" wire:target="saveDraft" class="btn-outline" style="flex: 1">
                    <span wire:loading.remove wire:target="saveDraft">Save draft</span>
                    <span wire:loading wire:target="saveDraft">Saving...</span>
                </button>
                <button type="submit" @click="window.rfSubmitting = true" wire:loading.attr="disabled" wire:target="submit" class="btn-primary" style="flex: 1.4">
                    <span wire:loading.remove wire:target="submit">Submit report</span>
                    <span wire:loading wire:target="submit">Submitting...</span>
                </button>
            </div>
        </div>
    </form>
</div>
