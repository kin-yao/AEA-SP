<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Models\User;
use App\Services\IctReports;

new #[Layout('layouts.app', ['title' => 'Security'])] class extends Component
{
    public function mount(): void
    {
        abort_unless(auth()->user()->hasAnyRole(['ICT', 'Super Admin']), 403);
    }

    public function unlock(int $id): void
    {
        $account = User::findOrFail($id);
        $this->authorize('manage', $account);

        $account->update(['status' => 'Active']);
    }

    public function with(): array
    {
        $viewer = auth()->user();

        return [
            'c' => IctReports::counts($viewer),
            'signIns' => IctReports::perDay('login', 7, $viewer, '#15803d'),
            'failed' => IctReports::perDay('login_failed', 7, $viewer, '#d62828'),
            'failedByEmail' => IctReports::failedByEmail($viewer),
            'locked' => IctReports::people()->with('roles')->where('status', '!=', 'Active')->orderBy('name')->get(),
            'tempPassword' => IctReports::people()->with('roles')->where('must_change_password', true)->orderBy('name')->get(),
            'unverified' => IctReports::people()->with('roles')->whereNull('email_verified_at')->orderBy('name')->get(),
        ];
    }
};
?>

<div class="mx-auto" style="max-width: 72rem">
    <div class="mb-5">
        <h1 class="text-xl font-semibold text-neutral-900">Security</h1>
        <p class="text-sm text-neutral-500">Who is locked out, who keeps failing to sign in, and which accounts are not fully set up.</p>
    </div>

    <div class="grid gap-3" style="grid-template-columns: repeat(auto-fit, minmax(135px, 1fr));">
        <x-dash.kpi label="Locked accounts" tone="warn" :value="$c['locked']" />
        <x-dash.kpi label="Failed sign-ins today" tone="bad" :value="$c['failedToday']" />
        <x-dash.kpi label="Failed, last 7 days" tone="info" :value="$c['failedWeek']" />
        <x-dash.kpi label="Temporary passwords" tone="warn" :value="$c['tempPassword']" />
        <x-dash.kpi label="Unverified emails" tone="warn" :value="$c['unverified']" />
    </div>
    <p class="mb-6 mt-2 text-xs text-neutral-400">Sign-in history starts from the day this feature went live.</p>

    <div class="mb-4 flex flex-wrap gap-4">
        <div class="card" style="flex: 1 1 340px; min-width: 0">
            <h3 class="mb-1 text-sm font-semibold text-neutral-900">Successful sign-ins</h3>
            <p class="mb-4 text-xs text-neutral-400">Last 7 days</p>
            <x-column-chart :data="$signIns" :height="170" />
        </div>
        <div class="card" style="flex: 1 1 340px; min-width: 0">
            <h3 class="mb-1 text-sm font-semibold text-neutral-900">Failed sign-ins</h3>
            <p class="mb-4 text-xs text-neutral-400">Last 7 days</p>
            <x-column-chart :data="$failed" :height="170" />
        </div>
    </div>

    <div class="card mb-4" style="padding: 0">
        <h3 class="text-sm font-semibold text-neutral-900" style="padding: 1.25rem 1.25rem 0">Failed sign-ins, last 24 hours</h3>
        <p class="px-5 text-xs text-neutral-400">Grouped by the email that was tried. Many failures on one email can mean someone is guessing the password.</p>
        <div class="mt-3 overflow-x-auto">
            <table class="table-clean" style="min-width: 560px">
                <thead><tr><th>Email tried</th><th>Attempts</th><th>Last attempt</th><th>From</th><th>Account</th></tr></thead>
                <tbody>
                    @forelse ($failedByEmail as $f)
                        <tr wire:key="fe-{{ $loop->index }}">
                            <td class="font-mono text-xs">{{ $f['email'] }}</td>
                            <td><span @class(['pill-danger' => $f['count'] >= 5, 'pill-amber' => $f['count'] >= 3 && $f['count'] < 5, 'pill-neutral' => $f['count'] < 3])>{{ $f['count'] }}</span></td>
                            <td class="whitespace-nowrap">{{ $f['last']->diffForHumans() }}</td>
                            <td class="font-mono text-xs">{{ $f['ip'] }}</td>
                            <td>{{ $f['known'] ? 'Exists' : 'No such account' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-neutral-500">No failed sign-ins in the last 24 hours.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="card mb-4" style="padding: 0">
        <h3 class="text-sm font-semibold text-neutral-900" style="padding: 1.25rem 1.25rem 0">Locked accounts</h3>
        <div class="mt-3 overflow-x-auto">
            <table class="table-clean" style="min-width: 520px">
                <thead><tr><th>Name</th><th>Role</th><th>Email</th><th></th></tr></thead>
                <tbody>
                    @forelse ($locked as $u)
                        <tr wire:key="lk-{{ $u->id }}">
                            <td class="font-semibold text-neutral-900"><a href="/users/{{ $u->id }}" wire:navigate class="hover:underline">{{ $u->name }}</a></td>
                            <td>{{ $u->roles->first()?->name }}</td>
                            <td class="text-xs">{{ $u->email }}</td>
                            <td class="text-right">
                                @can('manage', $u)
                                    <button type="button" wire:click="unlock({{ $u->id }})" wire:confirm="Unlock {{ $u->name }}?" class="btn-outline" style="padding: 0.3rem 0.75rem">Unlock</button>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-center text-neutral-500">No accounts are locked.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mb-4 flex flex-wrap gap-4">
        <div class="card" style="flex: 1 1 340px; min-width: 0; padding: 0">
            <h3 class="text-sm font-semibold text-neutral-900" style="padding: 1.25rem 1.25rem 0">Still on a temporary password</h3>
            <p class="px-5 text-xs text-neutral-400">They have not chosen their own password yet.</p>
            <div class="mt-3 overflow-x-auto">
                <table class="table-clean">
                    <thead><tr><th>Name</th><th>Role</th></tr></thead>
                    <tbody>
                        @forelse ($tempPassword as $u)
                            <tr wire:key="tp-{{ $u->id }}">
                                <td><a href="/users/{{ $u->id }}" wire:navigate class="font-semibold text-neutral-900 hover:underline">{{ $u->name }}</a></td>
                                <td>{{ $u->roles->first()?->name }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="2" class="text-center text-neutral-500">Everyone has set their own password.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card" style="flex: 1 1 340px; min-width: 0; padding: 0">
            <h3 class="text-sm font-semibold text-neutral-900" style="padding: 1.25rem 1.25rem 0">Email not verified</h3>
            <p class="px-5 text-xs text-neutral-400">Password reset emails may not reach these people.</p>
            <div class="mt-3 overflow-x-auto">
                <table class="table-clean">
                    <thead><tr><th>Name</th><th>Role</th></tr></thead>
                    <tbody>
                        @forelse ($unverified as $u)
                            <tr wire:key="uv-{{ $u->id }}">
                                <td><a href="/users/{{ $u->id }}" wire:navigate class="font-semibold text-neutral-900 hover:underline">{{ $u->name }}</a></td>
                                <td>{{ $u->roles->first()?->name }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="2" class="text-center text-neutral-500">All emails are verified.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>