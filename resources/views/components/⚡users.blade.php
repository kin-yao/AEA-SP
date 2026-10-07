<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Models\User;

new #[Layout('layouts.app', ['title' => 'User accounts'])] class extends Component
{
    use \App\Support\ShowsMore;

    public string $roleFilter = 'All';

    protected array $roles = ['Manager', 'Supervisor', 'Service Admin', 'Technician', 'Finance', 'ICT', 'Customer'];

    public function mount(): void
    {
        $this->authorize('viewAny', User::class);
    }

    public function setFilter(string $role): void
    {
        $this->roleFilter = $role;
    }

    public function with(): array
    {
        $query = User::with(['branch', 'customer', 'roles'])
            ->whereDoesntHave('roles', fn ($q) => $q->where('name', 'Super Admin'))
            ->orderBy('name');

        if ($this->roleFilter !== 'All') {
            $query->whereHas('roles', fn ($q) => $q->where('name', $this->roleFilter));
        }

        $total = (clone $query)->count();

        return [
            'total' => $total,
            'users' => $query->limit($this->limit)->get(),
            'roles' => $this->roles,
        ];
    }
};
?>

<div>
    <div class="mb-6 flex items-start justify-between">
        <div>
            <h1 class="text-xl font-semibold text-neutral-900">User accounts</h1>
            <p class="text-sm text-neutral-500">{{ number_format($total) }} {{ $roleFilter === 'All' ? 'total' : 'matching' }}</p>
        </div>
        @can('create', \App\Models\User::class)
            <a href="/users/create" wire:navigate class="btn-primary">
                New account
            </a>
        @endcan
    </div>

    <div class="mb-5 flex flex-wrap gap-1 border-b border-neutral-200">
        <button
            wire:click="setFilter('All')"
            @class([
                'border-b-2 px-3 py-2 text-sm font-medium transition-colors',
                'border-primary-500 text-primary-600' => $roleFilter === 'All',
                'border-transparent text-neutral-500 hover:text-neutral-900' => $roleFilter !== 'All',
            ])
        >
            All
        </button>
        @foreach ($roles as $role)
            <button
                wire:click="setFilter('{{ $role }}')"
                @class([
                    'border-b-2 px-3 py-2 text-sm font-medium transition-colors',
                    'border-primary-500 text-primary-600' => $roleFilter === $role,
                    'border-transparent text-neutral-500 hover:text-neutral-900' => $roleFilter !== $role,
                ])
            >
                {{ $role }}
            </button>
        @endforeach
    </div>

    <div class="space-y-3">
        @forelse ($users as $user)
            <a href="/users/{{ $user->id }}" wire:navigate class="card flex items-center justify-between gap-3 hover:shadow-[var(--shadow-card-hover)]">
                <div class="flex min-w-0 items-center gap-3">
                    <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-neutral-900 text-xs font-semibold text-white">
                        {{ collect(explode(' ', $user->name))->map(fn ($p) => $p[0] ?? '')->take(2)->implode('') }}
                    </div>
                    <div class="min-w-0">
                        <p class="truncate text-sm font-semibold text-neutral-900">{{ $user->name }}</p>
                        <p class="truncate text-xs text-neutral-500">{{ $user->email }}</p>
                    </div>
                </div>
                <div class="flex shrink-0 items-center gap-2">
                    <span class="text-xs text-neutral-500">{{ $user->branch->name ?? $user->customer->name ?? '—' }}</span>
                    <span class="pill-neutral">{{ $user->getRoleNames()->first() }}</span>
                    <span @class(['pill-success' => $user->status === 'Active', 'pill-danger' => $user->status !== 'Active'])>
                        {{ $user->status }}
                    </span>
                </div>
            </a>
        @empty
            <div class="card border-dashed text-center text-sm text-neutral-500">
                No accounts match this filter.
            </div>
        @endforelse
    </div>
    <x-show-more :shown="$users->count()" :total="$total" />
</div>
