<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

new #[Layout('layouts.guest', ['title' => 'Set a new password - AEA Service Operations Hub'])] class extends Component
{
    public string $password = '';
    public string $password_confirmation = '';

    public function mount(): void
    {
        // Nothing to force once it's already been done, send them on.
        if (! auth()->user()->must_change_password) {
            $this->redirect('/dashboard', navigate: true);
        }
    }

    public function save(): void
    {
        $this->validate([
            'password' => ['required', 'confirmed', Password::min((int) setting('password_min'))],
        ]);

        auth()->user()->update([
            'password' => Hash::make($this->password),
            'must_change_password' => false,
        ]);

        $this->redirect('/dashboard', navigate: true);
    }
};
?>

<div>
    <div class="mb-8 text-center">
        <div class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-[var(--radius-md)] bg-primary-500 text-lg font-bold text-white">
            AEA
        </div>
        <h1 class="text-lg font-semibold text-neutral-900">Set a new password</h1>
        <p class="text-sm text-neutral-500">Choose a password only you know.</p>
    </div>

    <form wire:submit="save" class="card">
        <div class="mb-4">
            <label for="password" class="label">New password</label>
            <div class="relative">
                <x-icon name="lock" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
                <input
                    wire:model="password"
                    type="password"
                    id="password"
                    autocomplete="new-password"
                    class="input pl-10"
                    placeholder="••••••••"
                >
            </div>
            @error('password')
                <p class="mt-1.5 flex items-center gap-1 text-xs text-critical-700">
                    <x-icon name="exclamation-circle" class="h-3.5 w-3.5" /> {{ $message }}
                </p>
            @enderror
        </div>

        <div class="mb-5">
            <label for="password_confirmation" class="label">Confirm password</label>
            <div class="relative">
                <x-icon name="lock" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
                <input
                    wire:model="password_confirmation"
                    type="password"
                    id="password_confirmation"
                    autocomplete="new-password"
                    class="input pl-10"
                    placeholder="••••••••"
                >
            </div>
        </div>

        <button type="submit" class="btn-primary w-full" wire:loading.attr="disabled" wire:target="save">
            <span wire:loading.remove wire:target="save">Set password</span>
            <span wire:loading wire:target="save">Saving...</span>
        </button>
    </form>
</div>
