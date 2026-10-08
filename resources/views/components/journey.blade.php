@props(['job'])
@php
    $user = auth()->user();
    $rep = $job->documents()->where('type', 'rep')->latest()->first();
    $inv = $job->invoices()->latest()->first();
    $st = $job->status;
    $rs = $rep?->status;
    $labels = ['Assigned', 'On site', 'Report filed', 'Checked', 'Released', 'Invoiced', 'Paid'];
    $flags = [
        true,
        in_array($st, ['On site', 'Awaiting review', 'Approved', 'Closed'], true) || (bool) $rep,
        (bool) $rep,
        in_array($rs, ['Checked, ready to post', 'Released'], true) || in_array($st, ['Approved', 'Closed'], true),
        $rs === 'Released',
        $inv && $inv->status !== 'Draft',
        $inv && $inv->status === 'Paid',
    ];
    $last = 0;
    foreach ($flags as $i => $f) { if ($f) { $last = $i; } }
    $cur = $last + 1;            // index of the step now in progress
    $allDone = $last >= 6;
    $tech = $job->technician?->name ?? 'the technician';
    $mine = $user->hasRole('Technician') && $job->assigned_technician_id === $user->id;
    $customer = $user->hasRole('Customer');

    $text = null; $href = null; $cta = null; $who = null;
    if ($allDone) {
        $text = 'All done. This job has been paid.';
    } elseif ($customer) {
        if ($cur === 6 && $inv && $inv->status !== 'Draft') {
            $text = "Invoice {$inv->reference} is waiting for payment."; $href = "/invoices/{$inv->id}"; $cta = 'View invoice'; $who = 'You';
        } else {
            $text = 'AEA is working on this step: '.strtolower($labels[$cur]).'.'; $who = 'AEA';
        }
    } else {
        switch ($cur) {
            case 1:
                $who = $mine ? 'You' : $tech;
                $text = $mine ? 'Tap "Move to On site" below when you arrive.' : "Waiting for {$tech} to arrive on site.";
                break;
            case 2:
                $who = $mine ? 'You' : $tech;
                $text = $mine ? 'File the service report, then attach the signed hard copy and any certificates under Certificates and paperwork below.' : "Waiting for {$tech} to file the service report.";
                break;
            case 3:
                $who = $user->hasRole('Supervisor') ? 'You' : 'Supervisor';
                $text = $user->hasRole('Supervisor') ? 'Check the service report and approve it.' : 'Waiting for a Supervisor to check the report.';
                break;
            case 4:
                $who = $user->hasRole('Service Admin') ? 'You' : 'Service Admin';
                $text = $user->hasRole('Service Admin') ? 'Release the checked report to the customer.' : 'Waiting for Service Admin to release the report to the customer.';
                if ($user->hasRole('Service Admin') && $rep) { $href = "/documents/{$rep->id}"; $cta = 'Open the report'; }
                break;
            case 5:
                $who = $user->hasRole('Finance') ? 'You' : 'Finance';
                $text = $user->hasRole('Finance') ? ($inv ? 'Issue the draft invoice to the customer.' : 'Raise the invoice for this job.') : 'Waiting for Finance to invoice this job.';
                if ($user->hasRole('Finance')) { $href = $inv ? "/invoices/{$inv->id}" : "/invoices/create/{$job->id}"; $cta = $inv ? 'Open the invoice' : 'Raise invoice'; }
                break;
            default:
                $who = $user->hasRole('Finance') ? 'You' : 'The customer';
                $text = $user->hasRole('Finance') ? "Record the payment when it arrives on invoice {$inv?->reference}." : "Waiting for the customer to pay invoice {$inv?->reference}.";
                if ($user->hasRole('Finance') && $inv) { $href = "/invoices/{$inv->id}"; $cta = 'Open the invoice'; }
        }
    }
@endphp

<div class="card mb-4">
    <div style="display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px">
        @foreach ($labels as $i => $label)
            @php
                $isDone = $i <= $last;
                $isCur = ! $allDone && $i === $cur;
            @endphp
            <div style="text-align: center; min-width: 0">
                <div style="height: 6px; border-radius: 3px; background: {{ $isDone ? '#16a34a' : ($isCur ? '#e31e24' : '#e4e4e7') }}"></div>
                <div style="margin-top: 6px; font-size: 0.65rem; line-height: 1.15; font-weight: {{ $isCur ? 700 : 500 }}; color: {{ $isDone ? '#15803d' : ($isCur ? '#18181b' : '#a1a1aa') }}">
                    {{ $label }}
                </div>
            </div>
        @endforeach
    </div>

    <div style="margin-top: 0.85rem; display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 0.6rem">
        <p style="margin: 0; font-size: 0.875rem; color: #3f3f46">
            @if (! $allDone && $who === 'You')
                <span style="font-weight: 700; color: #18181b">Your turn:</span>
            @endif
            {{ $text }}
        </p>
        @if ($href)
            <a href="{{ $href }}" wire:navigate class="btn-primary">{{ $cta }}</a>
        @endif
    </div>

    @php $canLink = $user->hasAnyRole(['Manager', 'Supervisor', 'Service Admin', 'Customer']); @endphp
    @if ($job->sourceRequest || $job->sourceQuotation)
        <p style="margin: 0.6rem 0 0; font-size: 0.75rem; color: #71717a">
            Started from
            @if ($job->sourceRequest)
                @if ($canLink)<a href="/requests/{{ $job->sourceRequest->id }}" wire:navigate style="text-decoration: underline">request {{ $job->sourceRequest->reference }}</a>@else request {{ $job->sourceRequest->reference }}@endif
            @endif
            @if ($job->sourceRequest && $job->sourceQuotation) and @endif
            @if ($job->sourceQuotation)
                @if ($canLink)<a href="/quotations/{{ $job->sourceQuotation->id }}" wire:navigate style="text-decoration: underline">quotation {{ $job->sourceQuotation->reference }}</a>@else quotation {{ $job->sourceQuotation->reference }}@endif
            @endif
        </p>
    @endif
</div>
