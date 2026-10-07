<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Services\IctReports;

new #[Layout('layouts.app', ['title' => 'Roles and Permissions'])] class extends Component
{
    public function mount(): void
    {
        abort_unless(auth()->user()->hasAnyRole(['ICT', 'Super Admin']), 403);
    }

    public function with(): array
    {
        [$roles, $permissions] = IctReports::roleMatrix();

        $people = [];
        foreach ($roles as $r) {
            $people[$r->name] = IctReports::people()->role($r->name)->count();
        }

        // Group "area.action" names by their area so the table reads in blocks.
        $groups = collect($permissions)->groupBy(fn ($p) => explode('.', $p)[0])->sortKeys();

        return compact('roles', 'groups', 'people');
    }
};
?>

<div class="mx-auto" style="max-width: 72rem">
    <div class="mb-5">
        <h1 class="text-xl font-semibold text-neutral-900">Roles and Permissions</h1>
        <p class="text-sm text-neutral-500">What each role can do. Read only.</p>
    </div>

    <div class="card mb-4" style="padding: 0">
        <div class="overflow-x-auto">
            <table class="table-clean" style="min-width: 760px">
                <thead>
                    <tr>
                        <th>Permission</th>
                        @foreach ($roles as $r)
                            <th class="text-center">{{ $r->name }}<br><span class="font-normal text-neutral-400">{{ $people[$r->name] }} {{ $people[$r->name] === 1 ? 'person' : 'people' }}</span></th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($groups as $area => $items)
                        <tr>
                            <td colspan="{{ $roles->count() + 1 }}" class="bg-neutral-50 text-xs font-semibold uppercase tracking-wide text-neutral-500">{{ str_replace('-', ' ', $area) }}</td>
                        </tr>
                        @foreach ($items as $p)
                            <tr wire:key="p-{{ $p }}">
                                <td class="font-mono text-xs text-neutral-900">{{ $p }}</td>
                                @foreach ($roles as $r)
                                    <td class="text-center">
                                        @if ($r->permissions->contains('name', $p))
                                            <span style="color: #15803d; font-weight: 700" aria-label="allowed">&#10003;</span>
                                        @else
                                            <span style="color: #d4d4d8" aria-label="not allowed">&ndash;</span>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <p class="text-xs text-neutral-400">Customers and Technicians only see their own records.</p>
</div>