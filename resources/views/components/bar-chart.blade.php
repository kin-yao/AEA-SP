@props(['data', 'height' => 120])
@php
    $max = max($data->max('value'), 1);
    $palette = ['var(--color-info-500)', 'var(--color-success-500)', 'var(--color-primary-500)', 'var(--color-amber-500)', 'var(--color-neutral-800)'];
@endphp
<div class="flex items-end gap-4" style="height: {{ $height + 44 }}px">
    @foreach ($data as $i => $row)
        <div class="flex flex-1 flex-col items-center gap-2">
            <span class="text-xs font-semibold text-neutral-700">{{ $row['valueLabel'] }}</span>
            <div class="w-full rounded-t-[var(--radius-sm)]"
                 style="height: {{ max(4, $row['value'] / $max * $height) }}px; background-color: {{ $palette[$i % count($palette)] }}"></div>
            <span class="text-xs text-neutral-500">{{ $row['label'] }}</span>
        </div>
    @endforeach
</div>
