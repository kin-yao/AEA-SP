<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Models\Quotation;

new #[Layout('layouts.app', ['title' => 'Quotations'])] class extends Component
{
    public function mount(): void
    {
        $this->authorize('viewAny', Quotation::class);
    }

    public function with(): array
    {
        $user = auth()->user();

        $query = Quotation::with('customer')->latest();

        if ($user->hasRole('Customer')) {
            $query->where('customer_id', $user->customer_id);
        }

        return [
            'quotations' => $query->get(),
        ];
    }
};
?>

<div>
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold text-gray-900">Quotations</h1>
            <p class="text-sm text-gray-500">{{ $quotations->count() }} total</p>
        </div>
        @can('create', \App\Models\Quotation::class)
            <a href="/quotations/create" wire:navigate
               class="bg-primary-500 px-4 py-2 text-sm font-medium text-white hover:bg-primary-600">
                New quotation
            </a>
        @endcan
    </div>

    <div class="space-y-3">
        @forelse ($quotations as $quotation)
            <a href="/quotations/{{ $quotation->id }}" wire:navigate class="block border border-gray-200 bg-white p-4 hover:border-gray-300">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-gray-900">{{ $quotation->reference }}</p>
                        <p class="truncate text-sm text-gray-600">{{ $quotation->customer->name }}</p>
                        <p class="mt-1 truncate text-xs text-gray-500">{{ $quotation->scope }}</p>
                    </div>
                    <div class="shrink-0 text-right">
                        <p class="text-sm font-medium text-gray-900">KES {{ number_format($quotation->totalMinor() / 100, 2) }}</p>
                        <span @class([
                            'mt-1 inline-block px-2.5 py-1 text-xs font-medium',
                            'bg-gray-100 text-gray-600' => str_starts_with($quotation->status, 'Awaiting'),
                            'bg-info-50 text-info-700' => in_array($quotation->status, ['Approved', 'Accepted']),
                            'bg-success-50 text-success-700' => $quotation->status === 'Converted',
                            'bg-primary-50 text-primary-700' => $quotation->status === 'Sent back',
                        ])>
                            {{ $quotation->status }}
                        </span>
                    </div>
                </div>
            </a>
        @empty
            <div class="border border-dashed border-gray-300 p-8 text-center text-sm text-gray-500">
                No quotations yet.
            </div>
        @endforelse
    </div>
</div>
