@props(['percent', 'stage', 'title', 'expiresAt'])
@php
    $displayPercent = min($percent, 100);
    $ring = match ($stage) {
        'fresh' => 'stroke-fresh-500', 'warn' => 'stroke-warn-500',
        'urgent' => 'stroke-urgent-500', 'critical' => 'stroke-critical-500',
    };
    $text = match ($stage) {
        'fresh' => 'text-fresh-700', 'warn' => 'text-warn-800',
        'urgent' => 'text-urgent-700', 'critical' => 'text-critical-700',
    };
    $dot = match ($stage) {
        'fresh' => 'bg-fresh-500', 'warn' => 'bg-warn-500',
        'urgent' => 'bg-urgent-500', 'critical' => 'bg-critical-500',
    };
    $circumference = 2 * M_PI * 15;
    $dasharray = round($displayPercent / 100 * $circumference, 1).' '.round($circumference, 1);
@endphp
<div class="flex items-center gap-3 rounded-[var(--radius-md)] px-2 py-2 hover:bg-neutral-50">
    <svg viewBox="0 0 36 36" class="h-12 w-12 shrink-0 -rotate-90">
        <circle cx="18" cy="18" r="15" fill="none" stroke-width="4" class="stroke-neutral-100" />
        <circle cx="18" cy="18" r="15" fill="none" stroke-width="4" stroke-linecap="round"
                class="{{ $ring }}" stroke-dasharray="{{ $dasharray }}" />
        <text x="18" y="18" text-anchor="middle" dominant-baseline="central" transform="rotate(90 18 18)"
              class="fill-neutral-900" style="font-size: 9px; font-weight: 700">{{ $displayPercent }}%</text>
    </svg>
    <div class="min-w-0 flex-1">
        <p class="flex items-center gap-1.5 text-sm font-semibold {{ $text }}">
            <span class="h-1.5 w-1.5 shrink-0 rounded-full {{ $dot }}"></span>
            Expires {{ $expiresAt }}
        </p>
        <p class="truncate text-xs text-neutral-500">{{ $title }}</p>
    </div>
</div>
