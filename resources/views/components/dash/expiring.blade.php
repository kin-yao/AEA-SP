@props(['kind', 'items', 'limit' => 4, 'empty' => 'Nothing needs attention.'])
@php
    $noun = match ($kind) { 'contract' => 'contract', 'cert' => 'certificate', default => 'document' };
    $stages = $items->groupBy(fn ($x) => $x->expiryStage())->map->count();
    $chips = [
        'critical' => ['#b91c1c', 'overdue'],
        'urgent' => ['#c2410c', 'urgent'],
        'warn' => ['#a16207', 'soon'],
    ];
@endphp
@once
    <style>
        details.xp > summary { list-style: none; }
        details.xp > summary::-webkit-details-marker { display: none; }
        details.xp[open] .xp-chev { transform: rotate(180deg); }
    </style>
@endonce
@if ($items->isEmpty())
    <p class="py-2 text-sm text-neutral-400">{{ $empty }}</p>
@else
    <details class="xp">
        <summary style="display: flex; flex-wrap: wrap; align-items: center; gap: 0.5rem 0.9rem; cursor: pointer; padding: 0.35rem 0">
            <span class="text-sm font-semibold text-neutral-900">{{ $items->count() }} {{ $noun }}{{ $items->count() === 1 ? '' : 's' }} to review</span>
            @foreach ($chips as $stage => [$color, $label])
                @if (($stages[$stage] ?? 0) > 0)
                    <span class="text-xs text-neutral-600" style="display: inline-flex; align-items: center; gap: 0.3rem">
                        <span style="width: 0.5rem; height: 0.5rem; border-radius: 9999px; background: {{ $color }}"></span>{{ $stages[$stage] }} {{ $label }}
                    </span>
                @endif
            @endforeach
            <span class="text-xs font-medium text-neutral-500" style="margin-left: auto; display: inline-flex; align-items: center; gap: 0.25rem">
                Details
                <svg class="xp-chev" width="14" height="14" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="transition: transform .15s"><path d="M5 8l5 5 5-5"/></svg>
            </span>
        </summary>
        <div class="divide-y divide-neutral-100" style="margin-top: 0.4rem">
            @foreach ($items->take($limit) as $x)
                @if ($kind === 'contract')
                    <x-expiry-ring :percent="$x->percentOfTermUsedCapped()" :stage="$x->expiryStage()"
                                   :title="$x->reference.' · '.$x->customer->name" :expiresAt="$x->ends_at->format('d M Y')" />
                @elseif ($kind === 'cert')
                    <x-expiry-ring :percent="$x->percentUsedCapped()" :stage="$x->expiryStage()"
                                   :title="$x->technician->name.' · '.$x->document_type" :expiresAt="$x->expiresAt()->format('d M Y')" />
                @else
                    <x-expiry-ring :percent="$x->percentUsedCapped()" :stage="$x->expiryStage()"
                                   :title="$x->document_type" :expiresAt="$x->expiresAt()->format('d M Y')" />
                @endif
            @endforeach
        </div>
        @if ($items->count() > $limit)
            <p class="mt-2 text-xs text-neutral-400">+{{ $items->count() - $limit }} more</p>
        @endif
    </details>
@endif
