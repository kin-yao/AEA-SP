<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

new #[Layout('layouts.guest', ['title' => 'Sign in - AEA Service Portal'])] class extends Component
{
    public string $email = '';
    public string $password = '';
    public bool $remember = false;

    public function login(): void
    {
        $this->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (! Auth::attempt(['email' => $this->email, 'password' => $this->password], $this->remember)) {
            throw ValidationException::withMessages([
                'email' => 'Those credentials don\'t match our records.',
            ]);
        }

        request()->session()->regenerate();

        $this->redirect('/dashboard', navigate: true);
    }
};
?>

<div>
    <div class="mb-8 text-center">
        <div class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-[var(--radius-md)] bg-primary-500 text-lg font-bold text-white">
            AEA
        </div>
        <h1 class="text-lg font-semibold text-neutral-900">Service Portal</h1>
        <p class="text-sm text-neutral-500">Sign in to continue</p>
    </div>

    <form wire:submit="login" class="card">
        <div class="mb-4">
            <label for="email" class="label">Email</label>
            <div class="relative">
                <x-icon name="envelope" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
                <input
                    wire:model="email"
                    type="email"
                    id="email"
                    autocomplete="email"
                    class="input pl-10"
                    placeholder="you@aealimited.com"
                >
            </div>
            @error('email')
                <p class="mt-1.5 flex items-center gap-1 text-xs text-critical-600">
                    <x-icon name="exclamation-circle" class="h-3.5 w-3.5" /> {{ $message }}
                </p>
            @enderror
        </div>

        <div class="mb-4">
            <label for="password" class="label">Password</label>
            <div class="relative">
                <x-icon name="lock" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
                <input
                    wire:model="password"
                    type="password"
                    id="password"
                    autocomplete="current-password"
                    class="input pl-10"
                    placeholder="••••••••"
                >
            </div>
            @error('password')
                <p class="mt-1.5 flex items-center gap-1 text-xs text-critical-600">
                    <x-icon name="exclamation-circle" class="h-3.5 w-3.5" /> {{ $message }}
                </p>
            @enderror
        </div>

        <label class="mb-5 flex items-center gap-2 text-sm text-neutral-600">
            <input wire:model="remember" type="checkbox" class="rounded border-neutral-300 text-primary-500 focus:ring-primary-500">
            Stay signed in
        </label>

        <button type="submit" class="btn-primary w-full" wire:loading.attr="disabled" wire:target="login">
            <span wire:loading.remove wire:target="login">Sign in</span>
            <span wire:loading wire:target="login">Signing in...</span>
        </button>
    </form>
</div>
