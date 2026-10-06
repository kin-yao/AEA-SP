@props(['title', 'href' => null, 'link' => 'View all'])
<div class="flex items-center gap-3" style="margin: 1.75rem 0 0.75rem">
    <h2 class="text-base font-semibold text-neutral-900">{{ $title }}</h2>
    <div class="flex-1 border-t border-neutral-200"></div>
    @if ($href)
        <a href="{{ $href }}" wire:navigate class="text-xs font-semibold text-neutral-500 hover:text-neutral-900" style="text-decoration: none">{{ $link }} &rarr;</a>
    @endif
</div>
