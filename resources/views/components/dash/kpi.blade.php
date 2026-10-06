@props(['label', 'value', 'sub' => null, 'tone' => null, 'href' => null])
@php
    $color = match ($tone) {
        'bad' => 'text-critical-700',
        'warn' => 'text-amber-700',
        default => 'text-neutral-900',
    };
    $tag = $href ? 'a' : 'div';
@endphp
<{{ $tag }} @if ($href) href="{{ $href }}" wire:navigate @endif class="card" style="min-width: 0; display: block; text-decoration: none">
    <p class="text-xs font-medium text-neutral-500">{{ $label }}</p>
    <p class="mt-2 font-mono text-xl font-bold whitespace-nowrap {{ $color }}">{{ $value }}</p>
    @if ($sub)
        <p class="mt-0.5 text-xs text-neutral-400">{{ $sub }}</p>
    @endif
</{{ $tag }}>
