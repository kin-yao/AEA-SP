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
        .foot { color: #71717a; font-size: 8px; margin-top: 3px; }
        .pb { page-break-before: always; }
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
        $total = fn ($mix) => max(array_sum(array_column($mix, 'value')), 1);
    @endphp

    <h1>AEA Limited, Service report</h1>
    <p class="meta">{{ $label }} &middot; prepared by {{ $admin }} &middot; {{ now()->format('d M Y, H:i') }}</p>

    <table class="tiles" style="width: 100%; border-collapse: separate; margin: 0 -3px 10px -3px">
        <tr>
            <td><div class="box"><div class="lab">Requests received</div><div class="val">{{ $kpis['requests'] }}</div></div></td>
            <td><div class="box"><div class="lab">Request to job rate</div><div class="val">{{ $kpis['winRate'] !== null ? $kpis['winRate'].'%' : '-' }}</div></div></td>
            <td><div class="box"><div class="lab">Quotation pipeline</div><div class="val">{{ $kesC($kpis['pipelineMinor']) }}</div></div></td>
            <td><div class="box"><div class="lab">LPOs awaited</div><div class="val">{{ $kpis['lpoAwaited'] }}</div></div></td>
            <td><div class="box"><div class="lab">Jobs overdue</div><div class="val">{{ $kpis['overdueJobs'] }}</div></div></td>
            <td><div class="box"><div class="lab">Items to reorder</div><div class="val">{{ $kpis['reorder'] }}</div></div></td>
        </tr>
    </table>

    {{-- ============ Overview ============ --}}
    <h3>Intake and conversion</h3>
    <table class="two">
        <tr>
            <td>
                <div class="box" style="height: 190px">
                    <h2>Requests by status</h2>
                    <table style="width: 100%"><tr>
                        <td style="width: 100px; padding: 0; vertical-align: top"><img src="{{ $img['requestMix'] }}" style="width: 92px; height: 92px"></td>
                        <td style="padding: 0; vertical-align: middle">
                            <table style="width: 100%">
                                @foreach ($requestMix as $s)
                                    <tr>
                                        <td style="width: 12px; padding: 2px 0"><div style="width: 8px; height: 8px; background: {{ $s['color'] }}"></div></td>
                                        <td>{{ $s['label'] }}</td>
                                        <td class="num" style="font-weight: bold">{{ $s['value'] }}</td>
                                        <td class="num muted" style="width: 30px">{{ round($s['value'] / $total($requestMix) * 100) }}%</td>
                                    </tr>
                                @endforeach
                            </table>
                        </td>
                    </tr></table>
                </div>
            </td>
            <td>
                <div class="box" style="height: 190px">
                    <h2>Work pipeline</h2>
                    <img src="{{ $img['pipeline'] }}" style="width: 100%">
                </div>
            </td>
        </tr>
    </table>
    <table class="two keep">
        <tr>
            <td><div class="box"><h2>Jobs by nature of visit</h2><img src="{{ $img['nature'] }}" style="width: 100%"></div></td>
            <td><div class="box"><h2>Jobs by branch</h2><img src="{{ $img['branches'] }}" style="width: 100%"></div></td>
        </tr>
    </table>

    {{-- ============ Quotations ============ --}}
    <h3>Quotations</h3>
    <table class="tiles" style="width: 100%; border-collapse: separate; margin: 0 -3px 8px -3px">
        <tr>
            <td><div class="box"><div class="lab">Quotations raised</div><div class="val">{{ $quoteTiles['raised'] }}</div></div></td>
            <td><div class="box"><div class="lab">Value won</div><div class="val">{{ $kesC($quoteTiles['wonMinor']) }}</div></div></td>
            <td><div class="box"><div class="lab">Approved quotes with an LPO</div><div class="val">{{ $quoteTiles['lpoRate'] !== null ? $quoteTiles['lpoRate'].'%' : '-' }}</div></div></td>
        </tr>
    </table>
    <table class="two keep">
        <tr>
            <td>
                <div class="box" style="height: 165px">
                    <h2>By status</h2>
                    <table style="width: 100%"><tr>
                        <td style="width: 100px; padding: 0; vertical-align: top"><img src="{{ $img['quoteMix'] }}" style="width: 92px; height: 92px"></td>
                        <td style="padding: 0; vertical-align: middle">
                            <table style="width: 100%">
                                @foreach ($quoteMix as $s)
                                    <tr>
                                        <td style="width: 12px; padding: 2px 0"><div style="width: 8px; height: 8px; background: {{ $s['color'] }}"></div></td>
                                        <td>{{ $s['label'] }}</td>
                                        <td class="num" style="font-weight: bold">{{ $s['value'] }}</td>
                                    </tr>
                                @endforeach
                            </table>
                        </td>
                    </tr></table>
                    <h2 style="margin-top: 8px">LPO status</h2>
                    <table style="width: 100%">
                        @foreach ($lpoMix as $s)
                            <tr>
                                <td style="width: 12px; padding: 2px 0"><div style="width: 8px; height: 8px; background: {{ $s['color'] }}"></div></td>
                                <td>{{ $s['label'] }}</td>
                                <td class="num" style="font-weight: bold">{{ $s['value'] }}</td>
                            </tr>
                        @endforeach
                    </table>
                </div>
            </td>
            <td><div class="box" style="height: 165px"><h2>Value by status ({{ currency() }})</h2><img src="{{ $img['value'] }}" style="width: 100%"></div></td>
        </tr>
    </table>
    <div class="keep">
        <h2>Quotations that need action</h2>
        <table class="grid">
            <thead><tr><th>Quotation</th><th>Customer</th><th class="num">Amount</th><th>Waiting for</th><th class="num">Days</th></tr></thead>
            <tbody>
                @forelse ($needs->take(10) as $n)
                    <tr>
                        <td>{{ $n['q']->reference }}</td>
                        <td>{{ $n['q']->customer?->name }}</td>
                        <td class="num">{{ $kes($n['q']->totalMinor()) }}</td>
                        <td>{{ $n['what'] }}</td>
                        <td class="num">{{ $n['days'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5">Nothing is waiting.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- ============ Finance ============ --}}
    <h3>Finance</h3>
    <table class="tiles" style="width: 100%; border-collapse: separate; margin: 0 -3px 8px -3px">
        <tr>
            <td><div class="box"><div class="lab">Paid</div><div class="val">{{ $finance['paidCount'] }}</div><div class="lab">{{ $kes($finance['paidMinor']) }}</div></div></td>
            <td><div class="box"><div class="lab">Draft or part paid</div><div class="val">{{ $finance['draftPartCount'] }}</div><div class="lab">{{ $kes($finance['draftPartMinor']) }}</div></div></td>
            <td><div class="box"><div class="lab">Overdue</div><div class="val">{{ $finance['overdueCount'] }}</div><div class="lab">{{ $kes($finance['overdueMinor']) }}</div></div></td>
            <td><div class="box"><div class="lab">Still to collect</div><div class="val">{{ $finance['owingCount'] }}</div><div class="lab">{{ $kes($finance['owingMinor']) }}</div></div></td>
        </tr>
    </table>
    <table class="two keep" style="margin-top: 12px">
        <tr>
            <td><div class="box"><h2>Money still to collect, by age ({{ currency() }})</h2><img src="{{ $img['ageing'] }}" style="width: 100%"></div></td>
            <td><div class="box"><h2>Revenue by technician ({{ currency() }})</h2><img src="{{ $img['revenue'] }}" style="width: 100%"></div></td>
        </tr>
    </table>
    <div class="keep">
        <table class="grid">
            <thead><tr><th>Technician</th><th>Branch</th><th class="num">Jobs closed</th><th class="num">Open jobs</th><th class="num">Revenue</th></tr></thead>
            <tbody>
                @forelse ($techRows as $t)
                    <tr>
                        <td>{{ $t['user']->name }}</td>
                        <td>{{ $t['location'] ?: '-' }}</td>
                        <td class="num">{{ $t['closed'] }}</td>
                        <td class="num">{{ $t['open'] }}@if ($t['overdue'] > 0) <span class="bad">({{ $t['overdue'] }} overdue)</span>@endif</td>
                        <td class="num">{{ $kes($t['revenueMinor']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5">No technicians match.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- ============ Technicians ============ --}}
    <h3>Technicians</h3>
    <table class="two keep">
        <tr>
            <td>
                <div class="box">
                    <h2>Reports by status</h2>
                    <table style="width: 100%"><tr>
                        <td style="width: 100px; padding: 0; vertical-align: top"><img src="{{ $img['reportMix'] }}" style="width: 92px; height: 92px"></td>
                        <td style="padding: 0; vertical-align: middle">
                            <table style="width: 100%">
                                @foreach ($reportMix as $s)
                                    <tr>
                                        <td style="width: 12px; padding: 2px 0"><div style="width: 8px; height: 8px; background: {{ $s['color'] }}"></div></td>
                                        <td>{{ $s['label'] }}</td>
                                        <td class="num" style="font-weight: bold">{{ $s['value'] }}</td>
                                    </tr>
                                @endforeach
                            </table>
                        </td>
                    </tr></table>
                    <div class="foot">{{ $toPost }} checked and waiting to be posted. {{ $techDocs->count() }} technician documents flagged.</div>
                </div>
            </td>
            <td><div class="box"><h2>Open jobs per technician</h2><img src="{{ $img['workload'] }}" style="width: 100%"><div class="foot">Red means at least one is overdue.</div></div></td>
        </tr>
    </table>
    <div class="keep">
        <h2>Technician documents and certificates</h2>
        <table class="grid">
            <thead><tr><th>Technician</th><th>Document</th><th>Due</th><th>Status</th></tr></thead>
            <tbody>
                @forelse ($techDocs as $doc)
                    <tr>
                        <td>{{ $doc->technician->name }}</td>
                        <td>{{ $doc->document_type }}</td>
                        <td>{{ $doc->dueLabel() }}</td>
                        <td class="{{ $doc->expiresAt()->lt(today()) ? 'bad' : '' }}">{{ $doc->expiresAt()->lt(today()) ? 'Overdue' : 'Due soon' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4">No document is expired or close to expiry.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- ============ Inventory ============ --}}
    <h3>Inventory</h3>
    <table class="tiles" style="width: 100%; border-collapse: separate; margin: 0 -3px 8px -3px">
        <tr>
            <td><div class="box"><div class="lab">Items to reorder</div><div class="val">{{ $kpis['reorder'] }}</div></div></td>
            <td><div class="box"><div class="lab">Units in stock</div><div class="val">{{ number_format($inventory['units']) }}</div></div></td>
            <td><div class="box"><div class="lab">Stock value at cost</div><div class="val">{{ $kesC($inventory['valueMinor']) }}</div></div></td>
        </tr>
    </table>
    <table class="two keep" style="margin-top: 12px">
        <tr>
            <td><div class="box"><h2>Stock at or below reorder level</h2><img src="{{ $img['low'] }}" style="width: 100%"><div class="foot">Bar is in stock, dashed line is the reorder level.</div></div></td>
            <td><div class="box"><h2>Units in stock by category</h2><img src="{{ $img['category'] }}" style="width: 100%"></div></td>
        </tr>
    </table>
    <table class="two keep">
        <tr>
            <td><div class="box"><h2>Stock used {{ $inventory['usedLabel'] }}</h2><img src="{{ $img['used'] }}" style="width: 100%"></div></td>
            <td></td>
        </tr>
    </table>

    {{-- ============ Contracts ============ --}}
    <h3>Contracts, renewal risk</h3>
    <table class="two keep">
        <tr>
            <td><div class="box"><h2>Visits remaining, lowest first</h2><img src="{{ $img['contracts'] }}" style="width: 100%"><div class="foot">Red none left, amber 1 to 3, green 4 or more.</div></div></td>
            <td>
                <table class="grid">
                    <thead><tr><th>Contract</th><th>Customer</th><th class="num">Left</th><th>Ends</th><th>Status</th></tr></thead>
                    <tbody>
                        @forelse ($contractRows as $r)
                            <tr>
                                <td>{{ $r['c']->reference }}</td>
                                <td>{{ $r['c']->customer?->name }}</td>
                                <td class="num">{{ $r['left'] }}</td>
                                <td>{{ $r['c']->ends_at->format('d M Y') }}</td>
                                <td class="{{ $r['state'] === 'Expired' ? 'bad' : '' }}">{{ $r['state'] }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5">No active contracts.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </td>
        </tr>
    </table>

    {{-- ============ Job history ============ --}}
    <div class="pb">
        <h2>Job history, {{ $label }} ({{ $history->count() }} jobs)</h2>
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
