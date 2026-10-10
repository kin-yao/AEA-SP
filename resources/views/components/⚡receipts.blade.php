<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Models\Payment;
use Carbon\Carbon;

new #[Layout('layouts.app', ['title' => 'Receipts'])] class extends Component
{
    use \App\Support\ShowsMore;

    // What the person is typing, and what has actually been applied.
    public string $search = '';
    public string $method = '';
    public string $from = '';
    public string $to = '';
    public array $applied = ['search' => '', 'method' => '', 'from' => '', 'to' => ''];

    public ?int $open = null;

    public function mount(): void
    {
        abort_unless(auth()->user()->hasRole('Finance'), 403);
    }

    public function applyFilter(): void
    {
        $this->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $this->applied = ['search' => trim($this->search), 'method' => $this->method, 'from' => $this->from, 'to' => $this->to];
    }

    public function resetFilter(): void
    {
        $this->reset(['search', 'method', 'from', 'to']);
        $this->resetValidation();
        $this->applied = ['search' => '', 'method' => '', 'from' => '', 'to' => ''];
    }

    public function show(int $id): void
    {
        $this->open = $id;
    }

    public function download(int $id)
    {
        abort_unless(auth()->user()->hasRole('Finance'), 403);
        $payment = Payment::findOrFail($id);
        $bytes = \App\Services\FinancePdf::receipt($payment);

        return response()->streamDownload(fn () => print($bytes), $payment->reference.'.pdf');
    }

    public function close(): void
    {
        $this->open = null;
    }

    private function filtered()
    {
        $a = $this->applied;
        $q = Payment::with(['customer', 'invoice', 'recordedBy'])->orderByDesc('paid_at')->orderByDesc('id');

        if ($a['method'] !== '') {
            $q->where('method', $a['method']);
        }
        if ($a['from'] !== '') {
            $q->whereDate('paid_at', '>=', Carbon::parse($a['from'])->toDateString());
        }
        if ($a['to'] !== '') {
            $q->whereDate('paid_at', '<=', Carbon::parse($a['to'])->toDateString());
        }
        if ($a['search'] !== '') {
            $term = '%'.$a['search'].'%';
            $q->where(function ($w) use ($term) {
                $w->where('reference', 'like', $term)
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', $term))
                    ->orWhereHas('invoice', fn ($i) => $i->where('reference', 'like', $term));
            });
        }

        return $q;
    }

    public function exportCsv()
    {
        $query = $this->filtered();

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            \App\Support\Csv::put($out, ['Receipt', 'Customer', 'Invoice', 'Date', 'Method', 'Recorded by', 'Amount']);
            foreach ($query->lazy(500) as $p) {
                \App\Support\Csv::put($out, [$p->reference, $p->customer?->name, $p->invoice?->reference, $p->paid_at->format('Y-m-d'), $p->method, $p->recordedBy?->name, number_format($p->amount_minor / 100, 2, '.', '')]);
            }
            fclose($out);
        }, 'aea-receipts-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }

    public function with(): array
    {
        $query = $this->filtered();
        $matching = (clone $query)->count();

        return [
            'rows' => $query->limit($this->limit)->get(),
            'matching' => $matching,
            'total' => Payment::count(),
            'sumMinor' => (int) (clone $query)->reorder()->sum('amount_minor'),
            'methods' => Payment::query()->distinct()->orderBy('method')->pluck('method'),
            'detail' => $this->open ? Payment::with(['customer', 'invoice', 'recordedBy'])->find($this->open) : null,
        ];
    }
};
?>
@php
    $kes = fn (int $minor) => currency().' '.number_format($minor / 100, 0);
@endphp
<div class="mx-auto" style="max-width: 72rem">
    <div class="mb-3 flex items-center gap-3">
        <h1 class="text-xl font-semibold text-neutral-900">Receipts</h1>
        <div class="flex-1 border-t border-neutral-200"></div>
        <span class="text-xs text-neutral-400">{{ number_format($matching) }} of {{ number_format($total) }}</span>
        <button type="button" wire:click="exportCsv" class="btn-outline" style="padding: 0.4rem 0.8rem">Export CSV</button>
    </div>

    {{-- Filters --}}
    <form wire:submit="applyFilter" class="card mb-4" style="padding: 1rem">
        <div class="flex flex-wrap items-end gap-3">
            <div style="flex: 2 1 220px; min-width: 0">
                <input type="text" wire:model="search" class="input" placeholder="Search receipt, customer or invoice">
            </div>
            <div style="flex: 1 1 160px; min-width: 0">
                <select wire:model="method" class="input">
                    <option value="">All methods</option>
                    @foreach ($methods as $m)
                        <option value="{{ $m }}">{{ $m }}</option>
                    @endforeach
                </select>
            </div>
            <div style="flex: 1 1 140px; min-width: 0">
                <label class="label">From</label>
                <input type="date" wire:model="from" class="input">
            </div>
            <div style="flex: 1 1 140px; min-width: 0">
                <label class="label">To</label>
                <input type="date" wire:model="to" class="input">
            </div>
            <div class="flex gap-2">
                <button type="submit" class="btn-primary">Filter</button>
                @if (array_filter($applied) || $search || $method || $from || $to)
                    <button type="button" wire:click="resetFilter" class="btn-outline">Reset</button>
                @endif
            </div>
        </div>
        @error('from') <p class="field-error">{{ $message }}</p> @enderror
        @error('to') <p class="field-error">{{ $message }}</p> @enderror
    </form>

    {{-- List --}}
    <div class="card" style="padding: 0; overflow: hidden">
        <div style="overflow-x: auto">
            <table class="table-clean" style="min-width: 760px">
                <thead>
                    <tr>
                        <th>No.</th>
                        <th>Customer</th>
                        <th>Invoice</th>
                        <th>Date</th>
                        <th>Method</th>
                        <th>Recorded by</th>
                        <th class="text-right">Amount</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $p)
                        <tr wire:key="rcp-{{ $p->id }}">
                            <td class="whitespace-nowrap font-mono font-bold text-neutral-900">{{ $p->reference }}</td>
                            <td>{{ $p->customer?->name }}</td>
                            <td class="whitespace-nowrap font-mono text-xs">{{ $p->invoice?->reference }}</td>
                            <td class="whitespace-nowrap">{{ $p->paid_at->format('d M') }}</td>
                            <td>{{ $p->method }}</td>
                            <td>{{ $p->recordedBy?->name ?? '-' }}</td>
                            <td class="whitespace-nowrap text-right font-mono text-xs font-semibold">{{ $kes($p->amount_minor) }}</td>
                            <td class="text-right">
                                <button type="button" wire:click="download({{ $p->id }})" class="btn-primary" style="padding: 0.35rem 0.9rem">PDF</button>
                                <button type="button" wire:click="show({{ $p->id }})" class="btn-outline" style="padding: 0.35rem 0.9rem">Open</button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-center text-neutral-400" style="padding: 2rem">No receipts match these filters. A receipt is created when a payment is recorded on an invoice.</td>
                        </tr>
                    @endforelse
                </tbody>
                @if ($rows->isNotEmpty())
                    <tfoot>
                        <tr>
                            <td colspan="6" class="text-right text-xs font-semibold text-neutral-700" style="padding: 0.75rem 1rem">Total received</td>
                            <td class="whitespace-nowrap text-right font-mono text-sm font-bold text-neutral-900" style="padding: 0.75rem 1rem">{{ $kes($sumMinor) }}</td>
                            <td></td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </div>

    {{-- Detail window --}}
    @if ($detail)
        @php
            $inv = $detail->invoice;
            $cust = $detail->customer;
        @endphp
        <div style="position: fixed; inset: 0; z-index: 60; display: flex; align-items: center; justify-content: center; padding: 1rem; background: rgba(0, 0, 0, 0.45)" wire:click.self="close">
            <div class="card" style="width: 100%; max-width: 40rem; max-height: 92vh; overflow-y: auto; padding: 0">
                <div class="flex items-center justify-between gap-3" style="padding: 1rem 1.25rem; border-bottom: 1px solid #e4e4e7">
                    <h2 class="text-base font-semibold text-neutral-900">Receipt, No. {{ $detail->reference }}</h2>
                    <button type="button" wire:click="close" class="btn-outline" style="padding: 0.3rem 0.7rem" aria-label="Close">&times;</button>
                </div>

                <div style="padding: 1.25rem">
                    <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem 2rem">
                        <div>
                            <p class="text-xs text-neutral-500">Received from</p>
                            <p class="text-sm font-semibold text-neutral-900">{{ $cust?->name }}</p>
                            @if ($cust?->po_box)<p class="text-xs text-neutral-500">{{ $cust->po_box }}</p>@endif
                        </div>
                        <div>
                            <p class="text-xs text-neutral-500">Against invoice</p>
                            <p class="text-sm font-semibold text-neutral-900">{{ $inv?->reference ?? '-' }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-neutral-500">Date received</p>
                            <p class="text-sm font-semibold text-neutral-900">{{ $detail->paid_at->format('d M Y') }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-neutral-500">Method</p>
                            <p class="text-sm font-semibold text-neutral-900">{{ $detail->method }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-neutral-500">Recorded by</p>
                            <p class="text-sm font-semibold text-neutral-900">{{ $detail->recordedBy?->name ?? '-' }}</p>
                        </div>
                    </div>

                    <div style="margin-top: 1.25rem; border-top: 1px solid #e4e4e7; padding-top: 1rem">
                        <div class="flex items-center justify-between py-1 text-sm">
                            <span class="text-neutral-500">Invoice total</span>
                            <span class="font-mono text-neutral-900">{{ $inv ? $kes($inv->amount_minor) : '-' }}</span>
                        </div>
                        <div class="flex items-center justify-between py-1 text-sm">
                            <span class="text-neutral-500">Paid to date</span>
                            <span class="font-mono text-neutral-900">{{ $inv ? $kes($inv->paid_minor) : '-' }}</span>
                        </div>
                        <div class="flex items-center justify-between py-1 text-sm">
                            <span class="text-neutral-500">Balance on the invoice</span>
                            <span class="font-mono text-neutral-900">{{ $inv ? $kes($inv->balanceMinor()) : '-' }}</span>
                        </div>
                        <div class="flex items-center justify-between py-2" style="border-top: 1px solid #e4e4e7; margin-top: 0.4rem">
                            <span class="text-sm font-semibold text-neutral-700">Amount received</span>
                            <span class="font-mono text-lg font-bold text-neutral-900">{{ $kes($detail->amount_minor) }}</span>
                        </div>
                    </div>

                    <div class="mt-4 flex flex-wrap gap-2">
                        <button type="button" wire:click="download({{ $detail->id }})" class="btn-primary">Download receipt</button>
                        <button type="button" wire:click="close" class="btn-outline">Close</button>
                        @if ($inv)
                            <a href="/invoices/{{ $inv->id }}" wire:navigate class="btn-outline">Open invoice</a>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @endif
    <x-show-more :shown="$rows->count()" :total="$matching" />
</div>
