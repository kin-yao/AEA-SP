<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: 'Helvetica', sans-serif; font-size: 11px; color: #1a1a1a; margin: 0; padding: 30px; }
        .company-name { font-size: 20px; font-weight: bold; color: #E31E24; }
        .doc-title { font-size: 22px; font-weight: bold; text-align: right; }
        .ref { text-align: right; color: #666; font-size: 12px; }
        .status-badge { display: inline-block; padding: 3px 10px; font-size: 10px; font-weight: bold; text-transform: uppercase; margin-top: 4px; }
        .status-paid { background: #eefdec; color: #189913; }
        .status-unpaid { background: #eef0fd; color: #151aa8; }
        .status-partpaid { background: #eef0fd; color: #151aa8; }
        .status-overdue { background: #fbe9eb; color: #a71d2a; }
        .status-draft { background: #f0f0f0; color: #666; }
        .party-table { width: 100%; margin-bottom: 20px; }
        .party-table td { width: 50%; vertical-align: top; padding: 0; }
        .party-label { font-size: 9px; text-transform: uppercase; color: #999; letter-spacing: 1px; margin-bottom: 4px; }
        .party-name { font-size: 13px; font-weight: bold; margin-bottom: 2px; }
        .party-detail { font-size: 10.5px; color: #333; line-height: 1.5; }
        .trace-box { background: #f7f7f7; padding: 10px 12px; margin-bottom: 18px; font-size: 10.5px; line-height: 1.8; }
        .trace-box b { display: inline-block; width: 90px; }
        table.items { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
        table.items th { background: #1a1a1a; color: #fff; text-align: left; padding: 7px 8px; font-size: 10px; text-transform: uppercase; }
        table.items th.num, table.items td.num { text-align: right; }
        table.items td { padding: 7px 8px; border-bottom: 1px solid #e5e5e5; font-size: 11px; }
        .totals { width: 280px; margin-left: auto; margin-top: 8px; }
        .totals td { padding: 5px 0; font-size: 11px; }
        .totals td.num { text-align: right; }
        .totals .balance { border-top: 2px solid #1a1a1a; font-weight: bold; font-size: 14px; padding-top: 8px; }
        table.payments { width: 100%; border-collapse: collapse; margin-top: 22px; }
        table.payments th { background: #1a1a1a; color: #fff; text-align: left; padding: 6px 8px; font-size: 10px; text-transform: uppercase; }
        table.payments td { padding: 6px 8px; border-bottom: 1px solid #e5e5e5; font-size: 10.5px; }
        .meta-table { width: 100%; margin-top: 24px; }
        .meta-table td { vertical-align: top; width: 50%; padding: 0; }
        .meta-label { font-size: 9px; text-transform: uppercase; color: #999; letter-spacing: 1px; margin-bottom: 3px; }
        .meta-value { font-size: 10.5px; line-height: 1.6; }
        .footer { margin-top: 30px; text-align: center; font-size: 9px; color: #999; }
    </style>
</head>
<body>
    @php
        $statusClass = match ($invoice->status) {
            'Paid' => 'status-paid',
            'Part paid' => 'status-partpaid',
            'Unpaid' => 'status-unpaid',
            'Overdue' => 'status-overdue',
            default => 'status-draft',
        };
    @endphp

    <table style="width: 100%; border: none;">
        <tr>
            <td style="border: none;"><div class="company-name">{{ $company['name'] }}</div></td>
            <td style="border: none;">
                <div class="doc-title">INVOICE</div>
                <div class="ref">{{ $invoice->reference }}</div>
                <div class="ref">Issued {{ $invoice->created_at->format('d M Y') }} &middot; Due {{ $invoice->due_at->format('d M Y') }}</div>
                <div style="text-align: right">
                    <span class="status-badge {{ $statusClass }}">{{ $invoice->status }}</span>
                </div>
            </td>
        </tr>
    </table>

    <table class="party-table">
        <tr>
            <td>
                <div class="party-label">From</div>
                <div class="party-name">{{ $company['name'] }}</div>
                <div class="party-detail">
                    KRA PIN: {{ $company['kra_pin'] }}<br>
                    {{ $company['po_box'] }}<br>
                    {{ $company['phone'] }} &middot; {{ $company['email'] }}
                </div>
            </td>
            <td>
                <div class="party-label">Bill to</div>
                <div class="party-name">{{ $invoice->customer->name }}</div>
                <div class="party-detail">
                    @if ($invoice->customer->kra_pin)
                        KRA PIN: {{ $invoice->customer->kra_pin }}
                    @endif
                </div>
            </td>
        </tr>
    </table>

    @if ($invoice->workOrder)
        <div class="trace-box">
            <b>Job</b> {{ $invoice->workOrder->reference }}<br>
            @if ($invoice->workOrder->sourceRequest)
                <b>Request</b> {{ $invoice->workOrder->sourceRequest->reference }}<br>
            @endif
            @if ($invoice->workOrder->sourceQuotation)
                <b>Quotation</b> {{ $invoice->workOrder->sourceQuotation->reference }}<br>
            @endif
            @if ($invoice->workOrder->sourceQuotation?->lpoDetail)
                <b>LPO</b> {{ $invoice->workOrder->sourceQuotation->lpo_reference }}
            @endif
        </div>
    @endif

    @if ($invoice->items->isNotEmpty())
        <table class="items">
            <thead>
                <tr>
                    <th>Description</th>
                    <th class="num">Qty</th>
                    <th class="num">Rate</th>
                    <th class="num">Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($invoice->items as $item)
                    <tr>
                        <td>{{ $item->description }}</td>
                        <td class="num">{{ rtrim(rtrim($item->quantity, '0'), '.') }}</td>
                        <td class="num">{{ number_format($item->rate_minor / 100, 2) }}</td>
                        <td class="num">{{ number_format($item->amountMinor() / 100, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <table class="totals">
        @if ($invoice->items->isNotEmpty())
            <tr><td>Subtotal</td><td class="num">KES {{ number_format($invoice->itemsSubtotalMinor() / 100, 2) }}</td></tr>
            <tr><td>VAT, {{ number_format($invoice->vat_rate * 100, 0) }}%</td><td class="num">KES {{ number_format($invoice->vatMinor() / 100, 2) }}</td></tr>
        @endif
        <tr><td>Total</td><td class="num">KES {{ number_format($invoice->amount_minor / 100, 2) }}</td></tr>
        <tr><td>Paid</td><td class="num">KES {{ number_format($invoice->paid_minor / 100, 2) }}</td></tr>
        <tr class="balance"><td>Balance due</td><td class="num">KES {{ number_format($invoice->balanceMinor() / 100, 2) }}</td></tr>
    </table>

    @if ($invoice->payments->isNotEmpty())
        <table class="payments">
            <thead>
                <tr><th>Receipt</th><th>Method</th><th>Date</th><th style="text-align: right">Amount</th></tr>
            </thead>
            <tbody>
                @foreach ($invoice->payments as $payment)
                    <tr>
                        <td>{{ $payment->reference }}</td>
                        <td>{{ $payment->method }}</td>
                        <td>{{ $payment->paid_at->format('d M Y') }}</td>
                        <td style="text-align: right">KES {{ number_format($payment->amount_minor / 100, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <table class="meta-table">
        <tr>
            <td>
                <div class="meta-label">Payment terms</div>
                <div class="meta-value">{{ $company['default_payment_terms'] }}</div>
            </td>
            <td>
                <div class="meta-label">Bank details</div>
                <div class="meta-value">
                    {{ $company['bank']['account_name'] }}<br>
                    {{ $company['bank']['name'] }}, {{ $company['bank']['branch'] }}<br>
                    Account: {{ $company['bank']['account_number'] }}<br>
                    SWIFT: {{ $company['bank']['swift'] }}
                </div>
            </td>
        </tr>
    </table>

    <div class="footer">This invoice was generated by the AEA Service Management System.</div>
</body>
</html>
