<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

new #[Layout('layouts.guest', ['title' => 'Verify your email - AEA Service Portal'])] class extends Component
{
    public bool $sent = false;

    public function mount(): void
    {
        if (auth()->user()->hasVerifiedEmail()) {
            $this->redirect('/dashboard', navigate: true);
        }
    }

    public function resend(): void
    {
        try {
            auth()->user()->sendEmailVerificationNotification();
        } catch (\Throwable $e) {
            Log::warning('Verification email failed to send', [
                'to' => auth()->user()->email,
                'error' => $e->getMessage(),
            ]);
        }

        $this->sent = true;
    }

    public function logout(): void
    {
        Auth::logout();

        request()->session()->invalidate();
        request()->session()->regenerateToken();

        $this->redirect('/login', navigate: true);
    }
};
?>

<div>
    <div class="mb-8 text-center">
        <div class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-[var(--radius-md)] bg-primary-500 text-lg font-bold text-white">
            AEA
        </div>
        <h1 class="text-lg font-semibold text-neutral-900">Verify your email</h1>
        <p class="text-sm text-neutral-500">We sent a confirmation link to {{ auth()->user()->email }}</p>
    </div>

    <div class="card">
        @if ($sent)
            <p class="mb-4 text-sm text-fresh-700">A new verification link has been sent.</p>
        @else
            <p class="mb-4 text-sm text-neutral-600">
                Click the link in that email to confirm your address. You can keep using the portal in the meantime.
            </p>
        @endif

        <div class="flex gap-2">
            <button type="button" wire:click="resend" class="btn-outline flex-1" wire:loading.attr="disabled" wire:target="resend">
                Resend email
            </button>
            <a href="/dashboard" wire:navigate class="btn-primary flex-1 text-center">Back to dashboard</a>
        </div>

        <button type="button" wire:click="logout" class="mt-4 w-full text-center text-xs text-neutral-400 hover:text-neutral-600">
            Sign out
        </button>
    </div>
</div>
