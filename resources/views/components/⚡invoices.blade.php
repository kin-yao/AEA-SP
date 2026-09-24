<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Models\Invoice;
use App\Models\WorkOrder;

new #[Layout('layouts.app', ['title' => 'Invoices'])] class extends Component
{
    public function mount(): void
    {
        $this->authorize('viewAny', Invoice::class);
    }

    public function with(): array
    {
        $user = auth()->user();

        // A job is ready to invoice once its report has been Released and
        // nothing's been invoiced against it yet. This is the actual gate,
        // not a status field on the job itself.
        $readyToInvoice = WorkOrder::with('customer')
            ->whereHas('documents', fn ($q) => $q->where('type', 'rep')->where('status', 'Released'))
            ->whereDoesntHave('invoices')
            ->latest()
            ->get();

        $query = Invoice::with('customer')->latest();

        if ($user->hasRole('Customer')) {
            $query->where('customer_id', $user->customer_id);
        }

        return [
            'readyToInvoice' => $user->hasRole('Finance') ? $readyToInvoice : collect(),
            'invoices' => $query->get(),
        ];
    }
};
?>

<div>
    <div class="mb-6">
        <h1 class="text-xl font-semibold text-gray-900">Invoices</h1>
        <p class="text-sm text-gray-500">{{ $invoices->count() }} total</p>
    </div>

    @if ($readyToInvoice->isNotEmpty())
        <div class="mb-6">
            <h2 class="mb-3 text-sm font-medium text-gray-900">Ready to invoice</h2>
            <div class="space-y-3">
                @foreach ($readyToInvoice as $job)
                    <a href="/invoices/create/{{ $job->id }}" wire:navigate
                       class="block rounded-xl border border-primary-200 bg-primary-50 p-4 hover:border-primary-300">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-900">{{ $job->reference }}</p>
                                <p class="text-sm text-gray-600">{{ $job->customer->name }}</p>
                            </div>
                            <span class="text-xs font-medium text-primary-700">Raise invoice &rarr;</span>
                        </div>
                    </a>
                @endforeach
            </div>
        </div>
    @endif

    <div class="space-y-3">
        @forelse ($invoices as $invoice)
            <a href="/invoices/{{ $invoice->id }}" wire:navigate class="block rounded-xl border border-gray-200 bg-white p-4 hover:border-gray-300">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-gray-900">{{ $invoice->reference }}</p>
                        <p class="truncate text-sm text-gray-600">{{ $invoice->customer->name }}</p>
                        <p class="mt-1 text-xs text-gray-500">Due {{ $invoice->due_at->format('d M Y') }}</p>
                    </div>
                    <div class="text-right">
                        <p class="text-sm font-medium text-gray-900">KES {{ number_format($invoice->amount_minor / 100, 2) }}</p>
                        <span @class([
                            'mt-1 inline-block rounded-full px-2.5 py-1 text-xs font-medium',
                            'bg-gray-100 text-gray-600' => $invoice->status === 'Draft',
                            'bg-amber-50 text-amber-700' => $invoice->status === 'Unpaid',
                            'bg-blue-50 text-blue-700' => $invoice->status === 'Part paid',
                            'bg-green-50 text-green-700' => $invoice->status === 'Paid',
                            'bg-red-50 text-red-700' => $invoice->status === 'Overdue',
                        ])>
                            {{ $invoice->status }}
                        </span>
                    </div>
                </div>
            </a>
        @empty
            <div class="rounded-xl border border-dashed border-gray-300 p-8 text-center text-sm text-gray-500">
                No invoices yet.
            </div>
        @endforelse
    </div>
</div>
