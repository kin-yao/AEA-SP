<?php

use Livewire\Component;
use Livewire\Attributes\Layout;

new #[Layout('layouts.app', ['title' => 'Overview'])] class extends Component
{
    //
};
?>

<div>
    <div class="mb-6">
        <p class="text-sm font-medium text-primary-600">{{ auth()->user()->getRoleNames()->first() }}</p>
        <h1 class="text-xl font-semibold text-gray-900">
            Good {{ now()->hour < 12 ? 'morning' : (now()->hour < 17 ? 'afternoon' : 'evening') }}, {{ explode(' ', auth()->user()->name)[0] }}
        </h1>
    </div>

    <div class="rounded-xl border border-gray-200 bg-white p-6">
        <p class="text-sm text-gray-500">
            You're signed in. This is where each role's real dashboard
            (open requests, jobs, approvals, whatever's relevant to you)
            will live, built one screen at a time from here.
        </p>
    </div>
</div>