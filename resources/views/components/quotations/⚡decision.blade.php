<?php

use Livewire\Component;
use Livewire\Attributes\Locked;
use App\Models\Quotation;
use App\Services\WorkflowNotifier;

new class extends Component
{
    #[Locked]
    public int $quotationId;

    public bool $details = false;       // show the full quotation (used on the Approvals page)
    public string $mode = '';           // '', 'reject' or 'escalate'
    public string $reason = '';
    public string $note = '';

    public function mount(Quotation $quotation, bool $details = false): void
    {
        $this->authorize('view', $quotation);
        $this->quotationId = $quotation->id;
        $this->details = $details;
    }

    protected function quotation(): Quotation
    {
        return Quotation::with(['customer', 'site', 'items', 'createdBy', 'decider', 'escalator', 'sourceRequest'])->findOrFail($this->quotationId);
    }

    public function choose(string $mode): void
    {
        abort_unless(in_array($mode, ['', 'reject', 'escalate'], true), 422);
        $this->mode = $mode;
        $this->reset(['reason', 'note']);
        $this->resetErrorBag();
    }

    public function approve(): void
    {
        $q = $this->quotation();
        $this->authorize('approve', $q);

        $q->approve(auth()->id());

        WorkflowNotifier::customer($q->customer, 'Your quotation is ready', ["Quotation {$q->reference} has been approved and is ready for your review."]);

        WorkflowNotifier::user(
            $q->createdBy,
            'Your quotation was approved',
            ["Quotation {$q->reference} for {$q->customer->name} has been approved by ".auth()->user()->name.'.'],
            url("/quotations/{$q->id}"),
            'View quotation',
        );

        $this->afterDecision("{$q->reference} approved.");
    }

    public function reject(): void
    {
        $q = $this->quotation();
        $this->authorize('reject', $q);

        $v = $this->validate(['reason' => ['required', 'string', 'min:5', 'max:2000']], [
            'reason.required' => 'Say why you are rejecting it, so the quotation can be corrected.',
            'reason.min' => 'Give a little more detail on the reason.',
        ]);

        $q->reject(trim($v['reason']), auth()->id());

        WorkflowNotifier::user(
            $q->createdBy,
            'Quotation rejected',
            ["Quotation {$q->reference} for {$q->customer->name} was rejected by ".auth()->user()->name.'.', 'Reason: '.trim($v['reason'])],
            url("/quotations/{$q->id}"),
            'View quotation',
        );

        $this->afterDecision("{$q->reference} rejected.");
    }

    public function escalate(): void
    {
        $q = $this->quotation();
        $this->authorize('escalate', $q);

        $v = $this->validate(['note' => ['nullable', 'string', 'max:1000']]);

        $q->escalate(auth()->id(), $v['note'] ? trim($v['note']) : null);

        $lines = ["Quotation {$q->reference} for {$q->customer->name} ({$q->currency_code} ".number_format($q->totalMinor() / 100, 2).') was passed up to you by '.auth()->user()->name.'.'];
        if ($q->escalation_note) {
            $lines[] = 'Note: '.$q->escalation_note;
        }

        WorkflowNotifier::role('Manager', 'Quotation escalated for your decision', $lines, url("/quotations/{$q->id}"), 'Review quotation');

        WorkflowNotifier::user(
            $q->createdBy,
            'Your quotation was passed to the Manager',
            ["Quotation {$q->reference} is now waiting for the Manager's decision."],
            url("/quotations/{$q->id}"),
            'View quotation',
        );

        $this->afterDecision("{$q->reference} escalated to the Manager.");
    }

    protected function afterDecision(string $message): void
    {
        $this->mode = '';
        $this->reset(['reason', 'note']);
        $this->dispatch('quotation-decided', message: $message);
    }

    public function with(): array
    {
        return ['q' => $this->quotation()];
    }
};
?>

<div>
    @php $user = auth()->user(); $internal = $user->can('seeDecisionNotes', $q); @endphp

    @if ($q->status === 'Rejected' && $internal)
        <div class="mb-4 rounded-[var(--radius-md)] border px-4 py-3" style="background: #fef2f2; border-color: #fecaca">
            <p class="text-sm font-semibold" style="color: #991b1b">Rejected{{ $q->decider ? ' by '.$q->decider->name : '' }}{{ $q->decided_at ? ', '.$q->decided_at->format('d M Y H:i') : '' }}</p>
            <p class="mt-1 text-sm" style="color: #7f1d1d">{{ $q->rejection_reason ?: 'No reason was recorded.' }}</p>
            @can('create', \App\Models\Quotation::class)
                <a href="/quotations/create?revise={{ $q->id }}" wire:navigate class="btn-primary mt-3 inline-block">Make a corrected quotation</a>
            @endcan
        </div>
    @endif

    @if ($q->escalated_at && $internal)
        <div class="mb-4 rounded-[var(--radius-md)] border px-4 py-3 text-sm" style="background: #fffbeb; border-color: #fde68a; color: #78350f">
            Passed up to the Manager by {{ $q->escalator?->name ?? 'a Supervisor' }}, {{ $q->escalated_at->format('d M Y H:i') }}.
            @if ($q->escalation_note) <span class="block mt-1">Note: {{ $q->escalation_note }}</span> @endif
        </div>
    @endif

    @if ($details)
        <div class="card mb-4">
            <div class="mb-3 flex items-start justify-between gap-3">
                <div>
                    <p class="font-mono text-sm font-bold text-neutral-900">{{ $q->reference }}</p>
                    <p class="text-sm text-neutral-600">{{ $q->customer->name }}@if ($q->site) &middot; {{ $q->site->name }}@endif</p>
                </div>
                <a href="/quotations/{{ $q->id }}" wire:navigate class="text-xs font-medium text-info-700 hover:text-info-800">Open full page</a>
            </div>
            <p class="mb-1 text-xs text-neutral-500">Scope</p>
            <p class="mb-3 text-sm font-semibold text-neutral-900">{{ $q->scope }}</p>
            <table class="table-clean">
                <thead><tr><th class="text-left">Item</th><th class="text-right">Qty</th><th class="text-right">Rate</th><th class="text-right">Amount</th></tr></thead>
                <tbody>
                    @foreach ($q->items as $item)
                        <tr><td>{{ $item->description }}</td><td class="text-right">{{ $item->quantity }}</td><td class="text-right">{{ number_format($item->rate_minor / 100, 2) }}</td><td class="text-right">{{ number_format($item->amountMinor() / 100, 2) }}</td></tr>
                    @endforeach
                    <tr><td colspan="3">Labour</td><td class="text-right">{{ number_format($q->labour_minor / 100, 2) }}</td></tr>
                </tbody>
            </table>
            <div class="mt-3 flex justify-end">
                <div class="w-52 text-sm">
                    <div class="flex justify-between py-1"><span class="text-neutral-500">Subtotal</span><span>{{ number_format($q->subtotalMinor() / 100, 2) }}</span></div>
                    <div class="flex justify-between py-1"><span class="text-neutral-500">VAT, {{ round($q->vat_rate * 100, 2) }}%</span><span>{{ number_format($q->vatMinor() / 100, 2) }}</span></div>
                    <div class="flex justify-between border-t border-neutral-100 py-2 font-semibold"><span>Total</span><span>{{ $q->currency_code }} {{ number_format($q->totalMinor() / 100, 2) }}</span></div>
                </div>
            </div>
            <p class="mt-3 border-t border-neutral-100 pt-3 text-xs text-neutral-500">
                Prepared by {{ $q->createdBy?->name ?? 'unknown' }} on {{ $q->created_at->format('d M Y') }}
                @if ($q->sourceRequest) &middot; from request {{ $q->sourceRequest->reference }} @endif
                &middot; valid {{ $q->validity_days }} days
            </p>
        </div>
    @endif

    @if ($user->can('approve', $q))
        @if ($mode === 'reject')
            <div class="card mb-4" style="border-left: 4px solid #dc2626">
                <label class="label">Why is this being rejected?</label>
                <textarea wire:model="reason" rows="3" class="input" placeholder="e.g. Labour is too high for a one-day job. Please re-check the rate."></textarea>
                @error('reason') <p class="field-error">{{ $message }}</p> @enderror
                <div class="mt-3 flex gap-2">
                    <button type="button" wire:click="reject" wire:loading.attr="disabled" wire:target="reject" class="btn-primary flex-1" style="background: #dc2626; border-color: #dc2626">Reject quotation</button>
                    <button type="button" wire:click="choose('')" class="btn-outline">Cancel</button>
                </div>
            </div>
        @elseif ($mode === 'escalate')
            <div class="card mb-4" style="border-left: 4px solid #d97706">
                <label class="label">Note for the Manager, optional</label>
                <textarea wire:model="note" rows="2" class="input" placeholder="Why does this need the Manager?"></textarea>
                <div class="mt-3 flex gap-2">
                    <button type="button" wire:click="escalate" wire:loading.attr="disabled" wire:target="escalate" class="btn-primary flex-1">Send to the Manager</button>
                    <button type="button" wire:click="choose('')" class="btn-outline">Cancel</button>
                </div>
            </div>
        @else
            <div class="mb-4 flex flex-wrap gap-2">
                <button type="button" wire:click="approve" wire:loading.attr="disabled" wire:target="approve" wire:confirm="Approve {{ $q->reference }} for {{ $q->currency_code }} {{ number_format($q->totalMinor() / 100, 2) }}?" class="btn-primary flex-1">Approve</button>
                <button type="button" wire:click="choose('reject')" class="btn-outline flex-1">Reject</button>
                @can('escalate', $q)
                    <button type="button" wire:click="choose('escalate')" class="btn-outline flex-1">Escalate to Manager</button>
                @endcan
            </div>
        @endif
    @endif
</div>
