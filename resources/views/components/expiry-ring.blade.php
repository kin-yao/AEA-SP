{{--
    Shared indicator for anything time-limited, contracts and technician
    compliance documents alike. Four stages, exact colors: fresh (under
    50% elapsed), warn (~50%), urgent (~75%), critical (100%/expired).
    A small progress ring reads faster than a flat colored pill for
    something someone needs to notice at a glance, not just recognize.
--}}
@props(['percent', 'stage', 'title', 'subtitle'])

@php
    $displayPercent = min($percent, 100);
    $ring = match ($stage) {
        'fresh' => 'stroke-fresh-500',
        'warn' => 'stroke-warn-500',
        'urgent' => 'stroke-urgent-500',
        'critical' => 'stroke-critical-500',
    };
    $text = match ($stage) {
        'fresh' => 'text-fresh-700',
        'warn' => 'text-warn-800',
        'urgent' => 'text-urgent-700',
        'critical' => 'text-critical-700',
    };
    $circumference = 2 * M_PI * 15;
    $dasharray = round($displayPercent / 100 * $circumference, 1).' '.round($circumference, 1);
@endphp

<div class="flex items-center gap-3">
    <svg viewBox="0 0 36 36" class="h-9 w-9 shrink-0 -rotate-90">
        <circle cx="18" cy="18" r="15" fill="none" stroke-width="4" class="stroke-gray-100" />
        <circle cx="18" cy="18" r="15" fill="none" stroke-width="4" stroke-linecap="butt"
                class="{{ $ring }}" stroke-dasharray="{{ $dasharray }}" />
    </svg>
    <div class="min-w-0 flex-1">
        <p class="truncate text-sm font-medium text-gray-900">{{ $title }}</p>
        <p class="text-xs {{ $text }} font-semibold">{{ $subtitle }}</p>
    </div>
</div>
