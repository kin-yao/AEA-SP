<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Models\Document;

new #[Layout('layouts.app', ['title' => 'Documents'])] class extends Component
{
    use \App\Support\ShowsMore;

    public string $typeFilter = 'All';

    public array $typeLabels = [
        Document::TYPE_REPORT => 'Service report',
        Document::TYPE_CERTIFICATE => 'Calibration certificate',
        Document::TYPE_LPO => 'LPO',
        Document::TYPE_VOUCHER => 'Maintenance voucher',
        Document::TYPE_DELIVERY_NOTE => 'Delivery note',
    ];

    public function mount(): void
    {
        $this->authorize('viewAny', Document::class);
    }

    public function setFilter(string $type): void
    {
        $this->typeFilter = $type;
    }

    public function with(): array
    {
        $user = auth()->user();
        $role = $user->roles->first()?->name;
        $scope = Document::scopeForRole($role ?? '');
        // LPOs have their own page under Sales.
        $scope = array_values(array_diff($scope, [Document::TYPE_LPO]));

        $query = Document::with(['customer', 'workOrder'])
            ->whereIn('type', $scope)
            ->latest();

        if (! $user->hasRole('Technician')) {
            $query->where('status', '!=', 'Draft');
        }

        if ($user->hasRole('Technician')) {
            $query->whereHas('workOrder', fn ($q) => $q->where('assigned_technician_id', $user->id));
        } elseif ($user->hasRole('Customer')) {
            $query->where('customer_id', $user->customer_id);
        }

        if ($this->typeFilter !== 'All') {
            $query->where('type', $this->typeFilter);
        }

        $total = (clone $query)->count();

        return [
            'total' => $total,
            'documents' => $query->limit($this->limit)->get(),
            'availableTypes' => $scope,
        ];
    }
};
?>

<div>
    <div class="mb-6">
        <h1 class="text-xl font-semibold text-neutral-900">Documents</h1>
        <p class="text-sm text-neutral-500">{{ number_format($total) }} {{ $typeFilter === 'All' ? 'total' : 'matching' }}</p>
    </div>

    <div class="mb-5 flex flex-wrap gap-1 border-b border-neutral-200">
        <button
            wire:click="setFilter('All')"
            @class([
                'border-b-2 px-3 py-2 text-sm font-medium transition-colors',
                'border-primary-500 text-primary-600' => $typeFilter === 'All',
                'border-transparent text-neutral-500 hover:text-neutral-900' => $typeFilter !== 'All',
            ])
        >
            All
        </button>
        @foreach ($availableTypes as $type)
            <button
                wire:click="setFilter('{{ $type }}')"
                @class([
                    'border-b-2 px-3 py-2 text-sm font-medium transition-colors',
                    'border-primary-500 text-primary-600' => $typeFilter === $type,
                    'border-transparent text-neutral-500 hover:text-neutral-900' => $typeFilter !== $type,
                ])
            >
                {{ $this->typeLabels[$type] }}s
            </button>
        @endforeach
    </div>

    <div class="space-y-3">
        @forelse ($documents as $document)
            <a href="/documents/{{ $document->id }}" wire:navigate class="card flex items-start justify-between gap-3 hover:shadow-[var(--shadow-card-hover)]">
                <div class="min-w-0">
                    <p class="text-sm font-semibold text-neutral-900">{{ $document->reference }}</p>
                    <p class="truncate text-sm text-neutral-600">{{ $document->customer->name }}</p>
                    <p class="mt-1 text-xs text-neutral-400">{{ $this->typeLabels[$document->type] ?? ucfirst($document->type) }}</p>
                </div>
                <span @class([
                    'shrink-0',
                    'pill-info' => in_array($document->status, ['Awaiting review', 'Checked, ready to post']),
                    'pill-success' => $document->status === 'Released',
                    'pill-neutral' => ! in_array($document->status, ['Awaiting review', 'Checked, ready to post', 'Released']),
                ])>
                    {{ $document->status }}
                </span>
            </a>
        @empty
            <div class="card border-dashed text-center text-sm text-neutral-500">
                No documents match this filter.
            </div>
        @endforelse
    </div>
    <x-show-more :shown="$documents->count()" :total="$total" />
</div>
