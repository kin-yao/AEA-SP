<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Models\User;
use App\Mail\AccountCredentialsMail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

new #[Layout('layouts.app', ['title' => 'User account'])] class extends Component
{
    public User $account;
    public ?string $generatedPassword = null;
    public ?bool $emailSent = null;

    public function mount(User $account): void
    {
        $this->authorize('view', $account);
        $this->account = $account->load(['branch', 'customer']);
    }

    public function toggleLock(): void
    {
        $this->authorize('manage', $this->account);

        $this->account->update([
            'status' => $this->account->status === 'Active' ? 'Locked' : 'Active',
        ]);
        $this->account->refresh();
    }

    public function resetPassword(): void
    {
        $this->authorize('manage', $this->account);

        $temp = Str::password(12);

        $this->account->update([
            'password' => Hash::make($temp),
            'must_change_password' => true,
        ]);
        $this->generatedPassword = $temp;

        try {
            Mail::to($this->account->email)->send(new AccountCredentialsMail($this->account, $temp, false));
            $this->emailSent = true;
        } catch (\Throwable $e) {
            $this->emailSent = false;
        }
    }
};
?>

<div>
    <a href="/users" wire:navigate class="mb-4 inline-flex items-center gap-1.5 text-sm text-neutral-500 hover:text-neutral-900">
        <x-icon name="arrow-right" class="h-3.5 w-3.5 rotate-180" />
        Back to accounts
    </a>

    <div class="mb-4 flex items-start justify-between">
        <div class="flex items-center gap-3">
            <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-neutral-900 text-sm font-semibold text-white">
                {{ collect(explode(' ', $account->name))->map(fn ($p) => $p[0] ?? '')->take(2)->implode('') }}
            </div>
            <div>
                <h1 class="text-xl font-semibold text-neutral-900">{{ $account->name }}</h1>
                <p class="text-sm text-neutral-500">{{ $account->email }}</p>
            </div>
        </div>
        <span @class(['pill-success' => $account->status === 'Active', 'pill-danger' => $account->status !== 'Active'])>
            {{ $account->status }}
        </span>
    </div>

    <div class="card mb-4">
        <dl class="grid grid-cols-2 gap-4 text-sm">
            <div>
                <dt class="text-neutral-500">Role</dt>
                <dd class="text-neutral-900">{{ $account->getRoleNames()->first() }}</dd>
            </div>
            <div>
                <dt class="text-neutral-500">Phone</dt>
                <dd class="text-neutral-900">{{ $account->phone ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-neutral-500">{{ $account->customer ? 'Customer' : 'Branch' }}</dt>
                <dd class="text-neutral-900">{{ $account->customer->name ?? $account->branch->name ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-neutral-500">Last seen</dt>
                <dd class="text-neutral-900">{{ $account->last_seen_at?->diffForHumans() ?? 'Never' }}</dd>
            </div>
            <div class="col-span-2">
                <dt class="text-neutral-500">Account created</dt>
                <dd class="text-neutral-900">{{ $account->created_at->format('d M Y') }}</dd>
            </div>
        </dl>
    </div>

    @if ($generatedPassword)
        <div class="card mb-4" style="background-color: var(--color-info-50); border-color: var(--color-info-200)">
            @if ($emailSent)
                <h2 class="mb-1 text-sm font-semibold text-info-800">Password reset</h2>
                <p class="text-xs text-info-700">
                    A new temporary password was emailed to {{ $account->email }}. They'll be asked to set their own as soon as they sign in.
                </p>
            @else
                <h2 class="mb-1 text-sm font-semibold text-info-800">Password reset &mdash; email could not be sent</h2>
                <p class="mb-2 text-xs text-info-700">
                    Mail isn't set up yet, or the send failed, so relay this to {{ $account->name }} yourself. It won't be shown again.
                </p>
                <p class="rounded-[var(--radius-sm)] bg-white px-3 py-2 font-mono text-sm text-neutral-900">{{ $generatedPassword }}</p>
            @endif
        </div>
    @endif

    @can('manage', $account)
        <div class="flex gap-2">
            <button wire:click="toggleLock" wire:loading.attr="disabled" wire:target="toggleLock" class="btn-outline flex-1">
                {{ $account->status === 'Active' ? 'Lock account' : 'Unlock account' }}
            </button>
            <button wire:click="resetPassword" wire:loading.attr="disabled" wire:target="resetPassword" class="btn-primary flex-1">
                Reset password
            </button>
        </div>
    @endcan
</div>
