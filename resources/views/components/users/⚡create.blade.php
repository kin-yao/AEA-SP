<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Models\User;
use App\Models\Branch;
use App\Models\Customer;
use App\Mail\AccountCredentialsMail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

new #[Layout('layouts.app', ['title' => 'New account'])] class extends Component
{
    public string $name = '';
    public string $email = '';
    public string $phone = '';
    public string $role = '';
    public ?int $branch_id = null;
    public ?int $customer_id = null;

    public ?string $generatedPassword = null;
    public ?User $created = null;
    public ?bool $emailSent = null;

    public function mount(): void
    {
        $this->authorize('create', User::class);

        // Super Admin's only job here is to create the ICT account, so
        // there is nothing for it to pick.
        if (auth()->user()->hasRole('Super Admin')) {
            $this->role = 'ICT';
        }
    }

    private function assignableRoles(): array
    {
        return auth()->user()->hasRole('Super Admin')
            ? ['ICT']
            : ['Manager', 'Supervisor', 'Service Admin', 'Technician', 'Finance', 'ICT', 'Customer'];
    }

    public function with(): array
    {
        return [
            'roles' => $this->assignableRoles(),
            'branches' => Branch::orderBy('name')->get(),
            'customers' => Customer::orderBy('name')->get(),
        ];
    }

    public function save(): void
    {
        $this->authorize('create', User::class);

        $allowedRoles = $this->assignableRoles();

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'phone' => ['nullable', 'string', 'max:30'],
            'role' => ['required', Rule::in($allowedRoles)],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'customer_id' => [Rule::requiredIf($this->role === 'Customer'), 'nullable', 'exists:customers,id'],
        ]);

        $temp = Str::password(12);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?: null,
            'password' => Hash::make($temp),
            'branch_id' => $validated['role'] === 'Customer' ? null : $validated['branch_id'],
            'customer_id' => $validated['role'] === 'Customer' ? $validated['customer_id'] : null,
            'status' => 'Active',
            'must_change_password' => true,
        ]);

        $user->assignRole($validated['role']);

        $this->created = $user;
        $this->generatedPassword = $temp;

        try {
            Mail::to($user->email)->send(new AccountCredentialsMail($user, $temp, true));
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

    <div class="mb-6">
        <h1 class="text-xl font-semibold text-neutral-900">New account</h1>
        <p class="text-sm text-neutral-500">
            @if (auth()->user()->hasRole('Super Admin'))
                Super Admin can only create the ICT account.
            @else
                Create an account for any role except Super Admin.
            @endif
        </p>
    </div>

    @if ($created)
        <div class="card mb-4" style="background-color: var(--color-info-50); border-color: var(--color-info-200)">
            @if ($emailSent)
                <h2 class="mb-1 text-sm font-semibold text-info-800">Account created</h2>
                <p class="text-xs text-info-700">
                    Login details were emailed to {{ $created->email }}. They'll be asked to set their own password the first time they sign in.
                </p>
            @else
                <h2 class="mb-1 text-sm font-semibold text-info-800">Account created &mdash; email could not be sent</h2>
                <p class="mb-2 text-xs text-info-700">
                    Mail isn't set up yet, or the send failed, so relay this password to {{ $created->name }} yourself. It won't be shown again.
                </p>
                <p class="rounded-[var(--radius-sm)] bg-white px-3 py-2 font-mono text-sm text-neutral-900">{{ $generatedPassword }}</p>
            @endif
            <div class="mt-3 flex gap-2">
                <a href="/users/{{ $created->id }}" wire:navigate class="btn-outline flex-1 text-center">View account</a>
                <a href="/users/create" wire:navigate class="btn-primary flex-1 text-center">Create another</a>
            </div>
        </div>
    @else
        <form wire:submit="save" class="card space-y-4">
            <div>
                <label class="label">Full name</label>
                <input type="text" wire:model="name" class="input">
                @error('name') <p class="mt-1 text-xs text-critical-700">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="label">Email</label>
                <input type="email" wire:model="email" class="input">
                @error('email') <p class="mt-1 text-xs text-critical-700">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="label">Phone (optional)</label>
                <input type="text" wire:model="phone" class="input">
                @error('phone') <p class="mt-1 text-xs text-critical-700">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="label">Role</label>
                @if (auth()->user()->hasRole('Super Admin'))
                    <input type="text" value="ICT" disabled class="input bg-neutral-50 text-neutral-500">
                @else
                    <select wire:model.live="role" class="input">
                        <option value="">Select a role&hellip;</option>
                        @foreach ($roles as $r)
                            <option value="{{ $r }}">{{ $r }}</option>
                        @endforeach
                    </select>
                @endif
                @error('role') <p class="mt-1 text-xs text-critical-700">{{ $message }}</p> @enderror
            </div>

            @if ($role === 'Customer')
                <div>
                    <label class="label">Customer</label>
                    <select wire:model="customer_id" class="input">
                        <option value="">Select a customer&hellip;</option>
                        @foreach ($customers as $customer)
                            <option value="{{ $customer->id }}">{{ $customer->name }}</option>
                        @endforeach
                    </select>
                    @error('customer_id') <p class="mt-1 text-xs text-critical-700">{{ $message }}</p> @enderror
                </div>
            @elseif ($role !== '')
                <div>
                    <label class="label">Branch (optional)</label>
                    <select wire:model="branch_id" class="input">
                        <option value="">No branch</option>
                        @foreach ($branches as $branch)
                            <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                        @endforeach
                    </select>
                    @error('branch_id') <p class="mt-1 text-xs text-critical-700">{{ $message }}</p> @enderror
                </div>
            @endif

            <button type="submit" wire:loading.attr="disabled" wire:target="save" class="btn-primary w-full">
                Create account
            </button>
        </form>
    @endif
</div>
