@props(['label', 'value', 'sub' => null, 'tone' => null, 'href' => null])
@php
    // Solid colour, white text. good = healthy or money in, warn = waiting on
    // someone, bad = late, info = just a count. A warn or bad card that reads
    // zero turns good.
    $isZero = in_array((string) $value, ['0', '0.0'], true);
    $shown = in_array($tone, ['warn', 'bad'], true) && $isZero ? 'good' : $tone;

    $bg = match ($shown) {
        'bad' => '#e31e24',
        'warn' => '#b7791f',
        'good' => '#15803d',
        'info' => '#475569',
        default => '#1f2937',
    };
    $tag = $href ? 'a' : 'div';
@endphp
<{{ $tag }} @if ($href) href="{{ $href }}" wire:navigate @endif
    style="min-width: 0; display: block; text-align: center; text-decoration: none; padding: 1rem 0.5rem; border-radius: 0.75rem; background: {{ $bg }}; color: #fff">
    <p style="font-size: 0.8125rem; font-weight: 600; color: rgba(255, 255, 255, 0.92)">{{ $label }}</p>
    <p class="font-mono font-bold whitespace-nowrap" style="margin-top: 0.35rem; font-size: clamp(1.35rem, 2.2vw, 1.75rem); line-height: 1.1; color: #fff">{{ $value }}</p>
</{{ $tag }}>
