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
        $kes = fn ($m, $c = false) => \App\Services\ManagerReports::kes($m, $c);
        $range = $from->format('d M Y').' to '.$to->format('d M Y');
        $topBranch = max($branches->max('minor') ?? 0, 1);
    @endphp

    <h1>AEA Limited, Management report</h1>
    <p class="meta">{{ $range }} &middot; all branches &middot; prepared by {{ $manager }} &middot; {{ now()->format('d M Y, H:i') }}</p>

    <table class="kpi" style="width: 100%; border-collapse: separate; margin: 0 -4px 12px -4px">
        <tr>
            <td><div class="box"><div class="lab">Revenue</div><div class="val">{{ $kes($revenueMinor, true) }}</div></div></td>
            <td><div class="box"><div class="lab">Jobs closed</div><div class="val">{{ $jobsClosed }}</div></div></td>
            <td><div class="box"><div class="lab">Approvals given</div><div class="val">{{ $approvals }}</div></div></td>
            <td><div class="box"><div class="lab">Contracts expiring soon</div><div class="val">{{ $contracts->count() }}</div></div></td>
        </tr>
    </table>

    <table style="width: 100%; border-collapse: separate; margin: 0 -4px 6px -4px">
        <tr>
            <td style="width: 50%; padding: 0 4px; vertical-align: top">
                <div class="box" style="height: 250px">
                    <h2>Revenue by branch</h2>
                    @foreach ($branches as $b)
                        <div style="margin-bottom: 9px">
                            <table style="width: 100%"><tr>
                                <td>{{ $b['name'] }}</td>
                                <td class="num" style="font-weight: bold">{{ $kes($b['minor']) }}</td>
                            </tr></table>
                            <div class="bar"><div class="fill" style="width: {{ $b['minor'] > 0 ? max(2, round($b['minor'] / $topBranch * 100)) : 0 }}%"></div></div>
                        </div>
                    @endforeach
                </div>
            </td>
            <td style="width: 50%; padding: 0 4px; vertical-align: top">
                <div class="box" style="height: 250px">
                    <h2>Revenue trend (monthly, KES)</h2>
                    <img src="{{ $trendImg }}" style="width: 100%">
                </div>
            </td>
        </tr>
    </table>

    <div class="section">
        <div class="box">
            <h2>Jobs right now</h2>
            <table style="width: 100%"><tr>
                <td style="width: 170px; vertical-align: top"><img src="{{ $pieImg }}" style="width: 150px; height: 150px"></td>
                <td style="vertical-align: middle">
                    @php $mixTotal = max(array_sum(array_column($mix, 'value')), 1); @endphp
                    <table style="width: 100%">
                        @foreach ($mix as $m)
                            <tr>
                                <td style="width: 14px"><div style="width: 9px; height: 9px; background: {{ $m['color'] }}"></div></td>
                                <td>{{ $m['label'] }}</td>
                                <td class="num" style="font-weight: bold">{{ $m['value'] }}</td>
                                <td class="num muted" style="width: 36px">{{ round($m['value'] / $mixTotal * 100) }}%</td>
                            </tr>
                        @endforeach
                    </table>
                </td>
            </tr></table>
            <div class="foot">A snapshot of today, not of the chosen dates.</div>
        </div>
    </div>

    <div class="section">
        <h2>Revenue by technician</h2>
        <table class="grid">
            <thead><tr><th>Technician</th><th>Branch</th><th>Jobs closed</th><th>Open</th><th>Utilization (30d)</th><th class="num">Revenue</th></tr></thead>
            <tbody>
                @forelse ($techs as $t)
                    <tr>
                        <td>{{ $t['user']->name }}</td>
                        <td>{{ $t['location'] ?: '-' }}</td>
                        <td>{{ $t['closed'] }}</td>
                        <td>{{ $t['open'] }}</td>
                        <td>{{ $t['utilization'] !== null ? $t['utilization'].'%' : '-' }}</td>
                        <td class="num">{{ $kes($t['revenueMinor']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6">No technician accounts yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="section">
        <h2>Contracts expiring within 60 days</h2>
        <table class="grid">
            <thead><tr><th>Contract</th><th>Customer</th><th>Ends</th><th>Visits left</th><th>Status</th></tr></thead>
            <tbody>
                @forelse ($contracts as $c)
                    <tr>
                        <td>{{ $c->reference }}</td>
                        <td>{{ $c->customer->name }}</td>
                        <td>{{ $c->ends_at->format('d M Y') }}</td>
                        <td>{{ $c->visitsRemaining() }}</td>
                        <td class="{{ $c->ends_at->lt(today()) ? 'bad' : '' }}">{{ $c->ends_at->lt(today()) ? 'Expired' : 'Expiring soon' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5">No contracts end in the next 60 days.</td></tr>
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
