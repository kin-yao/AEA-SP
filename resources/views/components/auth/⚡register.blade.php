<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use App\Models\Customer;
use App\Models\Branch;
use App\Models\User;

new #[Layout('layouts.guest', ['title' => 'Create your account - AEA Service Portal'])] class extends Component
{
    public string $companyName = '';
    public string $branchId = '';
    public string $contactName = '';
    public string $email = '';
    public string $phone = '';
    public string $password = '';
    public string $password_confirmation = '';

    public function with(): array
    {
        return [
            'branches' => Branch::orderBy('name')->get(),
        ];
    }

    public function register(): void
    {
        $validated = $this->validate([
            'companyName' => ['required', 'string', 'max:255'],
            'branchId' => ['required', 'exists:branches,id'],
            'contactName' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:255'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $reference = 'CUS-'.str_pad((string) (Customer::max('id') + 1), 4, '0', STR_PAD_LEFT);

        $customer = Customer::create([
            'reference' => $reference,
            'name' => $validated['companyName'],
            'branch_id' => $validated['branchId'],
            'main_contact_name' => $validated['contactName'],
            'main_contact_email' => $validated['email'],
            'main_contact_phone' => $validated['phone'] ?: null,
        ]);

        $user = User::create([
            'name' => $validated['contactName'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?: null,
            'password' => Hash::make($validated['password']),
            'customer_id' => $customer->id,
            'status' => 'Active',
            // They chose this password themselves, so there's nothing to
            // force a change on, unlike accounts ICT creates for staff.
            'must_change_password' => false,
        ]);

        $user->assignRole('Customer');

        Auth::login($user);

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
        <h1 class="text-lg font-semibold text-neutral-900">Create your account</h1>
        <p class="text-sm text-neutral-500">For customers requesting equipment service</p>
    </div>

    <form wire:submit="register" class="card">
        <div class="mb-4">
            <label for="companyName" class="label">Company name</label>
            <input wire:model="companyName" type="text" id="companyName" class="input">
            @error('companyName') <p class="mt-1.5 text-xs text-critical-600">{{ $message }}</p> @enderror
        </div>

        <div class="mb-4">
            <label for="branchId" class="label">Nearest branch</label>
            <select wire:model="branchId" id="branchId" class="input">
                <option value="">Select a branch</option>
                @foreach ($branches as $branch)
                    <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                @endforeach
            </select>
            @error('branchId') <p class="mt-1.5 text-xs text-critical-600">{{ $message }}</p> @enderror
        </div>

        <div class="mb-4">
            <label for="contactName" class="label">Your name</label>
            <input wire:model="contactName" type="text" id="contactName" class="input">
            @error('contactName') <p class="mt-1.5 text-xs text-critical-600">{{ $message }}</p> @enderror
        </div>

        <div class="mb-4">
            <label for="email" class="label">Email</label>
            <input wire:model="email" type="email" id="email" autocomplete="email" class="input">
            @error('email') <p class="mt-1.5 text-xs text-critical-600">{{ $message }}</p> @enderror
        </div>

        <div class="mb-4">
            <label for="phone" class="label">Phone (optional)</label>
            <input wire:model="phone" type="text" id="phone" class="input">
        </div>

        <div class="mb-4">
            <label for="password" class="label">Password</label>
            <input wire:model="password" type="password" id="password" autocomplete="new-password" class="input">
            @error('password') <p class="mt-1.5 text-xs text-critical-600">{{ $message }}</p> @enderror
        </div>

        <div class="mb-5">
            <label for="password_confirmation" class="label">Confirm password</label>
            <input wire:model="password_confirmation" type="password" id="password_confirmation" autocomplete="new-password" class="input">
        </div>

        <button type="submit" class="btn-primary w-full" wire:loading.attr="disabled" wire:target="register">
            <span wire:loading.remove wire:target="register">Create account</span>
            <span wire:loading wire:target="register">Creating...</span>
        </button>

        <p class="mt-4 text-center text-xs text-neutral-400">
            Company details like KRA PIN and any service contract can be added later by AEA's team.
        </p>
    </form>

    <p class="mt-5 text-center text-sm text-neutral-500">
        Already have an account? <a href="/login" wire:navigate class="font-semibold text-primary-600 hover:text-primary-700">Sign in</a>
    </p>
</div>
