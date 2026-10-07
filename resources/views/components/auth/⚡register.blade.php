<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rules\Password;
use App\Models\Customer;
use App\Models\CustomerSite;
use App\Models\Branch;
use App\Models\User;

new #[Layout('layouts.guest', ['title' => 'Create your account - AEA Service Operations Hub'])] class extends Component
{
    public string $companyName = '';
    public string $branchId = '';
    public string $kraPin = '';
    public string $poBox = '';
    public string $siteAddress = '';
    public string $siteLat = '';
    public string $siteLng = '';
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
            'companyName' => \App\Support\Rules::company(),
            'branchId' => ['required', 'exists:branches,id'],
            'kraPin' => \App\Support\Rules::kraPin(),
            'poBox' => \App\Support\Rules::text(60),
            'siteAddress' => ['nullable', 'string', 'max:500'],
            'siteLat' => ['nullable', 'numeric', 'between:-90,90'],
            'siteLng' => ['nullable', 'numeric', 'between:-180,180'],
            'contactName' => \App\Support\Rules::person(),
            'email' => array_merge(\App\Support\Rules::email(), ['unique:users,email']),
            'phone' => \App\Support\Rules::phone(),
            'password' => ['required', 'confirmed', Password::min((int) setting('password_min'))],
        ]);

        $reference = \App\Models\ReferenceSeries::next('customer');

        $customer = Customer::create([
            'reference' => $reference,
            'name' => $validated['companyName'],
            'branch_id' => $validated['branchId'],
            'kra_pin' => $validated['kraPin'] ?: null,
            'po_box' => $validated['poBox'] ?: null,
            'main_contact_name' => $validated['contactName'],
            'main_contact_email' => $validated['email'],
            'main_contact_phone' => $validated['phone'] ?: null,
            // Deliberately not customer-set: has_active_contract and
            // balance_minor stay at their defaults (false / 0). Whether
            // this company is on a contract, and their balance, is an
            // AEA business decision, not something a signup form grants.
        ]);

        if ($validated['siteLat'] !== '' || trim($validated['siteAddress'] ?? '') !== '') {
            CustomerSite::create([
                'customer_id' => $customer->id,
                'name' => 'Main location',
                'address' => trim($validated['siteAddress'] ?? '') ?: null,
                'lat' => $validated['siteLat'] ?: null,
                'lng' => $validated['siteLng'] ?: null,
            ]);
        }

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

        // Never let a mail problem block an account that was already
        // created successfully, same rule as WorkflowNotifier elsewhere.
        try {
            $user->sendEmailVerificationNotification();
        } catch (\Throwable $e) {
            Log::warning('Verification email failed to send', [
                'to' => $user->email,
                'error' => $e->getMessage(),
            ]);
        }

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
    </div>

    <form wire:submit="register" class="card">
        <div class="mb-4">
            <label for="companyName" class="label">Company name</label>
            <input wire:model="companyName" type="text" id="companyName" class="input">
            @error('companyName') <p class="field-error">{{ $message }}</p> @enderror
        </div>

        <div class="mb-4">
            <label for="branchId" class="label">Nearest branch</label>
            <select wire:model="branchId" id="branchId" class="input">
                <option value="">Select a branch</option>
                @foreach ($branches as $branch)
                    <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                @endforeach
            </select>
            @error('branchId') <p class="field-error">{{ $message }}</p> @enderror
        </div>

        <div class="mb-4 grid grid-cols-2 gap-4">
            <div>
                <label for="kraPin" class="label">KRA PIN (optional)</label>
                <input wire:model="kraPin" type="text" id="kraPin" class="input">
                @error('kraPin') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="poBox" class="label">P.O. Box (optional)</label>
                <input wire:model="poBox" type="text" id="poBox" class="input">
                @error('poBox') <p class="field-error">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="mb-4">
            <label for="siteAddress" class="label">Location (optional)</label>
            <input wire:model="siteAddress" type="text" id="siteAddress" class="input" placeholder="Address or landmark">
            @error('siteAddress') <p class="field-error">{{ $message }}</p> @enderror
            <div class="mt-2">
                <x-location-picker lat="siteLat" lng="siteLng" />
            </div>
        </div>

        <div class="mb-4">
            <label for="contactName" class="label">Your name</label>
            <input wire:model="contactName" type="text" id="contactName" class="input">
            @error('contactName') <p class="field-error">{{ $message }}</p> @enderror
        </div>

        <div class="mb-4">
            <label for="email" class="label">Email</label>
            <input wire:model="email" type="email" id="email" autocomplete="email" class="input">
            @error('email') <p class="field-error">{{ $message }}</p> @enderror
        </div>

        <div class="mb-4">
            <label for="phone" class="label">Phone (optional)</label>
            <input wire:model="phone" type="text" id="phone" class="input">
            @error('phone') <p class="field-error">{{ $message }}</p> @enderror
        </div>

        <div class="mb-4">
            <label for="password" class="label">Password</label>
            <input wire:model="password" type="password" id="password" autocomplete="new-password" class="input">
            @error('password') <p class="field-error">{{ $message }}</p> @enderror
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
            Any service contract and billing setup is arranged separately with AEA's team.
        </p>
    </form>

    <p class="mt-5 text-center text-sm text-neutral-500">
        Already have an account? <a href="/login" wire:navigate class="font-semibold text-primary-600 hover:text-primary-700">Sign in</a>
    </p>
</div>
