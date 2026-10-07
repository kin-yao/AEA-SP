<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

new #[Layout('layouts.auth-split', ['title' => 'Sign in - AEA Service Operations Hub'])] class extends Component
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
                'email' => 'Those credentials do not match our records.',
            ]);
        }

        if (Auth::user()->status !== 'Active') {
            Auth::logout();

            throw ValidationException::withMessages([
                'email' => 'This account has been locked. Contact ICT to have it unlocked.',
            ]);
        }

        request()->session()->regenerate();

        if (Auth::user()->must_change_password) {
            $this->redirect('/change-password', navigate: true);
            return;
        }

        $this->redirect('/dashboard', navigate: true);
    }
};
?>

<div>
    <h1 style="font-size: 2rem; font-weight: 800; letter-spacing: -0.02em; line-height: 1.1; color: #fff">Welcome back</h1>
    <p style="margin-top: 0.4rem; font-size: 0.95rem; color: rgba(255, 255, 255, 0.82)">Sign in to the AEA Service Operations Hub</p>

    <form wire:submit="login" style="margin-top: 1.75rem">
        <div style="margin-bottom: 1.1rem">
            <label for="email" style="display: block; margin-bottom: 0.35rem; font-size: 0.8rem; font-weight: 600; color: #fff">Email</label>
            <div class="relative">
                <x-icon name="envelope" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
                <input
                    wire:model="email"
                    type="email"
                    id="email"
                    autocomplete="email"
                    class="input pl-10"
                    style="background: #fff; border-color: #fff; color: #18181b; font-size: 16px; height: 2.9rem; border-radius: 0.65rem; box-shadow: 0 6px 18px rgba(0, 0, 0, 0.15)"
                    placeholder="you@aealimited.com"
                >
            </div>
            @error('email')
                <p class="field-error">
                     {{ $message }}
                </p>
            @enderror
        </div>

        <div style="margin-bottom: 1rem" x-data="{ show: false }">
            <label for="password" style="display: block; margin-bottom: 0.35rem; font-size: 0.8rem; font-weight: 600; color: #fff">Password</label>
            <div class="relative">
                <x-icon name="lock" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
                <input
                    wire:model="password"
                    x-bind:type="show ? 'text' : 'password'"
                    type="password"
                    id="password"
                    autocomplete="current-password"
                    class="input pl-10"
                    style="background: #fff; border-color: #fff; color: #18181b; font-size: 16px; height: 2.9rem; border-radius: 0.65rem; padding-right: 2.75rem; box-shadow: 0 6px 18px rgba(0, 0, 0, 0.15)"
                    placeholder="Your password"
                >
                <button type="button" x-on:click="show = ! show" aria-label="Show or hide password" class="absolute right-3 top-1/2 -translate-y-1/2 text-neutral-400 hover:text-neutral-700" style="background: none; border: 0; cursor: pointer">
                    <span x-show="! show"><x-icon name="eye" class="h-4 w-4" /></span>
                    <span x-show="show" x-cloak><x-icon name="eye-slash" class="h-4 w-4" /></span>
                </button>
            </div>
            @error('password')
                <p class="field-error">
                     {{ $message }}
                </p>
            @enderror
        </div>

        <label style="margin-bottom: 1.4rem; display: flex; align-items: center; gap: 0.5rem; font-size: 0.875rem; color: rgba(255, 255, 255, 0.92); cursor: pointer">
            <input wire:model="remember" type="checkbox" style="width: 1rem; height: 1rem; accent-color: #18181b">
            Stay signed in
        </label>

        <button type="submit" wire:loading.attr="disabled" wire:target="login"
            style="width: 100%; height: 3rem; border: 0; border-radius: 0.65rem; background: #18181b; color: #fff; font-size: 1rem; font-weight: 700; letter-spacing: 0.02em; cursor: pointer; box-shadow: 0 10px 24px rgba(0, 0, 0, 0.3)">
            <span wire:loading.remove wire:target="login">Sign in</span>
            <span wire:loading wire:target="login">Signing in...</span>
        </button>
    </form>

    <p style="margin-top: 1.5rem; text-align: center; font-size: 0.875rem; color: rgba(255, 255, 255, 0.85)">
        New customer? <a href="/register" wire:navigate style="font-weight: 700; color: #fff; text-decoration: underline">Create an account</a>
    </p>
</div>
