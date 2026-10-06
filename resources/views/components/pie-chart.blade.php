@props(['data', 'size' => 150])
@php
    // data: [['label' => 'Assigned', 'value' => 2, 'color' => '#1f2937'], ...]
    $total = max(array_sum(array_column($data, 'value')), 0);
    $r = 50;
    $cx = 50;
    $cy = 50;
    $angle = -M_PI / 2;
@endphp
<div style="display: flex; flex-direction: column; align-items: center; gap: 1rem">
    @if ($total === 0)
        <div class="flex items-center justify-center rounded-full bg-neutral-100 text-xs text-neutral-400" style="width: {{ $size }}px; height: {{ $size }}px">No data</div>
    @else
        <svg viewBox="0 0 100 100" style="width: {{ $size }}px; height: {{ $size }}px" role="img" aria-label="Pie chart">
            @if (count($data) === 1)
                <circle cx="{{ $cx }}" cy="{{ $cy }}" r="{{ $r }}" fill="{{ $data[0]['color'] }}"><title>{{ $data[0]['label'] }}: {{ $data[0]['value'] }}</title></circle>
            @else
                @foreach ($data as $slice)
                    @php
                        $sweep = $slice['value'] / $total * 2 * M_PI;
                        $x1 = $cx + $r * cos($angle);
                        $y1 = $cy + $r * sin($angle);
                        $angle += $sweep;
                        $x2 = $cx + $r * cos($angle);
                        $y2 = $cy + $r * sin($angle);
                        $large = $sweep > M_PI ? 1 : 0;
                    @endphp
                    <path d="M{{ $cx }},{{ $cy }} L{{ round($x1, 3) }},{{ round($y1, 3) }} A{{ $r }},{{ $r }} 0 {{ $large }} 1 {{ round($x2, 3) }},{{ round($y2, 3) }} Z"
                          fill="{{ $slice['color'] }}" stroke="#fff" stroke-width="0.8">
                        <title>{{ $slice['label'] }}: {{ $slice['value'] }}</title>
                    </path>
                @endforeach
            @endif
        </svg>

        <ul style="width: 100%; margin: 0; padding: 0; list-style: none">
            @foreach ($data as $slice)
                <li style="display: flex; align-items: center; gap: 0.6rem; padding: 0.3rem 0; font-size: 0.8125rem; color: #3f3f46">
                    <span style="width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; background: {{ $slice['color'] }}"></span>
                    <span style="flex: 1">{{ $slice['label'] }}</span>
                    <span style="font-weight: 700; color: #18181b; font-variant-numeric: tabular-nums">{{ $slice['valueLabel'] ?? $slice['value'] }}</span>
                    <span style="width: 2.75rem; text-align: right; font-size: 0.75rem; color: #71717a">{{ round($slice['value'] / $total * 100) }}%</span>
                </li>
            @endforeach
        </ul>
    @endif
</div>
