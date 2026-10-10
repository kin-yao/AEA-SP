<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use App\Support\Rules;

new #[Layout('layouts.app', ['title' => 'My profile'])] class extends Component
{
    public string $name = '';
    public string $phone = '';
    public bool $emailNotifications = true;

    public string $currentPassword = '';
    public string $newPassword = '';
    public string $newPassword_confirmation = '';

    public ?string $savedNote = null;
    public ?string $passwordNote = null;

    public function mount(): void
    {
        $u = auth()->user();
        $this->name = $u->name;
        $this->phone = (string) $u->phone;
        $this->emailNotifications = (bool) $u->email_notifications;
    }

    public function saveDetails(): void
    {
        $this->savedNote = null;

        $v = $this->validate([
            'name' => Rules::person(true),
            'phone' => Rules::phone(false),
            'emailNotifications' => ['boolean'],
        ], [], ['name' => 'name', 'phone' => 'phone number']);

        auth()->user()->update([
            'name' => $v['name'],
            'phone' => $v['phone'] ?: null,
            'email_notifications' => $v['emailNotifications'],
        ]);

        $this->savedNote = 'Your details are saved.';
    }

    public function changePassword(): void
    {
        $this->passwordNote = null;
        $user = auth()->user();
        $key = 'profile-password:'.$user->id;

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('currentPassword', 'Too many tries. Wait a few minutes and try again.');

            return;
        }

        $this->validate([
            'currentPassword' => ['required', 'string'],
            'newPassword' => ['required', 'confirmed:newPassword_confirmation', 'different:currentPassword', Password::min((int) setting('password_min'))],
        ], [
            'newPassword.different' => 'Choose a password you have not used just now.',
            'newPassword.confirmed' => 'The two new passwords do not match.',
        ], ['newPassword' => 'new password', 'currentPassword' => 'current password']);

        if (! Hash::check($this->currentPassword, $user->password)) {
            RateLimiter::hit($key, 300);
            $this->addError('currentPassword', 'That is not your current password.');

            return;
        }

        RateLimiter::clear($key);
        $user->update(['password' => $this->newPassword, 'must_change_password' => false]);

        $this->reset(['currentPassword', 'newPassword', 'newPassword_confirmation']);
        $this->passwordNote = 'Your password has been changed.';
    }

    public function with(): array
    {
        $u = auth()->user();

        return [
            'u' => $u,
            'role' => $u->getRoleNames()->first(),
            'initials' => collect(explode(' ', $u->name))->map(fn ($p) => $p[0] ?? '')->take(2)->implode(''),
            'branch' => $u->branch?->name,
            'customerName' => $u->customer?->name,
        ];
    }
};
?>

<div style="max-width: 44rem">
    <h1 class="mb-6 text-xl font-semibold text-neutral-900">My profile</h1>

    <div class="card mb-4" style="display: flex; align-items: center; gap: 1rem">
        <div style="display: flex; height: 3.5rem; width: 3.5rem; flex-shrink: 0; align-items: center; justify-content: center; border-radius: 999px; background: var(--color-primary-500, #e31e24); color: #fff; font-size: 1.1rem; font-weight: 800">{{ $initials }}</div>
        <div style="min-width: 0">
            <p class="truncate text-base font-semibold text-neutral-900">{{ $u->name }}</p>
            <p class="truncate text-sm text-neutral-500">{{ $role }}@if ($branch) &middot; {{ $branch }}@endif @if ($customerName) &middot; {{ $customerName }}@endif</p>
            <p class="truncate text-sm text-neutral-500">{{ $u->email }}</p>
        </div>
    </div>

    <form wire:submit="saveDetails" class="card mb-4">
        <h2 class="mb-3 text-sm font-semibold text-neutral-900">Your details</h2>
        <div class="mb-3 grid grid-cols-2 gap-4">
            <div>
                <label class="label">Full name</label>
                <input wire:model="name" type="text" class="input">
                @error('name') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="label">Phone</label>
                <input wire:model="phone" type="text" inputmode="tel" class="input" placeholder="+254712345678">
                @error('phone') <p class="field-error">{{ $message }}</p> @enderror
            </div>
        </div>
        <p class="mb-3 text-xs text-neutral-400">Your email address ({{ $u->email }}) is your sign-in name. Ask the ICT team if it needs to change.</p>

        <label style="display: flex; align-items: center; gap: 0.6rem; font-size: 0.875rem; color: #3f3f46; cursor: pointer">
            <input type="checkbox" wire:model="emailNotifications" style="width: 1.1rem; height: 1.1rem; accent-color: #e31e24">
            Also email me my notifications
        </label>
        <p class="mb-4 mt-1 text-xs text-neutral-400">Notifications always show in the bell. This only controls the email copy.</p>

        <div class="flex items-center gap-3">
            <button type="submit" class="btn-primary" wire:loading.attr="disabled" wire:target="saveDetails">Save details</button>
            @if ($savedNote)<span class="text-sm font-medium text-fresh-700">{{ $savedNote }}</span>@endif
        </div>
    </form>

    <form wire:submit="changePassword" class="card mb-4" id="password">
        <h2 class="mb-3 text-sm font-semibold text-neutral-900">Change password</h2>
        <div class="mb-3">
            <label class="label">Current password</label>
            <input wire:model="currentPassword" type="password" autocomplete="current-password" class="input">
            @error('currentPassword') <p class="field-error">{{ $message }}</p> @enderror
        </div>
        <div class="mb-3 grid grid-cols-2 gap-4">
            <div>
                <label class="label">New password</label>
                <input wire:model="newPassword" type="password" autocomplete="new-password" class="input">
                @error('newPassword') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="label">Confirm new password</label>
                <input wire:model="newPassword_confirmation" type="password" autocomplete="new-password" class="input">
            </div>
        </div>
        <p class="mb-3 text-xs text-neutral-400">At least {{ setting('password_min') }} characters.</p>
        <div class="flex items-center gap-3">
            <button type="submit" class="btn-primary" wire:loading.attr="disabled" wire:target="changePassword">Change password</button>
            @if ($passwordNote)<span class="text-sm font-medium text-fresh-700">{{ $passwordNote }}</span>@endif
        </div>
    </form>

    <form action="/logout" method="POST" class="card">
        @csrf
        <div class="flex items-center justify-between">
            <div>
                <h2 class="text-sm font-semibold text-neutral-900">Sign out</h2>
                <p class="text-xs text-neutral-500">End your session on this device.</p>
            </div>
            <button type="submit" class="btn-outline">Sign out</button>
        </div>
    </form>
</div>
