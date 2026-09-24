<?php

use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\Attributes\Layout;
use App\Models\Quotation;
use App\Models\User;
use App\Models\WorkOrder;

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
        $this->quotation = $quotation->load(['customer', 'site', 'items', 'lpoDetail', 'workOrder']);
        $this->jobDueDate = now()->addDays(3)->toDateString();
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
    }

    public function sendBack(): void
    {
        $this->authorize('sendBack', $this->quotation);

        $this->quotation->sendBack();
        $this->quotation->refresh();
    }

    public function logLpo(): void
    {
        $this->authorize('logLpo', $this->quotation);

        $this->validate([
            'lpoReference' => ['required', 'string'],
            'lpoReceivedVia' => ['required', 'string'],
            'lpoFile' => ['nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'],
        ]);

        $filePath = $this->lpoFile?->store('lpo-documents', 'public');

        $this->quotation->logLpo($this->lpoReference, $this->lpoReceivedVia, auth()->id(), $filePath);
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

        $reference = 'WO-'.str_pad((string) (WorkOrder::max('id') + 1), 4, '0', STR_PAD_LEFT);

        $this->quotation->convertToJob((int) $this->jobTechnicianId, $this->jobDueDate, $reference);
        $this->quotation->refresh();
        $this->quotation->load('workOrder');
    }
};
?>

<div>
    <a href="/quotations" wire:navigate class="mb-4 inline-flex items-center gap-1.5 text-sm text-gray-500 hover:text-gray-700">
        <x-icon name="arrow-right" class="h-3.5 w-3.5 rotate-180" />
        Back to quotations
    </a>

    <div class="mb-4 flex items-start justify-between">
        <div>
            <h1 class="text-xl font-semibold text-gray-900">{{ $quotation->reference }}</h1>
            <p class="text-sm text-gray-500">{{ $quotation->customer->name }}</p>
        </div>
        <span @class([
            'shrink-0 rounded-full px-2.5 py-1 text-xs font-medium',
            'bg-amber-50 text-amber-700' => str_starts_with($quotation->status, 'Awaiting'),
            'bg-blue-50 text-blue-700' => $quotation->status === 'Approved',
            'bg-primary-50 text-primary-700' => $quotation->status === 'Accepted',
            'bg-green-50 text-green-700' => $quotation->status === 'Converted',
            'bg-gray-100 text-gray-600' => $quotation->status === 'Sent back',
        ])>
            {{ $quotation->status }}
        </span>
    </div>

    <div class="mb-4 rounded-xl border border-gray-200 bg-white p-5">
        <p class="mb-1 text-xs text-gray-500">Scope</p>
        <p class="mb-4 text-sm font-medium text-gray-900">{{ $quotation->scope }}</p>

        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-100 text-xs text-gray-500">
                    <th class="pb-2 text-left font-medium">Item</th>
                    <th class="pb-2 text-right font-medium">Qty</th>
                    <th class="pb-2 text-right font-medium">Rate</th>
                    <th class="pb-2 text-right font-medium">Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($quotation->items as $item)
                    <tr class="border-b border-gray-50">
                        <td class="py-2 text-gray-900">{{ $item->description }}</td>
                        <td class="py-2 text-right text-gray-600">{{ $item->quantity }}</td>
                        <td class="py-2 text-right text-gray-600">{{ number_format($item->rate_minor / 100, 2) }}</td>
                        <td class="py-2 text-right text-gray-900">{{ number_format($item->amountMinor() / 100, 2) }}</td>
                    </tr>
                @endforeach
                <tr class="border-b border-gray-50">
                    <td class="py-2 text-gray-900" colspan="3">Labour</td>
                    <td class="py-2 text-right text-gray-900">{{ number_format($quotation->labour_minor / 100, 2) }}</td>
                </tr>
            </tbody>
        </table>

        <div class="mt-3 flex justify-end">
            <div class="w-48 text-sm">
                <div class="flex justify-between py-1">
                    <span class="text-gray-500">Subtotal</span>
                    <span class="text-gray-900">{{ number_format($quotation->subtotalMinor() / 100, 2) }}</span>
                </div>
                <div class="flex justify-between py-1">
                    <span class="text-gray-500">VAT, 16%</span>
                    <span class="text-gray-900">{{ number_format($quotation->vatMinor() / 100, 2) }}</span>
                </div>
                <div class="flex justify-between border-t border-gray-100 py-2 font-medium">
                    <span class="text-gray-900">Total</span>
                    <span class="text-gray-900">KES {{ number_format($quotation->totalMinor() / 100, 2) }}</span>
                </div>
            </div>
        </div>

        <dl class="mt-4 grid grid-cols-2 gap-4 border-t border-gray-100 pt-4 text-sm">
            <div>
                <dt class="text-gray-500">Validity</dt>
                <dd class="text-gray-900">{{ $quotation->validity_days }} days</dd>
            </div>
            <div>
                <dt class="text-gray-500">Approval route</dt>
                <dd class="text-gray-900">{{ $quotation->approval_threshold }}</dd>
            </div>
        </dl>
    </div>

    @can('approve', $quotation)
        <div class="mb-4 flex gap-2">
            <button wire:click="approve" wire:loading.attr="disabled" wire:target="approve"
                    class="flex-1 rounded-lg bg-primary-500 py-2.5 text-sm font-medium text-white hover:bg-primary-600">
                Approve
            </button>
            <button wire:click="sendBack" wire:loading.attr="disabled" wire:target="sendBack"
                    class="flex-1 rounded-lg border border-gray-300 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50">
                Send back
            </button>
        </div>
    @endcan

    @if ($quotation->lpoDetail)
        <div class="mb-4 rounded-xl border border-gray-200 bg-white p-5">
            <p class="mb-1 flex items-center gap-1 text-xs text-gray-500"><x-icon name="cart-check" class="h-3 w-3" /> LPO on file</p>
            <p class="text-sm font-medium text-gray-900">{{ $quotation->lpo_reference }}</p>
            <p class="text-xs text-gray-500">Received via {{ $quotation->lpoDetail->received_via }}</p>
            @if ($quotation->lpoDetail->file_path)
                <a href="{{ Storage::url($quotation->lpoDetail->file_path) }}" target="_blank" class="mt-2 inline-block text-xs font-medium text-primary-600 hover:text-primary-700">
                    View uploaded document
                </a>
            @else
                <p class="mt-2 text-xs text-gray-400">No document uploaded</p>
            @endif
        </div>
    @else
        @can('logLpo', $quotation)
            <div class="mb-4 rounded-xl border border-gray-200 bg-white p-5">
                <h2 class="mb-3 flex items-center gap-1.5 text-sm font-medium text-gray-900"><x-icon name="cart-check" class="h-4 w-4" /> Log the customer's LPO</h2>
                <div class="mb-3">
                    <label class="mb-1.5 block text-xs font-medium text-gray-700">LPO reference</label>
                    <input wire:model="lpoReference" type="text" placeholder="e.g. KSM-LPO-2291"
                           class="w-full rounded-lg border border-gray-300 py-2 px-3 text-sm focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500">
                    @error('lpoReference') <p class="mt-1 text-xs text-primary-600">{{ $message }}</p> @enderror
                </div>
                <div class="mb-3">
                    <label class="mb-1.5 block text-xs font-medium text-gray-700">Received via</label>
                    <select wire:model="lpoReceivedVia" class="w-full rounded-lg border border-gray-300 py-2 px-3 text-sm focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500">
                        <option>E-mail</option>
                        <option>Hand delivered</option>
                        <option>Post</option>
                    </select>
                </div>
                <div class="mb-4">
                    <label class="mb-1.5 block text-xs font-medium text-gray-700">Scanned document, optional</label>
                    <input wire:model="lpoFile" type="file" accept=".pdf,.jpg,.jpeg,.png"
                           class="w-full rounded-lg border border-gray-300 py-2 px-3 text-sm file:mr-3 file:rounded-md file:border-0 file:bg-gray-100 file:px-3 file:py-1.5 file:text-xs file:font-medium">
                    <div wire:loading wire:target="lpoFile" class="mt-1 text-xs text-gray-500">Uploading...</div>
                    @error('lpoFile') <p class="mt-1 text-xs text-primary-600">{{ $message }}</p> @enderror
                </div>
                <button wire:click="logLpo" wire:loading.attr="disabled" wire:target="logLpo,lpoFile"
                        class="w-full rounded-lg bg-primary-500 py-2.5 text-sm font-medium text-white hover:bg-primary-600">
                    Log LPO
                </button>
            </div>
        @endcan
    @endif

    @if ($quotation->workOrder)
        <div class="mb-4 rounded-xl border border-gray-200 bg-white p-5">
            <p class="mb-1 text-xs text-gray-500">Job</p>
            <a href="/jobs/{{ $quotation->workOrder->id }}" wire:navigate class="text-sm font-medium text-primary-600 hover:text-primary-700">
                {{ $quotation->workOrder->reference }}
            </a>
        </div>
    @else
        @can('convertToJob', $quotation)
            <div class="rounded-xl border border-gray-200 bg-white p-5">
                <h2 class="mb-3 text-sm font-medium text-gray-900">Generate the job</h2>
                <div class="mb-3">
                    <label class="mb-1.5 block text-xs font-medium text-gray-700">Technician</label>
                    <select wire:model="jobTechnicianId" class="w-full rounded-lg border border-gray-300 py-2 px-3 text-sm focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500">
                        <option value="">Select a technician</option>
                        @foreach ($this->technicians as $technician)
                            <option value="{{ $technician->id }}">{{ $technician->name }}</option>
                        @endforeach
                    </select>
                    @error('jobTechnicianId') <p class="mt-1 text-xs text-primary-600">{{ $message }}</p> @enderror
                </div>
                <div class="mb-4">
                    <label class="mb-1.5 block text-xs font-medium text-gray-700">Due date</label>
                    <input wire:model="jobDueDate" type="date"
                           class="w-full rounded-lg border border-gray-300 py-2 px-3 text-sm focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500">
                    @error('jobDueDate') <p class="mt-1 text-xs text-primary-600">{{ $message }}</p> @enderror
                </div>
                <button wire:click="convertToJob" wire:loading.attr="disabled" wire:target="convertToJob"
                        class="w-full rounded-lg bg-primary-500 py-2.5 text-sm font-medium text-white hover:bg-primary-600">
                    Generate job
                </button>
            </div>
        @endcan
    @endif
</div>
