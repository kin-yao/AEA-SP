@props(['shown', 'total'])

@if ($total > $shown)
    <div class="mt-4 text-center">
        <p class="mb-2 text-xs text-neutral-500">Showing {{ number_format($shown) }} of {{ number_format($total) }}</p>
        <button type="button" wire:click="showMore" wire:loading.attr="disabled" wire:target="showMore" class="btn-outline">
            <span wire:loading.remove wire:target="showMore">Show more</span>
            <span wire:loading wire:target="showMore">Loading...</span>
        </button>
    </div>
@endif
