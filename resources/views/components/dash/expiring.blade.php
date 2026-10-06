@props(['kind', 'items', 'limit' => 4, 'empty' => 'Nothing needs attention.'])
<div class="divide-y divide-neutral-100">
    @forelse ($items->take($limit) as $x)
        @if ($kind === 'contract')
            <x-expiry-ring :percent="$x->percentOfTermUsedCapped()" :stage="$x->expiryStage()"
                           :title="$x->reference.' · '.$x->customer->name" :expiresAt="$x->ends_at->format('d M Y')" />
        @elseif ($kind === 'cert')
            <x-expiry-ring :percent="$x->percentUsedCapped()" :stage="$x->expiryStage()"
                           :title="$x->technician->name.' · '.$x->document_type" :expiresAt="$x->expiresAt()->format('d M Y')" />
        @else
            <x-expiry-ring :percent="$x->percentUsedCapped()" :stage="$x->expiryStage()"
                           :title="$x->document_type" :expiresAt="$x->expiresAt()->format('d M Y')" />
        @endif
    @empty
        <p class="py-3 text-sm text-neutral-400">{{ $empty }}</p>
    @endforelse
</div>
@if ($items->count() > $limit)
    <p class="mt-2 text-xs text-neutral-400">+{{ $items->count() - $limit }} more</p>
@endif
