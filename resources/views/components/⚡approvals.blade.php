<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Models\Quotation;
use App\Services\WorkflowNotifier;

new #[Layout('layouts.app', ['title' => 'Approvals'])] class extends Component
{
    public function mount(): void
    {
        abort_unless(auth()->user()->hasAnyRole(['Manager', 'Supervisor']), 403);
    }

    // Managers decide the big quotations, Supervisors the smaller ones.
    protected function role(): string
    {
        return auth()->user()->hasRole('Manager') ? 'Manager' : 'Supervisor';
    }

    public function approve(int $id): void
    {
        $quotation = Quotation::with(['customer', 'createdBy'])->findOrFail($id);
        $this->authorize('approve', $quotation);

        $quotation->approve();

        WorkflowNotifier::customer(
            $quotation->customer,
            'Your quotation is ready',
            ["Quotation {$quotation->reference} has been approved and is ready for your review."],
        );

        WorkflowNotifier::user(
            $quotation->createdBy,
            'Your quotation was approved',
            ["Quotation {$quotation->reference} for {$quotation->customer->name} has been approved."],
            url("/quotations/{$quotation->id}"),
            'View quotation',
        );

        session()->flash('approvals-message', "{$quotation->reference} approved.");
    }

    public function sendBack(int $id): void
    {
        $quotation = Quotation::with(['customer', 'createdBy'])->findOrFail($id);
        $this->authorize('sendBack', $quotation);

        $quotation->sendBack();

        WorkflowNotifier::user(
            $quotation->createdBy,
            'Quotation sent back for changes',
            ["Quotation {$quotation->reference} for {$quotation->customer->name} was sent back."],
            url("/quotations/{$quotation->id}"),
            'View quotation',
        );

        session()->flash('approvals-message', "{$quotation->reference} sent back.");
    }

    public function with(): array
    {
        $role = $this->role();

        $waiting = Quotation::with(['customer', 'items'])
            ->where('approval_threshold', $role)
            ->where('status', 'Awaiting '.$role)
            ->oldest()
            ->get();

        $decided = Quotation::with(['customer', 'items'])
            ->where('approval_threshold', $role)
            ->whereIn('status', ['Approved', 'Accepted', 'Converted', 'Sent back'])
            ->where('updated_at', '>=', now()->subDays(60))
            ->latest('updated_at')
            ->take(25)
            ->get();

        // A Supervisor also sees what was passed up, read only.
        $escalated = $role === 'Supervisor'
            ? Quotation::with(['customer', 'items'])
                ->where('approval_threshold', 'Manager')
                ->where('created_at', '>=', now()->subDays(60))
                ->latest()
                ->take(25)
                ->get()
            : collect();

        return [
            'role' => $role,
            'escalated' => $escalated,
            'waiting' => $waiting,
            'decided' => $decided,
            'waitingValue' => $waiting->sum(fn (Quotation $q) => $q->totalMinor()),
            'threshold' => Quotation::APPROVAL_THRESHOLD_MINOR,
        ];
    }
};
?>

<div class="mx-auto" style="max-width: 72rem">
    <div class="mb-5">
        <h1 class="text-xl font-semibold text-neutral-900">Approvals</h1>
        <p class="text-sm text-neutral-500">
            @if ($role === 'Manager')
                Quotations of KES {{ number_format($threshold / 100, 0) }} or more need your decision.
                Anything smaller is approved by a Supervisor.
            @else
                Quotations under KES {{ number_format($threshold / 100, 0) }} need your decision.
                Anything larger goes to the Manager automatically.
            @endif
        </p>
    </div>

    @if (session('approvals-message'))
        <div class="mb-4 rounded-[var(--radius-md)] border border-success-200 bg-success-50 px-4 py-3 text-sm font-medium text-success-800">
            {{ session('approvals-message') }}
        </div>
    @endif

    <div class="mb-5 grid gap-3" style="grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));">
        <div class="card">
            <p class="text-xs font-medium text-neutral-500">Awaiting your decision</p>
            <p @class(['mt-2 font-mono text-2xl font-bold', 'text-amber-700' => $waiting->count() > 0, 'text-neutral-900' => $waiting->count() === 0])>{{ $waiting->count() }}</p>
        </div>
        <div class="card">
            <p class="text-xs font-medium text-neutral-500">Value waiting</p>
            <p class="mt-2 font-mono text-2xl font-bold text-neutral-900">{{ \App\Services\ManagerReports::kes($waitingValue, true) }}</p>
        </div>
    </div>

    <div class="card mb-5" style="padding: 0">
        <h2 class="px-5 pt-5 text-sm font-semibold text-neutral-900">Awaiting your decision</h2>
        <div class="mt-3 overflow-x-auto">
            <table class="table-clean" style="min-width: 760px">
                <thead>
                    <tr><th>Reference</th><th>Customer</th><th>Scope</th><th class="text-right">Amount</th><th>Raised</th><th class="text-right">Decision</th></tr>
                </thead>
                <tbody>
                    @forelse ($waiting as $q)
                        <tr wire:key="w-{{ $q->id }}">
                            <td><a href="/quotations/{{ $q->id }}" wire:navigate class="font-mono text-xs font-bold text-neutral-900 hover:text-primary-600">{{ $q->reference }}</a></td>
                            <td class="font-medium text-neutral-900">{{ $q->customer->name }}</td>
                            <td class="text-neutral-600" style="max-width: 18rem">{{ \Illuminate\Support\Str::limit($q->scope, 70) }}</td>
                            <td class="whitespace-nowrap text-right font-mono text-xs font-semibold">KES {{ number_format($q->totalMinor() / 100, 0) }}</td>
                            <td class="whitespace-nowrap text-neutral-500">{{ $q->created_at->format('d M Y') }}</td>
                            <td>
                                <div class="flex justify-end gap-2">
                                    <button type="button" wire:click="sendBack({{ $q->id }})" wire:confirm="Send {{ $q->reference }} back for changes?" class="btn-outline" style="padding: 0.4rem 0.8rem">Send back</button>
                                    <button type="button" wire:click="approve({{ $q->id }})" wire:confirm="Approve {{ $q->reference }} for KES {{ number_format($q->totalMinor() / 100, 0) }}?" class="btn-primary" style="padding: 0.4rem 0.8rem">Approve</button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-8 text-center text-neutral-500">Nothing is waiting for you. You are all caught up.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="card @if ($role === 'Supervisor') mb-5 @endif" style="padding: 0">
        <h2 class="px-5 pt-5 text-sm font-semibold text-neutral-900">Decided in the last 60 days</h2>
        <div class="mt-3 overflow-x-auto">
            <table class="table-clean" style="min-width: 640px">
                <thead>
                    <tr><th>Reference</th><th>Customer</th><th class="text-right">Amount</th><th>Decided</th><th>Outcome</th></tr>
                </thead>
                <tbody>
                    @forelse ($decided as $q)
                        <tr wire:key="d-{{ $q->id }}">
                            <td><a href="/quotations/{{ $q->id }}" wire:navigate class="font-mono text-xs font-bold text-neutral-900 hover:text-primary-600">{{ $q->reference }}</a></td>
                            <td>{{ $q->customer->name }}</td>
                            <td class="whitespace-nowrap text-right font-mono text-xs">KES {{ number_format($q->totalMinor() / 100, 0) }}</td>
                            <td class="whitespace-nowrap text-neutral-500">{{ $q->updated_at->format('d M Y') }}</td>
                            <td>
                                <span class="{{ $q->status === 'Sent back' ? 'pill-amber' : 'pill-success' }}">{{ $q->status === 'Sent back' ? 'Sent back' : 'Approved' }}</span>
                                @if (in_array($q->status, ['Accepted', 'Converted']))
                                    <span class="ml-1 text-xs text-neutral-400">{{ strtolower($q->status) }}</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-8 text-center text-neutral-500">No decisions yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @if ($role === 'Supervisor')
        <div class="card" style="padding: 0">
            <h2 class="px-5 pt-5 text-sm font-semibold text-neutral-900">Escalated to the Manager, last 60 days</h2>
            <p class="px-5 pt-1 text-xs text-neutral-500">Read only. These were over the limit, so the Manager decides them.</p>
            <div class="mt-3 overflow-x-auto">
                <table class="table-clean" style="min-width: 640px">
                    <thead>
                        <tr><th>Reference</th><th>Customer</th><th class="text-right">Amount</th><th>Raised</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                        @forelse ($escalated as $q)
                            <tr wire:key="e-{{ $q->id }}">
                                <td><a href="/quotations/{{ $q->id }}" wire:navigate class="font-mono text-xs font-bold text-neutral-900 hover:text-primary-600">{{ $q->reference }}</a></td>
                                <td>{{ $q->customer->name }}</td>
                                <td class="whitespace-nowrap text-right font-mono text-xs">KES {{ number_format($q->totalMinor() / 100, 0) }}</td>
                                <td class="whitespace-nowrap text-neutral-500">{{ $q->created_at->format('d M Y') }}</td>
                                <td><span class="{{ str_starts_with($q->status, 'Awaiting') ? 'pill-amber' : ($q->status === 'Sent back' ? 'pill-danger' : 'pill-success') }}">{{ $q->status }}</span></td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="py-8 text-center text-neutral-500">Nothing has been escalated.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
