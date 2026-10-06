@props(['title', 'href' => null, 'meta' => null, 'value' => null, 'pill' => null, 'pillClass' => 'pill-neutral', 'valueClass' => 'text-neutral-700'])
@php $tag = $href ? 'a' : 'div'; @endphp
<{{ $tag }} @if ($href) href="{{ $href }}" wire:navigate @endif class="flex items-center justify-between gap-3 py-2.5 text-sm" style="text-decoration: none; min-width: 0">
    <span style="min-width: 0">
        <span class="block truncate font-medium text-neutral-900">{{ $title }}</span>
        @if ($meta)
            <span class="block truncate text-xs text-neutral-400">{{ $meta }}</span>
        @endif
    </span>
    <span class="flex shrink-0 items-center gap-3">
        @if ($value)
            <span class="font-mono text-xs font-semibold {{ $valueClass }}">{{ $value }}</span>
        @endif
        @if ($pill)
            <span class="{{ $pillClass }}">{{ $pill }}</span>
        @endif
    </span>
</{{ $tag }}>
