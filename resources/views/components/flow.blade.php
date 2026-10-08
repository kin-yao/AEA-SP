@props(['request' => null, 'quotation' => null, 'here' => 'request'])
@php
    $user = auth()->user();
    $quotation = $quotation ?? $request?->quotation;
    $request = $request ?? $quotation?->sourceRequest;
    $job = $quotation?->workOrder ?? $request?->workOrder;
    $declined = $request?->status === 'Declined';
    $chargeable = $quotation || ($request && $request->cover === 'Chargeable');

    if ($chargeable) {
        $labels = ['Request', 'Quotation', 'Approved', 'LPO filed', 'Job'];
        $qs = $quotation?->status;
        $flags = [
            true,
            (bool) $quotation,
            in_array($qs, ['Approved', 'Accepted', 'Converted'], true),
            in_array($qs, ['Accepted', 'Converted'], true),
            (bool) $job,
        ];
    } else {
        $labels = ['Request', 'Technician', 'Job'];
        $flags = [true, in_array($request?->status, ['Assigned', 'Converted'], true) || (bool) $job, (bool) $job];
    }
    $last = 0;
    foreach ($flags as $i => $f) { if ($f) { $last = $i; } }
    $cur = $last + 1;
    $n = count($labels);
    $allDone = $last >= $n - 1;

    $text = null; $href = null; $cta = null; $mine = false;
    $qUrl = $quotation ? "/quotations/{$quotation->id}" : null;
    $rUrl = $request ? "/requests/{$request->id}" : null;

    if ($declined) {
        $text = 'This request was declined.';
    } elseif ($allDone) {
        $text = "Job {$job->reference} has been created and the technician can see it.";
        if ($job && $user->can('view', $job)) { $href = "/jobs/{$job->id}"; $cta = 'Open the job'; }
    } elseif ($chargeable) {
        switch ($cur) {
            case 1:
                $mine = $user->can('create', \App\Models\Quotation::class);
                $text = $mine ? 'This work is chargeable. Prepare the quotation.' : 'Waiting for a quotation to be prepared.';
                if ($mine) { $href = '/quotations/create'.($request ? '?request='.$request->id : ''); $cta = 'Prepare quotation'; }
                break;
            case 2:
                $mine = $quotation && $user->can('approve', $quotation);
                $text = $mine ? 'Review the quotation and approve it.' : 'Waiting for a '.($quotation->approval_threshold ?? 'Supervisor').' to approve the quotation.';
                if ($mine && $here !== 'quotation') { $href = $qUrl; $cta = 'Open the quotation'; }
                break;
            case 3:
                $mine = $quotation && $user->can('logLpo', $quotation);
                $text = $mine ? "File the customer's LPO (purchase order) once it arrives." : "Waiting for the customer's LPO.";
                if ($mine && $here !== 'quotation') { $href = $qUrl; $cta = 'File the LPO'; }
                break;
            default:
                $mine = $quotation && $user->can('convertToJob', $quotation);
                $text = $mine ? 'The LPO is in. Choose a technician and create the job.' : 'Waiting for a Service Admin to create the job.';
                if ($mine && $here !== 'quotation') { $href = $qUrl; $cta = 'Create the job'; }
        }
    } else {
        $mine = $request && $user->can('assign', $request);
        $text = $mine ? 'Choose a technician and create the job.' : 'Waiting for a Service Admin to assign a technician.';
    }
@endphp

<div class="card mb-4">
    <div style="display: grid; grid-template-columns: repeat({{ $n }}, 1fr); gap: 4px">
        @foreach ($labels as $i => $label)
            @php $isDone = $i <= $last; $isCur = ! $allDone && ! $declined && $i === $cur; @endphp
            <div style="text-align: center; min-width: 0">
                <div style="height: 6px; border-radius: 3px; background: {{ $isDone ? '#16a34a' : ($isCur ? '#e31e24' : '#e4e4e7') }}"></div>
                <div style="margin-top: 6px; font-size: 0.7rem; line-height: 1.15; font-weight: {{ $isCur ? 700 : 500 }}; color: {{ $isDone ? '#15803d' : ($isCur ? '#18181b' : '#a1a1aa') }}">{{ $label }}</div>
            </div>
        @endforeach
    </div>
    <div style="margin-top: 0.85rem; display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 0.6rem">
        <p style="margin: 0; font-size: 0.875rem; color: #3f3f46">
            @if ($mine && ! $allDone)<span style="font-weight: 700; color: #18181b">Your turn:</span>@endif
            {{ $text }}
        </p>
        @if ($href)
            <a href="{{ $href }}" wire:navigate class="btn-primary">{{ $cta }}</a>
        @endif
    </div>
</div>
