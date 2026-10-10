<form wire:submit="save" class="mt-2 p-3" style="border-radius: 0.75rem; background: #fff; border: 1px solid var(--color-neutral-200, #e4e4e7)" novalidate>
    @if ($needsNumber)
        <div class="mb-3">
            <label class="label">{{ $kind === \App\Models\Document::TYPE_CERTIFICATE ? 'Certificate number' : 'Number on the paper' }}</label>
            <input wire:model="number" type="text" maxlength="40" class="input" autocomplete="off">
            @error('number') <p class="field-error">{{ $message }}</p> @enderror
        </div>
    @endif

    @if ($needsTitle)
        <div class="mb-3">
            <label class="label">What is this document?</label>
            <input wire:model="title" type="text" maxlength="120" class="input" placeholder="e.g. Site access letter" autocomplete="off">
            @error('title') <p class="field-error">{{ $message }}</p> @enderror
        </div>
    @endif

    @if ($kind === \App\Models\Document::TYPE_CERTIFICATE)
        <div class="mb-3">
            <label class="label">Machine it was issued for</label>
            <select wire:model="equipmentId" class="input">
                <option value="">Choose the machine</option>
                @foreach ($machines as $m)
                    <option value="{{ $m->id }}">{{ $m->model }} ({{ $m->serial_number }})</option>
                @endforeach
            </select>
            @error('equipmentId') <p class="field-error">{{ $message }}</p> @enderror
        </div>
        <div class="mb-3 grid grid-cols-2 gap-3">
            <div>
                <label class="label">Issued on</label>
                <input wire:model="issuedAt" type="date" class="input">
                @error('issuedAt') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="label">Expires on</label>
                <input wire:model="expiresAt" type="date" class="input">
                @error('expiresAt') <p class="field-error">{{ $message }}</p> @enderror
            </div>
        </div>
        <div class="mb-3 flex flex-wrap items-center gap-2" style="margin-top: -0.25rem">
            <span class="text-xs text-neutral-500">Valid for:</span>
            @foreach ([3 => '3 months', 6 => '6 months', 12 => '1 year', 24 => '2 years', 36 => '3 years'] as $m => $lbl)
                <button type="button" wire:click="validFor({{ $m }})" class="btn-outline" style="padding: 0.2rem 0.6rem; font-size: 0.75rem">{{ $lbl }}</button>
            @endforeach
        </div>
    @endif

    <div class="mb-3">
        <label class="label">File (PDF or photo, up to 10 MB)</label>
        <input wire:model="file" type="file" accept=".pdf,.jpg,.jpeg,.png,image/*" class="input">
        <p wire:loading wire:target="file" class="mt-1 text-xs" style="color: var(--color-info-700)">Uploading...</p>
        @error('file') <p class="field-error">{{ $message }}</p> @enderror
    </div>

    <div class="flex gap-2">
        <button type="submit" class="btn-primary" wire:loading.attr="disabled" wire:target="save,file">Attach</button>
        <button type="button" wire:click="cancel" class="btn-outline">Cancel</button>
    </div>
</form>
