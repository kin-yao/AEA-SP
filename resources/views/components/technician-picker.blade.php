{{--
    Shared across every screen that assigns a technician, one real
    component, not copy-pasted select boxes with drifting behaviour.

    Expects $technicians already loaded with ->branch and an
    open_jobs_count from withCount(), see getTechniciansProperty() on
    the parent component. "Where they are" is genuinely just their home
    branch, this system has no live location tracking, showing anything
    else there would be pretending to know something we don't.
--}}
@props(['technicians', 'model'])

<div class="space-y-2">
    @forelse ($technicians as $technician)
        <label class="flex cursor-pointer items-center justify-between rounded-lg border border-gray-200 p-3 has-[:checked]:border-primary-500 has-[:checked]:bg-primary-50">
            <div class="flex items-center gap-3">
                <input type="radio" wire:model="{{ $model }}" value="{{ $technician->id }}" class="text-primary-500 focus:ring-primary-500">
                <div>
                    <p class="text-sm font-medium text-gray-900">{{ $technician->name }}</p>
                    <p class="text-xs text-gray-500">{{ $technician->branch->name ?? 'No branch set' }}</p>
                </div>
            </div>
            <span @class([
                'shrink-0 rounded-full px-2 py-0.5 text-xs font-medium',
                'bg-green-50 text-green-700' => $technician->open_jobs_count <= 2,
                'bg-amber-50 text-amber-700' => $technician->open_jobs_count > 2 && $technician->open_jobs_count <= 4,
                'bg-red-50 text-red-700' => $technician->open_jobs_count > 4,
            ])>
                {{ $technician->open_jobs_count }} open
            </span>
        </label>
    @empty
        <p class="text-sm text-gray-500">No technicians available.</p>
    @endforelse
</div>
