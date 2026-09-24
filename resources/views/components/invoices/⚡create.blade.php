<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Models\WorkOrder;
use App\Models\Invoice;

new #[Layout('layouts.app', ['title' => 'New invoice'])] class extends Component
{
    public WorkOrder $job;
    public string $amount = '';
    public string $dueAt = '';

    public function mount(WorkOrder $job): void
    {
        $this->authorize('create', Invoice::class);

        $hasReleasedReport = $job->documents()->where('type', 'rep')->where('status', 'Released')->exists();

        if (! $hasReleasedReport || $job->invoices()->exists()) {
            abort(403, 'This job is not ready to invoice, or already has one.');
        }

        $this->job = $job;
        $this->dueAt = now()->addDays(30)->toDateString();

        // Pre-fill from the linked quotation's real total when one exists,
        // Finance can still adjust it, this just saves re-typing a figure
        // that's already been computed correctly once.
        if ($job->sourceQuotation) {
            $this->amount = number_format($job->sourceQuotation->totalMinor() / 100, 2, '.', '');
        }
    }

    public function submit(): void
    {
        $this->validate([
            'amount' => ['required', 'numeric', 'min:1'],
            'dueAt' => ['required', 'date'],
        ]);

        $invoice = Invoice::create([
            'reference' => 'INV-'.str_pad((string) (Invoice::max('id') + 1), 4, '0', STR_PAD_LEFT),
            'customer_id' => $this->job->customer_id,
            'work_order_id' => $this->job->id,
            'issued_at' => now(),
            'due_at' => $this->dueAt,
            'amount_minor' => (int) round(((float) $this->amount) * 100),
            'raised_by' => auth()->id(),
        ]);

        $this->redirect('/invoices/'.$invoice->id, navigate: true);
    }
};
?>

<div>
    <a href="/invoices" wire:navigate class="mb-4 inline-flex items-center gap-1.5 text-sm text-gray-500 hover:text-gray-700">
        <x-icon name="arrow-right" class="h-3.5 w-3.5 rotate-180" />
        Back to invoices
    </a>

    <h1 class="mb-1 text-xl font-semibold text-gray-900">New invoice</h1>
    <p class="mb-6 text-sm text-gray-500">{{ $job->reference }} &middot; {{ $job->customer->name }}</p>

    <form wire:submit="submit" class="rounded-xl border border-gray-200 bg-white p-5">
        <div class="mb-4">
            <label class="mb-1.5 block text-xs font-medium text-gray-700">Amount, KES</label>
            <input wire:model="amount" type="text" inputmode="decimal" placeholder="0.00"
                   class="w-full rounded-lg border border-gray-300 py-2 px-3 text-sm focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500">
            @error('amount') <p class="mt-1 text-xs text-primary-600">{{ $message }}</p> @enderror
        </div>

        <div class="mb-4">
            <label class="mb-1.5 block text-xs font-medium text-gray-700">Due date</label>
            <input wire:model="dueAt" type="date"
                   class="w-full rounded-lg border border-gray-300 py-2 px-3 text-sm focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500">
            @error('dueAt') <p class="mt-1 text-xs text-primary-600">{{ $message }}</p> @enderror
        </div>

        <button type="submit" wire:loading.attr="disabled" wire:target="submit"
                class="w-full rounded-lg bg-primary-500 py-2.5 text-sm font-medium text-white hover:bg-primary-600">
            Create invoice
        </button>
    </form>
</div>
