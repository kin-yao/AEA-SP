<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 28px 30px 34px 30px; }
        body { font-family: 'Helvetica', sans-serif; font-size: 9.5px; color: #18181b; }
        h1 { font-size: 20px; margin: 0; color: #e31e24; }
        h2 { font-size: 11.5px; margin: 0 0 8px 0; color: #18181b; }
        .meta { color: #52525b; font-size: 9.5px; margin: 3px 0 14px 0; }
        .box { border: 1px solid #e4e4e7; border-radius: 6px; padding: 10px 12px; }
        .section { margin-bottom: 14px; page-break-inside: avoid; }
        table.grid { width: 100%; border-collapse: collapse; }
        table.grid th { background: #18181b; color: #fff; text-align: left; padding: 5px 6px; font-size: 8px; text-transform: uppercase; }
        table.grid td { padding: 4.5px 6px; border-bottom: 1px solid #e4e4e7; vertical-align: top; }
        .num { text-align: right; }
        .bad { color: #a71d2a; font-weight: bold; }
        .muted { color: #71717a; }
        .kpi td { width: 25%; padding: 0 4px; }
        .kpi .box { height: 44px; }
        .kpi .lab { font-size: 8.5px; color: #52525b; }
        .kpi .val { font-size: 17px; font-weight: bold; margin-top: 5px; }
        .bar { background: #f4f4f5; height: 7px; border-radius: 4px; }
        .fill { background: #e31e24; height: 7px; border-radius: 4px; }
        .foot { color: #71717a; font-size: 8px; margin-top: 4px; }
        .pb { page-break-before: always; }
    </style>
</head>
<body>
    @php
        $kes = fn ($m) => \App\Services\ManagerReports::kes($m);
        $range = $from->format('d M Y').' to '.$to->format('d M Y');
        $routeTotal = max(array_sum(array_column($route, 'value')), 1);
    @endphp

    <h1>AEA Limited, Supervisor report</h1>
    <p class="meta">{{ $range }} &middot; prepared by {{ $supervisor }} &middot; {{ now()->format('d M Y, H:i') }}</p>

    <table class="kpi" style="width: 100%; border-collapse: separate; margin: 0 -4px 12px -4px">
        <tr>
            <td><div class="box"><div class="lab">Jobs dispatched</div><div class="val">{{ $dispatched }}</div></div></td>
            <td><div class="box"><div class="lab">Quotations approved</div><div class="val">{{ $approved }}</div></div></td>
            <td><div class="box"><div class="lab">Reports checked</div><div class="val">{{ $checked }}</div></div></td>
            <td><div class="box"><div class="lab">Response compliance</div><div class="val">{{ $compliance !== null ? $compliance.'%' : '-' }}</div></div></td>
        </tr>
    </table>

    <div class="section">
        <div class="box">
            <h2>Response target compliance, last 8 weeks</h2>
            <img src="{{ $complianceImg }}" style="width: 100%">
            <div class="foot">Dashed line is the {{ \App\Services\SupervisorReports::TARGET_PERCENT }}% target. Share of requests that had a job dispatched within {{ \App\Services\SupervisorReports::RESPONSE_TARGET_HOURS }} hours.</div>
        </div>
    </div>

    <table style="width: 100%; border-collapse: separate; margin: 0 -4px 10px -4px">
        <tr>
            <td style="width: 50%; padding: 0 4px; vertical-align: top">
                <div class="box" style="height: 245px">
                    <h2>Technician utilization (30 days)</h2>
                    @foreach ($utilization as $u)
                        <div style="margin-bottom: 7px">
                            <table style="width: 100%"><tr>
                                <td>{{ $u['name'] }}</td>
                                <td class="num" style="font-weight: bold">{{ $u['utilization'] !== null ? $u['utilization'].'%' : '-' }}</td>
                            </tr></table>
                            <div class="bar"><div class="fill" style="width: {{ $u['utilization'] ?? 0 }}%"></div></div>
                        </div>
                    @endforeach
                </div>
            </td>
            <td style="width: 50%; padding: 0 4px; vertical-align: top">
                <div class="box" style="height: 245px">
                    <h2>Approval route</h2>
                    <table style="width: 100%"><tr>
                        <td style="width: 110px; vertical-align: top"><img src="{{ $pieImg }}" style="width: 100px; height: 100px"></td>
                        <td style="vertical-align: middle">
                            <table style="width: 100%">
                                @foreach ($route as $s)
                                    <tr>
                                        <td style="width: 14px"><div style="width: 9px; height: 9px; background: {{ $s['color'] }}"></div></td>
                                        <td>{{ $s['label'] }}</td>
                                        <td class="num" style="font-weight: bold">{{ $s['value'] }}</td>
                                        <td class="num muted" style="width: 34px">{{ round($s['value'] / $routeTotal * 100) }}%</td>
                                    </tr>
                                @endforeach
                            </table>
                        </td>
                    </tr></table>
                    <div class="foot">Quotations raised in the chosen dates.</div>
                </div>
            </td>
        </tr>
    </table>

    <div class="section">
        <h2>Quotations, mine to approve versus escalated</h2>
        <table class="grid">
            <thead><tr><th>Reference</th><th>Customer</th><th class="num">Amount</th><th>Approval route</th><th>Status</th></tr></thead>
            <tbody>
                @forelse ($quotations as $q)
                    <tr>
                        <td>{{ $q->reference }}</td>
                        <td>{{ $q->customer->name }}</td>
                        <td class="num">{{ $kes($q->totalMinor()) }}</td>
                        <td>{{ $q->approval_threshold }}</td>
                        <td>{{ $q->status }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5">No quotations were raised in these dates.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="pb">
        <h2>Job history, {{ $range }} ({{ $history->count() }} jobs)</h2>
        <table class="grid">
            <thead>
                <tr><th>Job</th><th>Customer</th><th>Branch</th><th>Technician</th><th>Nature of visit</th><th>Date</th><th class="num">Value</th><th>Status</th></tr>
            </thead>
            <tbody>
                @forelse ($history as $r)
                    <tr>
                        <td>{{ $r['job']->reference }}</td>
                        <td>{{ $r['job']->customer->name }}</td>
                        <td>{{ $r['branch'] }}</td>
                        <td>{{ $r['technician'] }}</td>
                        <td>{{ $r['job']->nature_of_visit }}</td>
                        <td>{{ $r['job']->due_date->format('d M Y') }}</td>
                        <td class="num">{{ $r['valueMinor'] > 0 ? $kes($r['valueMinor']) : '-' }}</td>
                        <td class="{{ $r['status'] === 'Overdue' ? 'bad' : '' }}">{{ $r['status'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8">No jobs fall in these dates.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</body>
</html>
