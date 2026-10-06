<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use Livewire\WithPagination;
use App\Models\AuditLog;

new #[Layout('layouts.app', ['title' => 'Audit trail'])] class extends Component
{
    use WithPagination;

    // Typed into the boxes, and what has been applied.
    public string $searchInput = '';
    public string $eventInput = '';
    public string $fromInput = '';
    public string $toInput = '';
    public string $search = '';
    public string $event = '';
    public string $from = '';
    public string $to = '';

    public ?int $open = null;

    public function mount(): void
    {
        abort_unless(auth()->user()->hasAnyRole(['ICT', 'Super Admin']), 403);
    }

    public function applyFilter(): void
    {
        $this->validate([
            'fromInput' => ['nullable', 'date'],
            'toInput' => ['nullable', 'date', 'after_or_equal:fromInput'],
        ], [
            'toInput.after_or_equal' => 'The end date cannot be before the start date.',
        ]);

        $this->search = trim($this->searchInput);
        $this->event = $this->eventInput;
        $this->from = $this->fromInput;
        $this->to = $this->toInput;
        $this->open = null;
        $this->resetPage();
    }

    public function resetFilter(): void
    {
        $this->reset(['searchInput', 'eventInput', 'fromInput', 'toInput', 'search', 'event', 'from', 'to', 'open']);
        $this->resetValidation();
        $this->resetPage();
    }

    public function toggle(int $id): void
    {
        $this->open = $this->open === $id ? null : $id;
    }

    protected function query()
    {
        $q = AuditLog::visibleTo(auth()->user())->orderByDesc('id');

        if ($this->event !== '') {
            $q->where('event', $this->event);
        }
        if ($this->search !== '') {
            $like = '%'.$this->search.'%';
            $q->where(fn ($w) => $w->where('label', 'like', $like)->orWhere('user_name', 'like', $like)->orWhere('email', 'like', $like));
        }
        if ($this->from !== '') {
            $q->where('created_at', '>=', \Carbon\Carbon::parse($this->from, 'Africa/Nairobi')->startOfDay()->setTimezone(config('app.timezone')));
        }
        if ($this->to !== '') {
            $q->where('created_at', '<=', \Carbon\Carbon::parse($this->to, 'Africa/Nairobi')->endOfDay()->setTimezone(config('app.timezone')));
        }

        return $q;
    }

    public function exportCsv()
    {
        $rows = $this->query()->limit(5000)->get();

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['When', 'Who', 'Email', 'Action', 'Details', 'From (IP)', 'Changes']);
            foreach ($rows as $r) {
                $changes = collect($r->changes ?? [])->map(fn ($v, $k) => $k.': '.($v[0] ?? '').' to '.($v[1] ?? ''))->implode('; ');
                fputcsv($out, [$r->created_at->copy()->setTimezone('Africa/Nairobi')->format('Y-m-d H:i:s'), $r->user_name, $r->email, AuditLog::EVENTS[$r->event] ?? $r->event, $r->label, $r->ip, $changes]);
            }
            fclose($out);
        }, 'aea-audit-trail-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }

    public function with(): array
    {
        return [
            'logs' => $this->query()->paginate(25),
            'events' => AuditLog::EVENTS,
        ];
    }
};
?>

<div class="mx-auto" style="max-width: 72rem">
    <div class="mb-5 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-neutral-900">Audit trail</h1>
            <p class="text-sm text-neutral-500">Who signed in, and who created, changed or deleted what. Newest first.</p>
        </div>
        <button type="button" wire:click="exportCsv" wire:loading.attr="disabled" class="btn-dark">
            <x-icon name="file-earmark-text" class="h-4 w-4" />
            Download CSV
        </button>
    </div>

    <form wire:submit="applyFilter" class="card mb-4 grid items-end gap-3" style="grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));">
        <div>
            <label class="label" for="a-q">Search</label>
            <input id="a-q" type="text" wire:model="searchInput" class="input" style="font-size: 16px" placeholder="Name, email or what changed">
        </div>
        <div>
            <label class="label" for="a-e">Action</label>
            <select id="a-e" wire:model="eventInput" class="input" style="font-size: 16px">
                <option value="">All actions</option>
                @foreach ($events as $key => $text)
                    <option value="{{ $key }}">{{ $text }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="a-f">From</label>
            <input id="a-f" type="date" wire:model="fromInput" class="input" style="font-size: 16px">
        </div>
        <div>
            <label class="label" for="a-t">To</label>
            <input id="a-t" type="date" wire:model="toInput" class="input" style="font-size: 16px">
            @error('toInput') <p class="mt-1 text-xs text-critical-700">{{ $message }}</p> @enderror
        </div>
        <div class="flex gap-2">
            <button type="submit" class="btn-primary">Apply filter</button>
            <button type="button" wire:click="resetFilter" class="btn-outline">Reset</button>
        </div>
    </form>

    <div class="card" style="padding: 0">
        <div class="overflow-x-auto">
            <table class="table-clean" style="min-width: 720px">
                <thead><tr><th>When</th><th>Who</th><th>Action</th><th>Details</th><th></th></tr></thead>
                <tbody>
                    @forelse ($logs as $l)
                        @php
                            $pill = match ($l->event) {
                                'login', 'created', 'account_unlocked' => 'pill-success',
                                'login_failed', 'login_blocked', 'deleted', 'account_locked' => 'pill-danger',
                                'password_reset', 'password_changed', 'updated' => 'pill-amber',
                                default => 'pill-neutral',
                            };
                        @endphp
                        <tr wire:key="al-{{ $l->id }}">
                            <td class="whitespace-nowrap text-xs">{{ $l->created_at->copy()->setTimezone('Africa/Nairobi')->format('d M Y, H:i') }}</td>
                            <td>
                                <span class="font-semibold text-neutral-900">{{ $l->user_name ?? 'Not signed in' }}</span>
                                @if ($l->email && ! $l->user_name)<br><span class="font-mono text-xs text-neutral-500">{{ $l->email }}</span>@endif
                            </td>
                            <td><span class="{{ $pill }}">{{ $events[$l->event] ?? $l->event }}</span></td>
                            <td>
                                {{ $l->label }}
                                @if ($l->ip)<br><span class="font-mono text-xs text-neutral-400">{{ $l->ip }}</span>@endif
                            </td>
                            <td class="text-right">
                                @if ($l->changes)
                                    <button type="button" wire:click="toggle({{ $l->id }})" class="btn-ghost" style="padding: 0.2rem 0.6rem">{{ $open === $l->id ? 'Hide' : 'Changes' }}</button>
                                @endif
                            </td>
                        </tr>
                        @if ($open === $l->id && $l->changes)
                            <tr wire:key="alc-{{ $l->id }}">
                                <td colspan="5" class="bg-neutral-50">
                                    @foreach ($l->changes as $field => $pair)
                                        <p class="text-xs"><span class="font-mono font-semibold text-neutral-900">{{ $field }}</span>: <span class="text-neutral-500">{{ ($pair[0] ?? '') === '' ? 'empty' : $pair[0] }}</span> &rarr; <span class="text-neutral-900">{{ ($pair[1] ?? '') === '' ? 'empty' : $pair[1] }}</span></p>
                                    @endforeach
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr><td colspan="5" class="text-center text-neutral-500">Nothing recorded for these filters yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-4">{{ $logs->links() }}</div>
</div>
