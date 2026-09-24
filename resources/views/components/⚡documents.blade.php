<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Models\Document;

new #[Layout('layouts.app', ['title' => 'Documents'])] class extends Component
{
    public function mount(): void
    {
        $this->authorize('viewAny', Document::class);
    }

    public function with(): array
    {
        $user = auth()->user();
        $role = $user->roles->first()?->name;

        $query = Document::with(['customer', 'workOrder'])
            ->whereIn('type', Document::scopeForRole($role ?? ''))
            ->latest();

        if ($user->hasRole('Technician')) {
            $query->whereHas('workOrder', fn ($q) => $q->where('assigned_technician_id', $user->id));
        } elseif ($user->hasRole('Customer')) {
            $query->where('customer_id', $user->customer_id);
        }

        return [
            'documents' => $query->get(),
        ];
    }
};
?>

<div>
    <div class="mb-6">
        <h1 class="text-xl font-semibold text-gray-900">Documents</h1>
        <p class="text-sm text-gray-500">{{ $documents->count() }} total</p>
    </div>

    <div class="space-y-3">
        @forelse ($documents as $document)
            <a href="/documents/{{ $document->id }}" wire:navigate class="block border border-gray-200 bg-white p-4 hover:border-gray-300">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-gray-900">{{ $document->reference }}</p>
                        <p class="truncate text-sm text-gray-600">{{ $document->customer->name }}</p>
                        <p class="mt-1 text-xs text-gray-400">{{ ucfirst($document->type === 'rep' ? 'Service report' : $document->type) }}</p>
                    </div>
                    <span @class([
                        'shrink-0 px-2.5 py-1 text-xs font-medium',
                        'bg-info-50 text-info-700' => in_array($document->status, ['Awaiting review', 'Checked, ready to post']),
                        'bg-success-50 text-success-700' => $document->status === 'Released',
                        'bg-gray-100 text-gray-600' => ! in_array($document->status, ['Awaiting review', 'Checked, ready to post', 'Released']),
                    ])>
                        {{ $document->status }}
                    </span>
                </div>
            </a>
        @empty
            <div class="border border-dashed border-gray-300 p-8 text-center text-sm text-gray-500">
                No documents yet.
            </div>
        @endforelse
    </div>
</div>
