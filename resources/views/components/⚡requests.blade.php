<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Models\ServiceRequest;

new #[Layout('layouts.app', ['title' => 'Requests'])] class extends Component
{
    public function mount(): void
    {
        $this->authorize('viewAny', ServiceRequest::class);
    }

    public function with(): array
    {
        $user = auth()->user();

        $query = ServiceRequest::with(['customer', 'technician'])->latest();

        if ($user->hasRole('Customer')) {
            $query->where('customer_id', $user->customer_id);
        }

        return [
            'requests' => $query->get(),
        ];
    }
};
?>

<div>
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold text-gray-900">Requests</h1>
            <p class="text-sm text-gray-500">{{ $requests->count() }} total</p>
        </div>
    </div>

    <div class="space-y-3">
        @forelse ($requests as $request)
            <a href="/requests/{{ $request->id }}" wire:navigate class="block border border-gray-200 bg-white p-4 hover:border-gray-300">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-gray-900">{{ $request->reference }}</p>
                        <p class="truncate text-sm text-gray-600">{{ $request->customer->name }}</p>
                        <p class="mt-1 truncate text-xs text-gray-500">{{ $request->fault_description }}</p>
                        @if ($request->technician)
                            <p class="mt-1 text-xs text-gray-400">Assigned to {{ $request->technician->name }}</p>
                        @endif
                    </div>
                    <span @class([
                        'shrink-0 px-2.5 py-1 text-xs font-medium',
                        'bg-gray-100 text-gray-600' => $request->status === 'Open',
                        'bg-info-50 text-info-700' => in_array($request->status, ['Assigned', 'Quoted']),
                        'bg-success-50 text-success-700' => $request->status === 'Converted',
                        'bg-primary-50 text-primary-700' => $request->status === 'Declined',
                    ])>
                        {{ $request->status }}
                    </span>
                </div>
            </a>
        @empty
            <div class="border border-dashed border-gray-300 p-8 text-center text-sm text-gray-500">
                No requests yet.
            </div>
        @endforelse
    </div>
</div>
