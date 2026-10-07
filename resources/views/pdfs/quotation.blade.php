<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: 'Helvetica', sans-serif; font-size: 11px; color: #1a1a1a; margin: 0; padding: 30px; }
        .company-name { font-size: 20px; font-weight: bold; color: #E31E24; }
        .doc-title { font-size: 22px; font-weight: bold; text-align: right; }
        .ref { text-align: right; color: #666; font-size: 12px; }
        .party-table { width: 100%; margin-bottom: 20px; }
        .party-table td { width: 50%; vertical-align: top; padding: 0; }
        .party-label { font-size: 9px; text-transform: uppercase; color: #999; letter-spacing: 1px; margin-bottom: 4px; }
        .party-name { font-size: 13px; font-weight: bold; margin-bottom: 2px; }
        .party-detail { font-size: 10.5px; color: #333; line-height: 1.5; }
        .scope-box { background: #f7f7f7; padding: 10px 12px; margin-bottom: 18px; font-size: 11px; }
        table.items { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
        table.items th { background: #1a1a1a; color: #fff; text-align: left; padding: 7px 8px; font-size: 10px; text-transform: uppercase; }
        table.items th.num, table.items td.num { text-align: right; }
        table.items td { padding: 7px 8px; border-bottom: 1px solid #e5e5e5; font-size: 11px; }
        .totals { width: 260px; margin-left: auto; margin-top: 8px; }
        .totals td { padding: 4px 0; font-size: 11px; }
        .totals td.num { text-align: right; }
        .totals .grand { border-top: 2px solid #1a1a1a; font-weight: bold; font-size: 13px; padding-top: 8px; }
        .meta-table { width: 100%; margin-top: 24px; }
        .meta-table td { vertical-align: top; width: 50%; padding: 0; }
        .meta-label { font-size: 9px; text-transform: uppercase; color: #999; letter-spacing: 1px; margin-bottom: 3px; }
        .meta-value { font-size: 10.5px; line-height: 1.6; }
        .signoff { margin-top: 40px; }
        .signoff-line { border-top: 1px solid #333; width: 220px; margin-top: 40px; padding-top: 4px; font-size: 10px; color: #666; }
        .footer { margin-top: 30px; text-align: center; font-size: 9px; color: #999; }
    </style>
</head>
<body>
    <table style="width: 100%; border: none;">
        <tr>
            <td style="border: none;"><div class="company-name">{{ $company['name'] }}</div></td>
            <td style="border: none;">
                <div class="doc-title">QUOTATION</div>
                <div class="ref">{{ $quotation->reference }}</div>
                <div class="ref">{{ $quotation->created_at->format('d M Y') }}</div>
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
                <div class="party-label">To</div>
                <div class="party-name">{{ $quotation->customer->name }}</div>
                <div class="party-detail">
                    @if ($quotation->customer->kra_pin)
                        KRA PIN: {{ $quotation->customer->kra_pin }}
                    @endif
                </div>
            </td>
        </tr>
    </table>

    <div class="scope-box"><strong>Scope:</strong> {{ $quotation->scope }}</div>

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
            @foreach ($quotation->items as $item)
                <tr>
                    <td>{{ $item->description }}</td>
                    <td class="num">{{ $item->quantity }}</td>
                    <td class="num">{{ number_format($item->rate_minor / 100, 2) }}</td>
                    <td class="num">{{ number_format($item->amountMinor() / 100, 2) }}</td>
                </tr>
            @endforeach
            <tr>
                <td colspan="3">Labour</td>
                <td class="num">{{ number_format($quotation->labour_minor / 100, 2) }}</td>
            </tr>
        </tbody>
    </table>

    <table class="totals">
        <tr><td>Subtotal</td><td class="num">{{ $quotation->currency_code }} {{ number_format($quotation->subtotalMinor() / 100, 2) }}</td></tr>
        <tr><td>VAT, {{ number_format($quotation->vat_rate * 100, 0) }}%</td><td class="num">{{ $quotation->currency_code }} {{ number_format($quotation->vatMinor() / 100, 2) }}</td></tr>
        <tr class="grand"><td>Total</td><td class="num">{{ $quotation->currency_code }} {{ number_format($quotation->totalMinor() / 100, 2) }}</td></tr>
    </table>

    <table class="meta-table">
        <tr>
            <td>
                <div class="meta-label">Validity</div>
                <div class="meta-value">{{ $quotation->validity_days }} days from the date above</div>
                <div class="meta-label" style="margin-top: 10px">Payment terms</div>
                <div class="meta-value">{{ $quotation->payment_terms ?? $company['default_payment_terms'] }}</div>
            </td>
            <td>
                <div class="meta-label">Bank details</div>
                <div class="meta-value">
                    @forelse ($banks as $bank)
                        <div style="margin-bottom: 6px">
                            {{ $bank->account_name }}<br>
                            {{ $bank->bank_name }}@if ($bank->branch), {{ $bank->branch }}@endif<br>
                            Account: {{ $bank->account_number }} ({{ $bank->currency_code }})@if ($bank->swift)<br>
                            SWIFT: {{ $bank->swift }}@endif
                        </div>
                    @empty
                        Bank details are not set up yet.
                    @endforelse
                </div>
            </td>
        </tr>
    </table>

    <div class="signoff">
        <div class="signoff-line">
            {{ $company['signatory']['name'] }}<br>
            {{ $company['signatory']['title'] }}, {{ $company['name'] }}
        </div>
    </div>

    <div class="footer">This quotation was generated by the AEA Service Management System.</div>
</body>
</html>
