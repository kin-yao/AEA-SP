<div class="flex flex-wrap items-center justify-between gap-2 py-1.5" wire:key="doc-{{ $doc->id }}">
    <div style="min-width: 0">
        <a href="/documents/{{ $doc->id }}" wire:navigate class="text-sm font-medium text-neutral-900 hover:underline">
            @if ($doc->certificateDetail)
                {{ $doc->reference }}
            @elseif ($doc->title)
                {{ $doc->title }}
            @else
                {{ $single }} {{ $doc->reference }}
            @endif
        </a>
        <p class="text-xs text-neutral-500">
            @if ($doc->certificateDetail)
                {{ $doc->certificateDetail->equipment?->model ? $doc->certificateDetail->equipment->model.' ('.$doc->certificateDetail->equipment->serial_number.')' : 'No machine chosen' }}
                &middot; issued {{ $doc->certificateDetail->issued_at?->format('d M Y') }}
                &middot; expires {{ $doc->certificateDetail->expires_at?->format('d M Y') }}
            @else
                Added {{ $doc->created_at->format('d M Y') }} by {{ $doc->filedBy?->name ?? '-' }}
            @endif
        </p>
    </div>
    <div class="flex items-center gap-3">
        @if ($doc->file_path)
            <a href="{{ Storage::url($doc->file_path) }}" target="_blank" rel="noopener" class="text-xs font-medium text-primary-700 hover:underline">Open file</a>
        @else
            <span class="text-xs text-neutral-400">No file yet</span>
        @endif
        @if ($doc->file_path && ($doc->filed_by === auth()->id() || auth()->user()->hasRole('Service Admin')) && auth()->user()->can('attach', [$job, $kind]))
            <button type="button" wire:click="remove({{ $doc->id }})" wire:confirm="Remove this file from the job?" class="text-xs text-neutral-400 hover:text-critical-700">Remove</button>
        @endif
    </div>
</div>
