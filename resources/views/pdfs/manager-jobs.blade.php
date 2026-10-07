<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: 'Helvetica', sans-serif; font-size: 9.5px; color: #1a1a1a; margin: 0; padding: 24px; }
        h1 { font-size: 17px; margin: 0 0 2px 0; color: #E31E24; }
        .meta { color: #666; font-size: 10px; margin-bottom: 14px; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #1a1a1a; color: #fff; text-align: left; padding: 6px 6px; font-size: 8.5px; text-transform: uppercase; }
        td { padding: 5px 6px; border-bottom: 1px solid #e5e5e5; vertical-align: top; }
        .num { text-align: right; }
        .bad { color: #a71d2a; font-weight: bold; }
    </style>
</head>
<body>
    <h1>Job history</h1>
    <p class="meta">AEA Limited &middot; {{ $label }} &middot; {{ $rows->count() }} jobs &middot; prepared by {{ $manager }} &middot; {{ now()->format('d M Y, H:i') }}</p>

    <table>
        <thead>
            <tr>
                <th>Job</th><th>Customer</th><th>Branch</th><th>Country</th><th>Technician</th>
                <th>Nature of visit</th><th>Date</th><th class="num">Value ({{ currency() }})</th><th>Status</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $r)
                <tr>
                    <td>{{ $r['job']->reference }}</td>
                    <td>{{ $r['job']->customer->name }}</td>
                    <td>{{ $r['branch'] }}</td>
                    <td>{{ $r['country'] }}</td>
                    <td>{{ $r['technician'] }}</td>
                    <td>{{ $r['job']->nature_of_visit }}</td>
                    <td>{{ $r['job']->due_date->format('d M Y') }}</td>
                    <td class="num">{{ $r['valueMinor'] > 0 ? number_format($r['valueMinor'] / 100, 2) : '-' }}</td>
                    <td class="{{ $r['status'] === 'Overdue' ? 'bad' : '' }}">{{ $r['status'] }}</td>
                </tr>
            @empty
                <tr><td colspan="9">No jobs match this filter.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
