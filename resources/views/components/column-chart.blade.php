@props(['data', 'height' => 170, 'minCol' => 50])
@php
    // data: [['label' => 'Nairobi', 'value' => 4, 'valueLabel' => '4', 'color' => '#8f1d1d', 'marker' => 12 (optional target tick)], ...]
    $rows = collect($data)->values();
    $max = max(1, (int) $rows->max(fn ($r) => max($r['value'], $r['marker'] ?? 0)));
    $plot = $height - 22;
    $empty = $rows->isEmpty() || $rows->sum('value') <= 0;
@endphp
@if ($empty)
    <div style="display: flex; align-items: center; justify-content: center; height: {{ $height }}px; border-radius: 0.5rem; background: #f4f4f5; color: #71717a; font-size: 0.8rem">No data for these filters</div>
@else
    <div style="overflow-x: auto; padding-bottom: 2px">
        <div style="position: relative; min-width: {{ $rows->count() * ($minCol + 8) }}px">
            <div style="position: relative; display: flex; align-items: flex-end; gap: 0.45rem; height: {{ $height }}px">
                {{-- faint guide lines --}}
                @foreach ([0, 1, 2, 3] as $g)
                    <div style="position: absolute; left: 0; right: 0; bottom: {{ round($g * $plot / 3) }}px; border-top: 1px solid {{ $g === 0 ? '#d4d4d8' : '#f0f0f2' }}"></div>
                @endforeach
                @foreach ($rows as $r)
                    @php
                        $bar = $r['value'] > 0 ? max(3, round($r['value'] / $max * $plot)) : 0;
                        $mark = isset($r['marker']) ? round($r['marker'] / $max * $plot) : null;
                    @endphp
                    <div title="{{ $r['label'] }}: {{ $r['valueLabel'] ?? $r['value'] }}{{ isset($r['marker']) ? ' (reorder at '.$r['marker'].')' : '' }}"
                         style="position: relative; flex: 1 1 0; min-width: {{ $minCol }}px; height: 100%; display: flex; flex-direction: column; align-items: center; justify-content: flex-end">
                        <span style="font-size: 0.75rem; font-weight: 700; color: #18181b; font-variant-numeric: tabular-nums; margin-bottom: 3px; white-space: nowrap">{{ $r['valueLabel'] ?? $r['value'] }}</span>
                        <div style="width: min(2.75rem, 70%); height: {{ $bar }}px; border-radius: 4px 4px 0 0; background: {{ $r['color'] ?? '#8f1d1d' }}"></div>
                        @if ($mark !== null)
                            <div style="position: absolute; left: 12%; right: 12%; bottom: {{ $mark }}px; border-top: 2px dashed #18181b"></div>
                        @endif
                    </div>
                @endforeach
            </div>

            <div style="display: flex; gap: 0.45rem; margin-top: 6px">
                @foreach ($rows as $r)
                    <div style="flex: 1 1 0; min-width: {{ $minCol }}px; text-align: center; font-size: 0.7rem; line-height: 1.2; color: #52525b; overflow-wrap: break-word">{{ $r['label'] }}</div>
                @endforeach
            </div>
        </div>
    </div>
@endif
