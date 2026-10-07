@props(['labels', 'series', 'height' => 240, 'width' => 640])
@php
    // series: [['name' => 'Billed', 'color' => 'var(--color-primary-500)', 'values' => [..]], ...]
    $w = $width; $h = $height; $padL = 52; $padR = 14; $padT = 14; $padB = 30;
    $n = max(count($labels), 1);
    $rawMax = 0;
    foreach ($series as $s) { $rawMax = max($rawMax, max($s['values'] ?: [0])); }
    // Round the top of the axis up to a tidy number.
    $mag = $rawMax > 0 ? pow(10, floor(log10($rawMax))) : 1;
    $top = $rawMax > 0 ? ceil($rawMax / $mag * 2) / 2 * $mag : 1;
    $top = max($top, 1);
    $x = fn ($i) => $padL + ($n > 1 ? $i / ($n - 1) : 0.5) * ($w - $padL - $padR);
    $y = fn ($v) => $padT + (1 - $v / $top) * ($h - $padT - $padB);
    $compact = function ($v) {
        if ($v >= 1000000) return rtrim(rtrim(number_format($v / 1000000, 1), '0'), '.').'M';
        if ($v >= 1000) return rtrim(rtrim(number_format($v / 1000, 1), '0'), '.').'k';
        return (string) round($v);
    };
    $ticks = 4;
@endphp
<div>
    <div class="mb-3 flex flex-wrap gap-4">
        @foreach ($series as $s)
            <span class="inline-flex items-center gap-1.5 text-xs font-medium text-neutral-600">
                <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:{{ $s['color'] }}"></span>{{ $s['name'] }}
            </span>
        @endforeach
    </div>
    <svg viewBox="0 0 {{ $w }} {{ $h }}" style="width:100%;height:auto;display:block" role="img" aria-label="Line chart">
        @for ($t = 0; $t <= $ticks; $t++)
            @php $gv = $top * $t / $ticks; @endphp
            <line x1="{{ $padL }}" x2="{{ $w - $padR }}" y1="{{ $y($gv) }}" y2="{{ $y($gv) }}"
                  stroke="var(--color-neutral-200)" stroke-width="1" @if ($t > 0) stroke-dasharray="4 4" @endif />
            <text x="{{ $padL - 8 }}" y="{{ $y($gv) + 4 }}" text-anchor="end" font-size="11" fill="var(--color-neutral-500)">{{ $compact($gv) }}</text>
        @endfor

        @foreach ($labels as $i => $label)
            <text x="{{ $x($i) }}" y="{{ $h - 8 }}" text-anchor="middle" font-size="11" fill="var(--color-neutral-500)">{{ $label }}</text>
        @endforeach

        @foreach ($series as $si => $s)
            @php
                $pts = collect($s['values'])->map(fn ($v, $i) => round($x($i), 1).','.round($y($v), 1))->implode(' ');
                $baseY = $y(0);
                $area = 'M'.round($x(0), 1).','.round($baseY, 1).' L'.collect($s['values'])->map(fn ($v, $i) => round($x($i), 1).','.round($y($v), 1))->implode(' L').' L'.round($x(count($s['values']) - 1), 1).','.round($baseY, 1).' Z';
            @endphp
            @if ($si === 0)
                <path d="{{ $area }}" fill="{{ $s['color'] }}" fill-opacity="0.10" />
            @endif
            <polyline points="{{ $pts }}" fill="none" stroke="{{ $s['color'] }}" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" />
            @foreach ($s['values'] as $i => $v)
                <circle cx="{{ round($x($i), 1) }}" cy="{{ round($y($v), 1) }}" r="3.5" fill="white" stroke="{{ $s['color'] }}" stroke-width="2">
                    <title>{{ $s['name'] }}, {{ $labels[$i] ?? '' }}: {{ currency() }} {{ number_format($v) }}</title>
                </circle>
            @endforeach
        @endforeach
    </svg>
</div>
