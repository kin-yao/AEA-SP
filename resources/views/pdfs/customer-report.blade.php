<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 28px 30px 34px 30px; }
        body { font-family: 'Helvetica', sans-serif; font-size: 9.5px; color: #18181b; }
        h1 { font-size: 20px; margin: 0; color: #e31e24; }
        h2 { font-size: 11.5px; margin: 0 0 6px 0; color: #18181b; }
        h3 { page-break-after: avoid; font-size: 13px; margin: 14px 0 8px 0; padding-bottom: 4px; border-bottom: 2px solid #e31e24; color: #18181b; }
        .meta { color: #52525b; font-size: 9.5px; margin: 3px 0 12px 0; }
        .box { border: 1px solid #e4e4e7; border-radius: 6px; padding: 9px 11px; }
        table.grid { width: 100%; border-collapse: collapse; }
        table.grid th { background: #18181b; color: #fff; text-align: left; padding: 5px 6px; font-size: 8px; text-transform: uppercase; }
        table.grid td { padding: 4.5px 6px; border-bottom: 1px solid #e4e4e7; vertical-align: top; }
        .num { text-align: right; }
        .bad { color: #a71d2a; font-weight: bold; }
        .muted { color: #71717a; }
        .tiles td { padding: 0 3px; }
        .tiles .box { height: 40px; }
        .lab { font-size: 8px; color: #52525b; }
        .val { font-size: 15px; font-weight: bold; margin-top: 4px; }
        .two { width: 100%; border-collapse: separate; margin: 0 -4px 8px -4px; }
        .two td { width: 50%; padding: 0 4px; vertical-align: top; }
        .keep { page-break-inside: avoid; }
    </style>
</head>
<body>
    @php
        $kes = fn ($m) => \App\Services\ManagerReports::kes((int) $m);
        $kesC = fn ($m) => \App\Services\ManagerReports::kes((int) $m, true);
        $tot = fn ($mix) => max(array_sum(array_column($mix, 'value')), 1);
        $legend = function ($mix) use ($tot) {
            $t = $tot($mix);
            $out = '';
            foreach ($mix as $s) {
                $out .= '<div style="margin-bottom: 3px"><span style="color: '.$s['color'].'">&#9632;</span> '.e($s['label']).' <b>'.$s['value'].'</b> <span class="muted">'.round($s['value'] / $t * 100).'%</span></div>';
            }
            return $out;
        };
    @endphp

    @include('pdfs._brand')
    <h1>AEA Limited, My reports</h1>
    <p class="meta">{{ $company }} &middot; prepared for {{ $person }} &middot; {{ $label }} &middot; {{ now()->format('d M Y, H:i') }}</p>

    <table class="tiles" style="width: 100%; border-collapse: separate; margin: 0 -3px 10px -3px">
        <tr>
            <td><div class="box"><div class="lab">Requests raised</div><div class="val">{{ $kpis['requests'] }}</div></div></td>
            <td><div class="box"><div class="lab">Jobs completed</div><div class="val">{{ $kpis['jobsDone'] }}</div></div></td>
            <td><div class="box"><div class="lab">Reports received</div><div class="val">{{ $kpis['reports'] }}</div></div></td>
            <td><div class="box"><div class="lab">Invoiced</div><div class="val">{{ $kesC($kpis['invoicedMinor']) }}</div></div></td>
            <td><div class="box"><div class="lab">Outstanding</div><div class="val">{{ $kesC($kpis['outstandingMinor']) }}</div></div></td>
        </tr>
    </table>

    <h3>Service activity</h3>
    <table class="two keep">
        <tr>
            <td>
                <div class="box" style="height: 150px">
                    <h2>Requests by status</h2>
                    <table style="width: 100%"><tr>
                        <td style="width: 100px; padding: 0; vertical-align: top"><img src="{{ $img['requestMix'] }}" style="width: 92px; height: 92px"></td>
                        <td style="padding: 0; vertical-align: middle">{!! $legend($requestMix) !!}</td>
                    </tr></table>
                </div>
            </td>
            <td>
                <div class="box" style="height: 150px">
                    <h2>Requests per month</h2>
                    <img src="{{ $img['requestMonths'] }}" style="width: 100%; height: 118px">
                </div>
            </td>
        </tr>
    </table>
    <div class="box keep" style="margin-bottom: 8px">
        <h2>Jobs by type of visit</h2>
        <img src="{{ $img['nature'] }}" style="width: 100%; height: 130px">
    </div>

    <h3>Invoices and payments</h3>
    <table class="two keep">
        <tr>
            <td>
                <div class="box" style="height: 150px">
                    <h2>Invoices by status</h2>
                    <table style="width: 100%"><tr>
                        <td style="width: 100px; padding: 0; vertical-align: top"><img src="{{ $img['invoiceMix'] }}" style="width: 92px; height: 92px"></td>
                        <td style="padding: 0; vertical-align: middle">{!! $legend($invoiceMix) !!}</td>
                    </tr></table>
                </div>
            </td>
            <td>
                <div class="box" style="height: 150px">
                    <h2>Invoiced per month</h2>
                    <img src="{{ $img['invoiced'] }}" style="width: 100%; height: 118px">
                </div>
            </td>
        </tr>
    </table>
    <table class="two keep">
        <tr>
            <td>
                <div class="box" style="height: 150px">
                    <h2>Aging of money still to pay</h2>
                    <img src="{{ $img['ageing'] }}" style="width: 100%; height: 118px">
                </div>
            </td>
            <td>
                <div class="box" style="height: 150px">
                    <h2>Aging detail</h2>
                    @foreach ($ageing as $a)
                        <div style="margin-bottom: 3px"><span style="color: {{ $a['color'] }}">&#9632;</span> {{ $a['label'] }} <b>{{ $kesC($a['value']) }}</b> <span class="muted">({{ $a['count'] }})</span></div>
                    @endforeach
                </div>
            </td>
        </tr>
    </table>

    <h3>Unpaid invoices</h3>
    <table class="grid">
        <thead><tr><th>Invoice</th><th>Due</th><th class="num">Amount</th><th class="num">Paid</th><th>Status</th></tr></thead>
        <tbody>
            @forelse ($owingRows as $i)
                @php $shown = \App\Services\FinanceReports::shownStatus($i); @endphp
                <tr>
                    <td>{{ $i->reference }}</td>
                    <td>{{ $i->due_at->format('d M Y') }}</td>
                    <td class="num">{{ $kes($i->amount_minor) }}</td>
                    <td class="num">{{ $kes($i->paid_minor) }}</td>
                    <td @class(['bad' => $shown === 'Overdue'])>{{ $shown }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="muted">Nothing is unpaid.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h3>Machines</h3>
    <table class="two keep">
        <tr>
            <td style="width: 35%">
                <div class="box" style="height: 120px">
                    <h2>Service status</h2>
                    <table style="width: 100%"><tr>
                        <td style="width: 100px; padding: 0; vertical-align: top"><img src="{{ $img['machineMix'] }}" style="width: 82px; height: 82px"></td>
                        <td style="padding: 0; vertical-align: middle">{!! $legend($machineMix) !!}</td>
                    </tr></table>
                </div>
            </td>
            <td></td>
        </tr>
    </table>
    <table class="grid">
        <thead><tr><th>Serial</th><th>Machine</th><th>Site</th><th>Next service</th><th>Status</th></tr></thead>
        <tbody>
            @forelse ($machines as $e)
                @php $st = $e->visitStatus(); @endphp
                <tr>
                    <td>{{ $e->serial_number }}</td>
                    <td>{{ $e->model }}</td>
                    <td>{{ $e->site?->name }}</td>
                    <td>{{ $e->next_visit_due_at?->format('d M Y') ?? 'Not set' }}</td>
                    <td @class(['bad' => $st === 'Overdue'])>{{ $st }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="muted">No machines are registered.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h3>Contracts</h3>
    <table class="grid">
        <thead><tr><th>Contract</th><th>Type</th><th>Ends</th><th>Visits used</th><th>Visits left</th><th>Status</th></tr></thead>
        <tbody>
            @forelse ($contractRows as $r)
                <tr>
                    <td>{{ $r['c']->reference }}</td>
                    <td>{{ $r['c']->type }}</td>
                    <td>{{ $r['c']->ends_at->format('d M Y') }}</td>
                    <td>{{ $r['used'] }}</td>
                    <td>{{ $r['left'] }}</td>
                    <td @class(['bad' => $r['stage'] === 'Expired'])>{{ $r['stage'] }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="muted">No contracts.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
