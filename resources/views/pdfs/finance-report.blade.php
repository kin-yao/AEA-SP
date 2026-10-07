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
        $statusTotal = max(array_sum(array_column($statusMix, 'value')), 1);
        $ageTotal = max(array_sum(array_column($ageingMix, 'value')), 1);
    @endphp

    @include('pdfs._brand')
    <h1>AEA Limited, Finance report</h1>
    <p class="meta">{{ $label }} &middot; prepared by {{ $finance }} &middot; {{ now()->format('d M Y, H:i') }}</p>

    <table class="tiles" style="width: 100%; border-collapse: separate; margin: 0 -3px 10px -3px">
        <tr>
            <td><div class="box"><div class="lab">{{ $kpis['revenueLabel'] }}</div><div class="val">{{ $kesC($kpis['revenueMinor']) }}</div></div></td>
            <td><div class="box"><div class="lab">Outstanding</div><div class="val">{{ $kesC($kpis['outstandingMinor']) }}</div></div></td>
            <td><div class="box"><div class="lab">Overdue</div><div class="val">{{ $kesC($kpis['overdueMinor']) }}</div></div></td>
            <td><div class="box"><div class="lab">VAT collected</div><div class="val">{{ $kesC($kpis['vatMinor']) }}</div></div></td>
        </tr>
    </table>

    <h3>Revenue by customer</h3>
    <table class="grid">
        <thead><tr><th>Customer</th><th>Invoices</th><th class="num">Revenue</th></tr></thead>
        <tbody>
            @forelse ($customerRows as $r)
                <tr><td>{{ $r['name'] }}</td><td>{{ $r['count'] }}</td><td class="num">{{ $kes($r['minor']) }}</td></tr>
            @empty
                <tr><td colspan="3" class="muted">No invoices for these filters.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h3>Revenue by branch and technician</h3>
    <table class="two">
        <tr>
            <td>
                <div class="box">
                    <h2>Revenue by branch</h2>
                    <table class="grid">
                        <thead><tr><th>Branch</th><th class="num">Revenue</th></tr></thead>
                        <tbody>
                            @foreach ($branchRows as $r)
                                <tr><td>{{ $r['label'] }}</td><td class="num">{{ $kes($r['value']) }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </td>
            <td>
                <div class="box">
                    <h2>Revenue by technician</h2>
                    <table class="grid">
                        <thead><tr><th>Technician</th><th class="num">Revenue</th></tr></thead>
                        <tbody>
                            @foreach ($techRows as $r)
                                <tr><td>{{ $r['name'] }}</td><td class="num">{{ $kes($r['minor']) }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </td>
        </tr>
    </table>

    <h3>Invoices by status and aging</h3>
    <table class="two keep">
        <tr>
            <td>
                <div class="box" style="height: 150px">
                    <h2>Invoices by status</h2>
                    <table style="width: 100%"><tr>
                        <td style="width: 100px; padding: 0; vertical-align: top"><img src="{{ $img['status'] }}" style="width: 92px; height: 92px"></td>
                        <td style="padding: 0; vertical-align: middle">
                            @foreach ($statusMix as $s)
                                <div style="margin-bottom: 3px"><span style="color: {{ $s['color'] }}">&#9632;</span> {{ $s['label'] }} <b>{{ $s['value'] }}</b> <span class="muted">{{ round($s['value'] / $statusTotal * 100) }}%</span></div>
                            @endforeach
                        </td>
                    </tr></table>
                </div>
            </td>
            <td>
                <div class="box" style="height: 150px">
                    <h2>Aging of money still to collect</h2>
                    <table style="width: 100%"><tr>
                        <td style="width: 100px; padding: 0; vertical-align: top"><img src="{{ $img['ageing'] }}" style="width: 92px; height: 92px"></td>
                        <td style="padding: 0; vertical-align: middle">
                            @foreach ($ageing as $a)
                                <div style="margin-bottom: 3px"><span style="color: {{ $a['color'] }}">&#9632;</span> {{ $a['label'] }} <b>{{ $kesC($a['value']) }}</b></div>
                            @endforeach
                        </td>
                    </tr></table>
                </div>
            </td>
        </tr>
    </table>

    <h3>Outstanding and overdue invoices</h3>
    <table class="grid">
        <thead><tr><th>Invoice</th><th>Customer</th><th>Due</th><th class="num">Amount</th><th class="num">Paid</th><th>Status</th></tr></thead>
        <tbody>
            @forelse ($owingRows as $i)
                @php $shown = \App\Services\FinanceReports::shownStatus($i); @endphp
                <tr>
                    <td>{{ $i->reference }}</td>
                    <td>{{ $i->customer?->name }}</td>
                    <td>{{ $i->due_at->format('d M Y') }}</td>
                    <td class="num">{{ $kes($i->amount_minor) }}</td>
                    <td class="num">{{ $kes($i->paid_minor) }}</td>
                    <td @class(['bad' => $shown === 'Overdue'])>{{ $shown }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="muted">Nothing outstanding for these filters.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h3>VAT summary</h3>
    <table class="grid">
        <thead><tr><th>Period</th><th class="num">Taxable revenue</th><th class="num">VAT</th><th>Currency</th></tr></thead>
        <tbody>
            @forelse ($vatRows as $r)
                <tr>
                    <td>{{ $r['period'] }}</td>
                    <td class="num">{{ $r['currency'] }} {{ number_format($r['taxable'] / 100) }}</td>
                    <td class="num">{{ $r['currency'] }} {{ number_format($r['vat'] / 100) }}</td>
                    <td>{{ $r['currency'] }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="muted">No invoices for these filters.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
