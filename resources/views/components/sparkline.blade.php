@props(['values', 'color' => 'var(--color-success-500)'])
@php
    $max = max(max($values), 1);
    $min = min($values);
    $range = max($max - $min, 1);
    $w = 100; $h = 28; $n = count($values);
    $points = collect($values)->map(function ($v, $i) use ($w, $h, $n, $min, $range) {
        $x = $n > 1 ? $i / ($n - 1) * $w : 0;
        $y = $h - (($v - $min) / $range * $h);
        return round($x, 1).','.round($y, 1);
    })->implode(' ');
@endphp
<svg viewBox="0 0 100 28" preserveAspectRatio="none" class="h-7 w-full">
    <polyline points="{{ $points }}" fill="none" stroke="{{ $color }}" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" />
</svg>
