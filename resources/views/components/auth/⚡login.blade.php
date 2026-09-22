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
        <div class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-lg bg-primary-500 text-lg font-bold text-white">
            AEA
        </div>
        <h1 class="text-lg font-semibold text-gray-900">Service Portal</h1>
        <p class="text-sm text-gray-500">Sign in to continue</p>
    </div>

    <form wire:submit="login" class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
        <div class="mb-4">
            <label for="email" class="mb-1.5 block text-sm font-medium text-gray-700">Email</label>
            <div class="relative">
                <x-icon name="envelope" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
                <input
                    wire:model="email"
                    type="email"
                    id="email"
                    autocomplete="email"
                    class="w-full rounded-lg border border-gray-300 py-2.5 pl-10 pr-3 text-sm focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500"
                    placeholder="you@aealimited.com"
                >
            </div>
            @error('email')
                <p class="mt-1.5 flex items-center gap-1 text-xs text-primary-600">
                    <x-icon name="exclamation-circle" class="h-3.5 w-3.5" /> {{ $message }}
                </p>
            @enderror
        </div>

        <div class="mb-4">
            <label for="password" class="mb-1.5 block text-sm font-medium text-gray-700">Password</label>
            <div class="relative">
                <x-icon name="lock" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
                <input
                    wire:model="password"
                    type="password"
                    id="password"
                    autocomplete="current-password"
                    class="w-full rounded-lg border border-gray-300 py-2.5 pl-10 pr-3 text-sm focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500"
                    placeholder="••••••••"
                >
            </div>
            @error('password')
                <p class="mt-1.5 flex items-center gap-1 text-xs text-primary-600">
                    <x-icon name="exclamation-circle" class="h-3.5 w-3.5" /> {{ $message }}
                </p>
            @enderror
        </div>

        <label class="mb-5 flex items-center gap-2 text-sm text-gray-600">
            <input wire:model="remember" type="checkbox" class="rounded border-gray-300 text-primary-500 focus:ring-primary-500">
            Stay signed in
        </label>

        <button
            type="submit"
            class="flex w-full items-center justify-center gap-2 rounded-lg bg-primary-500 py-2.5 text-sm font-medium text-white transition hover:bg-primary-600"
            wire:loading.attr="disabled"
            wire:target="login"
        >
            <span wire:loading.remove wire:target="login">Sign in</span>
            <span wire:loading wire:target="login">Signing in...</span>
        </button>
    </form>
</div>