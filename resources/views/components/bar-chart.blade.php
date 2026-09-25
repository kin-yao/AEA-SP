{{--
    A real vertical column chart, SVG, sharp-edged bars (consistent with
    the rest of the app, not the rounded tops in the reference image),
    each bar its own color from a fixed palette, a value label above and
    a name label below, and a baseline. Reused for anything comparing a
    handful of people or categories, not just technicians.
--}}
@props(['data', 'height' => 160])

@php
    $max = $data->max('value') ?: 1;
    $barWidth = 44;
    $gap = 28;
    $labelSpace = 32;
    $valueSpace = 22;
    $plotHeight = $height;
    $width = $data->count() * ($barWidth + $gap) + $gap;
    $palette = ['#1e25e3', '#25e31e', '#e31e24', '#e6ac35', '#8b5cf6', '#06b6d4'];
@endphp

<svg viewBox="0 0 {{ $width }} {{ $plotHeight + $labelSpace + $valueSpace }}" class="w-full">
    @foreach ($data as $i => $item)
        @php
            $barHeight = max(($item['value'] / $max) * $plotHeight, 2);
            $x = $i * ($barWidth + $gap) + $gap;
            $y = $valueSpace + $plotHeight - $barHeight;
            $color = $palette[$i % count($palette)];
        @endphp
        <text x="{{ $x + $barWidth / 2 }}" y="{{ $valueSpace - 6 }}" text-anchor="middle" class="fill-gray-700" style="font-size: 11px; font-weight: 600">
            {{ $item['valueLabel'] }}
        </text>
        <rect x="{{ $x }}" y="{{ $y }}" width="{{ $barWidth }}" height="{{ $barHeight }}" fill="{{ $color }}" />
        <text x="{{ $x + $barWidth / 2 }}" y="{{ $valueSpace + $plotHeight + 20 }}" text-anchor="middle" class="fill-gray-500" style="font-size: 11px">
            {{ $item['label'] }}
        </text>
    @endforeach
    <line x1="0" y1="{{ $valueSpace + $plotHeight }}" x2="{{ $width }}" y2="{{ $valueSpace + $plotHeight }}" class="stroke-gray-200" stroke-width="1" />
</svg>
