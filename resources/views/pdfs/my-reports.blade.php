<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: 'Helvetica', sans-serif; font-size: 10.5px; color: #1a1a1a; margin: 0; padding: 28px; }
        h1 { font-size: 18px; margin: 0 0 2px 0; color: #E31E24; }
        h2 { font-size: 12px; margin: 22px 0 6px 0; }
        .meta { color: #666; font-size: 10.5px; margin-bottom: 14px; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #1a1a1a; color: #fff; text-align: left; padding: 6px 7px; font-size: 9px; text-transform: uppercase; }
        td { padding: 6px 7px; border-bottom: 1px solid #e5e5e5; vertical-align: top; }
        .num { text-align: right; }
        .kpi td { border: 0; padding: 0 14px 0 0; }
        .kpi .v { font-size: 15px; font-weight: bold; }
        .kpi .l { font-size: 9px; color: #777; text-transform: uppercase; }
        .bad { color: #a71d2a; font-weight: bold; }
        .good { color: #189913; }
    </style>
</head>
<body>
    <h1>My reports</h1>
    <p class="meta">{{ $technician }} &middot; {{ $range }} &middot; generated {{ now()->format('d M Y, H:i') }}</p>

    @php $money = fn ($m) => number_format($m / 100, 2); @endphp

    <table class="kpi">
        <tr>
            <td><div class="v">{{ $kpi['matching'] }}</div><div class="l">Jobs in range</div></td>
            <td><div class="v">{{ $money($kpi['billed']) }}</div><div class="l">Billed ({{ currency() }})</div></td>
            <td><div class="v good">{{ $money($kpi['paid']) }}</div><div class="l">Collected ({{ currency() }})</div></td>
            <td><div class="v bad">{{ $money($kpi['balance']) }}</div><div class="l">Outstanding ({{ currency() }})</div></td>
        </tr>
    </table>

    <h2>Revenue per customer served</h2>
    <table>
        <thead>
            <tr><th>Customer</th><th>Jobs</th><th class="num">Billed</th><th class="num">Paid</th><th class="num">Balance</th></tr>
        </thead>
        <tbody>
            @forelse ($customers as $c)
                <tr>
                    <td>{{ $c['name'] }}</td>
                    <td>{{ $c['jobs'] }}</td>
                    <td class="num">{{ $money($c['billed']) }}</td>
                    <td class="num">{{ $money($c['paid']) }}</td>
                    <td class="num {{ $c['balance'] > 0 ? 'bad' : '' }}">{{ $money($c['balance']) }}</td>
                </tr>
            @empty
                <tr><td colspan="5">No customers in this range.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2>Job history</h2>
    <table>
        <thead>
            <tr><th>Job</th><th>Customer</th><th>Nature of visit</th><th>Date</th><th>Status</th><th>Payment</th><th class="num">Balance</th></tr>
        </thead>
        <tbody>
            @forelse ($rows as $r)
                <tr>
                    <td>{{ $r['job']->reference }}</td>
                    <td>{{ $r['job']->customer->name }}</td>
                    <td>{{ $r['job']->nature_of_visit }}</td>
                    <td>{{ $r['job']->due_date->format('d M Y') }}</td>
                    <td>{{ $r['job']->status }}</td>
                    <td>{{ $r['payLabel'] }}</td>
                    <td class="num {{ $r['balance'] > 0 ? 'bad' : '' }}">{{ $r['billed'] > 0 ? $money($r['balance']) : '-' }}</td>
                </tr>
            @empty
                <tr><td colspan="7">No jobs in this range.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
