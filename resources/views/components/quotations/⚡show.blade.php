<?php

use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\Attributes\Layout;
use App\Models\Quotation;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\WorkflowNotifier;
use Barryvdh\DomPDF\Facade\Pdf;

new #[Layout('layouts.app', ['title' => 'Quotation'])] class extends Component
{
    use WithFileUploads;

    public Quotation $quotation;

    public string $lpoReference = '';
    public string $lpoReceivedVia = 'E-mail';
    public $lpoFile = null;

    public string $jobTechnicianId = '';
    public string $jobDueDate = '';

    public function mount(Quotation $quotation): void
    {
        $this->authorize('view', $quotation);
        $this->quotation = $quotation->load(['customer', 'site', 'items', 'lpoDetail', 'workOrder', 'createdBy']);
        $this->jobDueDate = now()->addDays((int) setting('job_due_days'))->toDateString();
    }

    public function getTechniciansProperty()
    {
        return User::role('Technician')->orderBy('name')->get();
    }

    public function approve(): void
    {
        $this->authorize('approve', $this->quotation);

        $this->quotation->approve();
        $this->quotation->refresh();

        WorkflowNotifier::customer(
            $this->quotation->customer,
            'Your quotation is ready',
            [
                "Quotation {$this->quotation->reference} has been approved and is ready for your review.",
            ],
        );

        WorkflowNotifier::user(
            $this->quotation->createdBy,
            'Your quotation was approved',
            [
                "Quotation {$this->quotation->reference} for {$this->quotation->customer->name} has been approved.",
            ],
            url("/quotations/{$this->quotation->id}"),
            'View quotation',
        );
    }

    public function sendBack(): void
    {
        $this->authorize('sendBack', $this->quotation);

        $this->quotation->sendBack();
        $this->quotation->refresh();

        WorkflowNotifier::user(
            $this->quotation->createdBy,
            'Quotation sent back for changes',
            [
                "Quotation {$this->quotation->reference} for {$this->quotation->customer->name} was sent back.",
            ],
            url("/quotations/{$this->quotation->id}"),
            'View quotation',
        );
    }

    public function logLpo(): void
    {
        $this->authorize('logLpo', $this->quotation);

        $this->validate([
            'lpoReference' => ['nullable', 'string', 'max:100'],
            'lpoReceivedVia' => ['required', 'string'],
            'lpoFile' => ['nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'],
        ]);

        $filePath = $this->lpoFile?->store('lpo-documents', 'public');

        $lpoNumber = trim($this->lpoReference) !== '' ? trim($this->lpoReference) : \App\Models\ReferenceSeries::next('lpo');

        $this->quotation->logLpo($lpoNumber, $this->lpoReceivedVia, auth()->id(), $filePath);
        $this->lpoReference = '';
        $this->quotation->refresh();
        $this->quotation->load('lpoDetail');
    }

    public function convertToJob(): void
    {
        $this->authorize('convertToJob', $this->quotation);

        $this->validate([
            'jobTechnicianId' => ['required', 'exists:users,id'],
            'jobDueDate' => ['required', 'date'],
        ]);

        $reference = \App\Models\ReferenceSeries::next('work_order');

        $this->quotation->convertToJob((int) $this->jobTechnicianId, $this->jobDueDate, $reference);
        $this->quotation->refresh();
        $this->quotation->load(['workOrder.technician']);

        WorkflowNotifier::customer(
            $this->quotation->customer,
            'Your service visit has been scheduled',
            [
                "Job {$this->quotation->workOrder->reference} has been scheduled for {$this->jobDueDate}.",
                'Technician: '.($this->quotation->workOrder->technician->name ?? 'to be confirmed'),
            ],
        );

        WorkflowNotifier::user(
            $this->quotation->workOrder->technician,
            'New job scheduled',
            [
                "Job {$this->quotation->workOrder->reference} for {$this->quotation->customer->name} is due {$this->jobDueDate}.",
            ],
            url("/jobs/{$this->quotation->workOrder->id}"),
            'View job',
        );
    }

    public function downloadPdf()
    {
        $this->authorize('view', $this->quotation);

        $pdf = Pdf::loadView('pdfs.quotation', [
            'quotation' => $this->quotation,
            'company' => \App\Support\Settings::company(),
            'banks' => \App\Models\BankAccount::forDocument($this->quotation->currency_code, $this->quotation->customer?->branch?->country_id),
        ]);

        return response()->streamDownload(
            fn () => print($pdf->output()),
            $this->quotation->reference.'.pdf'
        );
    }
};
?>

<div>
    <a href="/quotations" wire:navigate class="mb-4 inline-flex items-center gap-1.5 text-sm text-neutral-500 hover:text-neutral-900">
        <x-icon name="arrow-right" class="h-3.5 w-3.5 rotate-180" />
        Back to quotations
    </a>

    <div class="mb-4 flex items-start justify-between">
        <div>
            <h1 class="text-xl font-semibold text-neutral-900">{{ $quotation->reference }}</h1>
            <p class="text-sm text-neutral-500">{{ $quotation->customer->name }}</p>
        </div>
        <span @class([
            'shrink-0',
            'pill-neutral' => str_starts_with($quotation->status, 'Awaiting'),
            'pill-info' => in_array($quotation->status, ['Approved', 'Accepted']),
            'pill-success' => $quotation->status === 'Converted',
            'pill-danger' => $quotation->status === 'Sent back',
        ])>
            {{ $quotation->status }}
        </span>
    </div>

    <button wire:click="downloadPdf" wire:loading.attr="disabled" wire:target="downloadPdf" class="btn-outline mb-4">
        <x-icon name="folder" class="h-3.5 w-3.5" />
        Download PDF
    </button>

    <div class="card mb-4">
        <p class="mb-1 text-xs text-neutral-500">Scope</p>
        <p class="mb-4 text-sm font-semibold text-neutral-900">{{ $quotation->scope }}</p>

        <table class="table-clean">
            <thead>
                <tr>
                    <th class="text-left">Item</th>
                    <th class="text-right">Qty</th>
                    <th class="text-right">Rate</th>
                    <th class="text-right">Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($quotation->items as $item)
                    <tr>
                        <td class="text-neutral-900">{{ $item->description }}</td>
                        <td class="text-right text-neutral-600">{{ $item->quantity }}</td>
                        <td class="text-right text-neutral-600">{{ number_format($item->rate_minor / 100, 2) }}</td>
                        <td class="text-right text-neutral-900">{{ number_format($item->amountMinor() / 100, 2) }}</td>
                    </tr>
                @endforeach
                <tr>
                    <td class="text-neutral-900" colspan="3">Labour</td>
                    <td class="text-right text-neutral-900">{{ number_format($quotation->labour_minor / 100, 2) }}</td>
                </tr>
            </tbody>
        </table>

        <div class="mt-3 flex justify-end">
            <div class="w-48 text-sm">
                <div class="flex justify-between py-1">
                    <span class="text-neutral-500">Subtotal</span>
                    <span class="text-neutral-900">{{ number_format($quotation->subtotalMinor() / 100, 2) }}</span>
                </div>
                <div class="flex justify-between py-1">
                    <span class="text-neutral-500">VAT, {{ round($quotation->vat_rate * 100, 2) }}%</span>
                    <span class="text-neutral-900">{{ number_format($quotation->vatMinor() / 100, 2) }}</span>
                </div>
                <div class="flex justify-between border-t border-neutral-100 py-2 font-semibold">
                    <span class="text-neutral-900">Total</span>
                    <span class="text-neutral-900">{{ $quotation->currency_code }} {{ number_format($quotation->totalMinor() / 100, 2) }}</span>
                </div>
            </div>
        </div>

        <dl class="mt-4 grid grid-cols-2 gap-4 border-t border-neutral-100 pt-4 text-sm">
            <div>
                <dt class="text-neutral-500">Validity</dt>
                <dd class="text-neutral-900">{{ $quotation->validity_days }} days</dd>
            </div>
            <div>
                <dt class="text-neutral-500">Approval route</dt>
                <dd class="text-neutral-900">{{ $quotation->approval_threshold }}</dd>
            </div>
            <div>
                <dt class="text-neutral-500">Customer KRA PIN</dt>
                <dd class="text-neutral-900">{{ $quotation->customer->kra_pin ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-neutral-500">Payment terms</dt>
                <dd class="text-neutral-900">{{ $quotation->payment_terms ?? setting('payment_terms') }}</dd>
            </div>
        </dl>
    </div>

    @can('approve', $quotation)
        <div class="mb-4 flex gap-2">
            <button wire:click="approve" wire:loading.attr="disabled" wire:target="approve" class="btn-primary flex-1">
                Approve
            </button>
            <button wire:click="sendBack" wire:loading.attr="disabled" wire:target="sendBack" class="btn-outline flex-1">
                Send back
            </button>
        </div>
    @endcan

    @if ($quotation->lpoDetail)
        <div class="card mb-4">
            <p class="mb-1 flex items-center gap-1 text-xs text-neutral-500"><x-icon name="cart-check" class="h-3 w-3" /> LPO on file</p>
            <p class="text-sm font-semibold text-neutral-900">{{ $quotation->lpo_reference }}</p>
            <p class="text-xs text-neutral-500">Received via {{ $quotation->lpoDetail->received_via }}</p>
            @if ($quotation->lpoDetail->file_path)
                <a href="{{ Storage::url($quotation->lpoDetail->file_path) }}" target="_blank" class="mt-2 inline-block text-xs font-medium text-info-700 hover:text-info-800">
                    View uploaded document
                </a>
            @else
                <p class="mt-2 text-xs text-neutral-400">No document uploaded</p>
            @endif
        </div>
    @else
        @can('logLpo', $quotation)
            <div class="card mb-4">
                <h2 class="mb-3 flex items-center gap-1.5 text-sm font-semibold text-neutral-900"><x-icon name="cart-check" class="h-4 w-4" /> Log the customer's LPO</h2>
                <div class="mb-3">
                    <label class="label">LPO reference</label>
                    <input wire:model="lpoReference" type="text" placeholder="e.g. KSM-LPO-2291" class="input">
                    <p class="mt-1 text-xs text-neutral-400">Leave blank if the customer gave no number. The system will number it from the LPO series.</p>
                    @error('lpoReference') <p class="mt-1 text-xs text-critical-700">{{ $message }}</p> @enderror
                </div>
                <div class="mb-3">
                    <label class="label">Received via</label>
                    <select wire:model="lpoReceivedVia" class="input">
                        <option>E-mail</option>
                        <option>Hand delivered</option>
                        <option>Post</option>
                    </select>
                </div>
                <div class="mb-4">
                    <label class="label">Scanned document, optional</label>
                    <input wire:model="lpoFile" type="file" accept=".pdf,.jpg,.jpeg,.png"
                           class="input file:mr-3 file:border-0 file:bg-neutral-100 file:px-3 file:py-1.5 file:text-xs file:font-medium">
                    <div wire:loading wire:target="lpoFile" class="mt-1 text-xs text-neutral-500">Uploading...</div>
                    @error('lpoFile') <p class="mt-1 text-xs text-critical-700">{{ $message }}</p> @enderror
                </div>
                <button wire:click="logLpo" wire:loading.attr="disabled" wire:target="logLpo,lpoFile" class="btn-primary w-full">
                    Log LPO
                </button>
            </div>
        @endcan
    @endif

    @if ($quotation->workOrder)
        <div class="card mb-4">
            <p class="mb-1 text-xs text-neutral-500">Job</p>
            <a href="/jobs/{{ $quotation->workOrder->id }}" wire:navigate class="text-sm font-semibold text-info-700 hover:text-info-800">
                {{ $quotation->workOrder->reference }}
            </a>
        </div>
    @else
        @can('convertToJob', $quotation)
            <div class="card">
                <h2 class="mb-3 text-sm font-semibold text-neutral-900">Generate the job</h2>
                <div class="mb-3">
                    <label class="label">Technician</label>
                    <select wire:model="jobTechnicianId" class="input">
                        <option value="">Select a technician</option>
                        @foreach ($this->technicians as $technician)
                            <option value="{{ $technician->id }}">{{ $technician->name }}</option>
                        @endforeach
                    </select>
                    @error('jobTechnicianId') <p class="mt-1 text-xs text-critical-700">{{ $message }}</p> @enderror
                </div>
                <div class="mb-4">
                    <label class="label">Due date</label>
                    <input wire:model="jobDueDate" type="date" class="input">
                    @error('jobDueDate') <p class="mt-1 text-xs text-critical-700">{{ $message }}</p> @enderror
                </div>
                <button wire:click="convertToJob" wire:loading.attr="disabled" wire:target="convertToJob" class="btn-primary w-full">
                    Generate job
                </button>
            </div>
        @endcan
    @endif
</div>
