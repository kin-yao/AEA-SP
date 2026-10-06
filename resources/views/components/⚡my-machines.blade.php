<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Models\Document;
use App\Models\Equipment;

new #[Layout('layouts.app', ['title' => 'My Machines'])] class extends Component
{
    // What the person is typing, and what has actually been applied.
    public string $search = '';
    public string $status = '';
    public array $applied = ['search' => '', 'status' => ''];

    public ?int $open = null;

    public function mount(): void
    {
        abort_unless(auth()->user()->hasRole('Customer') && auth()->user()->customer_id, 403);
    }

    public function applyFilter(): void
    {
        $this->applied = ['search' => trim($this->search), 'status' => $this->status];
    }

    public function resetFilter(): void
    {
        $this->reset(['search', 'status']);
        $this->applied = ['search' => '', 'status' => ''];
    }

    public function show(int $id): void
    {
        $this->open = $id;
    }

    public function close(): void
    {
        $this->open = null;
    }

    private function mine()
    {
        return Equipment::with('site')
            ->where('customer_id', auth()->user()->customer_id)
            ->orderBy('model')
            ->get();
    }

    public function with(): array
    {
        $all = $this->mine();
        $a = $this->applied;

        $rows = $all->filter(function ($e) use ($a) {
            if ($a['status'] !== '' && $e->visitStatus() !== $a['status']) {
                return false;
            }
            if ($a['search'] !== '') {
                $hay = strtolower(implode(' ', array_filter([$e->serial_number, $e->model, $e->category, $e->site?->name])));
                if (! str_contains($hay, strtolower($a['search']))) {
                    return false;
                }
            }

            return true;
        })->values();

        $detail = $this->open ? $all->firstWhere('id', $this->open) : null;

        $reports = collect();
        if ($detail) {
            $reports = Document::with('workOrder')
                ->where('customer_id', auth()->user()->customer_id)
                ->where('type', Document::TYPE_REPORT)
                ->where('status', 'Released')
                ->whereHas('workOrder', fn ($w) => $w->where('equipment_id', $detail->id))
                ->latest()
                ->get();
        }

        return ['rows' => $rows, 'total' => $all->count(), 'detail' => $detail, 'reports' => $reports];
    }
};
?>
@php
    $pill = ['Active' => 'pill-success', 'Due soon' => 'pill-amber', 'Overdue' => 'pill-danger'];
@endphp
<div class="mx-auto" style="max-width: 72rem">
    <div class="mb-3 flex items-center gap-3">
        <h1 class="text-xl font-semibold text-neutral-900">My Machines</h1>
        <div class="flex-1 border-t border-neutral-200"></div>
        <span class="text-xs text-neutral-400">{{ $rows->count() }} of {{ $total }}</span>
        <a href="/requests/create" wire:navigate class="btn-primary" style="padding: 0.4rem 0.9rem">Request service</a>
    </div>

    {{-- Filters --}}
    <form wire:submit="applyFilter" class="card mb-4" style="padding: 1rem">
        <div class="flex flex-wrap items-end gap-3">
            <div style="flex: 2 1 220px; min-width: 0">
                <input type="text" wire:model="search" class="input" placeholder="Search serial, model or site">
            </div>
            <div style="flex: 1 1 160px; min-width: 0">
                <select wire:model="status" class="input">
                    <option value="">All statuses</option>
                    <option value="Active">Active</option>
                    <option value="Due soon">Due soon</option>
                    <option value="Overdue">Overdue</option>
                </select>
            </div>
            <div class="flex gap-2">
                <button type="submit" class="btn-primary">Filter</button>
                @if (array_filter($applied) || $search || $status)
                    <button type="button" wire:click="resetFilter" class="btn-outline">Reset</button>
                @endif
            </div>
        </div>
    </form>

    {{-- List --}}
    <div class="card" style="padding: 0; overflow: hidden">
        <div style="overflow-x: auto">
            <table class="table-clean" style="min-width: 760px">
                <thead>
                    <tr>
                        <th>Serial</th>
                        <th>Machine</th>
                        <th>Site</th>
                        <th>Warranty</th>
                        <th>Next service</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $e)
                        @php $st = $e->visitStatus(); @endphp
                        <tr wire:key="mc-{{ $e->id }}">
                            <td class="whitespace-nowrap font-mono font-bold text-neutral-900">{{ $e->serial_number }}</td>
                            <td>{{ $e->model }}</td>
                            <td>{{ $e->site?->name ?? '-' }}</td>
                            <td class="whitespace-nowrap">{{ $e->warranty_expires_at ? ($e->warranty_expires_at->isPast() ? 'Expired' : 'To '.$e->warranty_expires_at->format('M Y')) : '-' }}</td>
                            <td class="whitespace-nowrap">{{ $e->next_visit_due_at?->format('d M Y') ?? 'Not set' }}</td>
                            <td><span class="{{ $pill[$st] }}">{{ $st }}</span></td>
                            <td class="text-right">
                                <button type="button" wire:click="show({{ $e->id }})" class="btn-outline" style="padding: 0.35rem 0.9rem">Open</button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-neutral-400" style="padding: 2rem">{{ $total === 0 ? 'No machines are registered to your company yet.' : 'No machines match these filters.' }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Detail window --}}
    @if ($detail)
        @php $dst = $detail->visitStatus(); @endphp
        <div style="position: fixed; inset: 0; z-index: 60; display: flex; align-items: center; justify-content: center; padding: 1rem; background: rgba(0, 0, 0, 0.45)" wire:click.self="close">
            <div class="card" style="width: 100%; max-width: 44rem; max-height: 92vh; overflow-y: auto; padding: 0">
                <div class="flex items-center justify-between gap-3" style="padding: 1rem 1.25rem; border-bottom: 1px solid #e4e4e7">
                    <h2 class="text-base font-semibold text-neutral-900">{{ $detail->model }}, Serial {{ $detail->serial_number }}</h2>
                    <button type="button" wire:click="close" class="btn-outline" style="padding: 0.3rem 0.7rem" aria-label="Close">&times;</button>
                </div>

                <div style="padding: 1.25rem">
                    <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem 2rem">
                        <div>
                            <p class="text-xs text-neutral-500">Site</p>
                            <p class="text-sm font-semibold text-neutral-900">{{ $detail->site?->name ?? '-' }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-neutral-500">Category</p>
                            <p class="text-sm font-semibold text-neutral-900">{{ $detail->category ?: '-' }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-neutral-500">Installed</p>
                            <p class="text-sm font-semibold text-neutral-900">{{ $detail->installed_at?->format('d M Y') ?? '-' }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-neutral-500">Warranty</p>
                            <p class="text-sm font-semibold text-neutral-900">{{ $detail->warranty_expires_at ? ($detail->warranty_expires_at->isPast() ? 'Expired '.$detail->warranty_expires_at->format('d M Y') : 'Until '.$detail->warranty_expires_at->format('d M Y')) : '-' }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-neutral-500">Cover</p>
                            <p class="text-sm font-semibold text-neutral-900">{{ $detail->cover }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-neutral-500">Next service</p>
                            <p class="text-sm font-semibold text-neutral-900">{{ $detail->next_visit_due_at?->format('d M Y') ?? 'Not set' }} <span class="{{ $pill[$dst] }}" style="margin-left: 0.4rem">{{ $dst }}</span></p>
                        </div>
                    </div>

                    <div style="margin-top: 1.25rem">
                        <p class="mb-1 text-xs font-semibold text-neutral-700">Service reports for this machine</p>
                        @forelse ($reports as $r)
                            <a href="/documents/{{ $r->id }}" wire:navigate class="flex items-center justify-between gap-3 py-2 text-sm" style="border-top: 1px solid #f0f0f2; text-decoration: none">
                                <span class="font-mono font-bold text-neutral-900">{{ $r->reference }}</span>
                                <span class="text-neutral-500">{{ $r->workOrder?->reference }}</span>
                                <span class="text-neutral-500">{{ $r->created_at->format('d M Y') }}</span>
                            </a>
                        @empty
                            <p class="py-2 text-sm text-neutral-400" style="border-top: 1px solid #f0f0f2">No service reports yet.</p>
                        @endforelse
                    </div>

                    <div class="mt-5 flex flex-wrap gap-2">
                        <button type="button" wire:click="close" class="btn-outline">Close</button>
                        <a href="/requests/create" wire:navigate class="btn-primary">Request service</a>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
