<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Invoice {{ $number }}</title>
<style>
    @page { margin: 40px 46px; }
    * { font-family: 'DejaVu Sans', sans-serif; }
    body { color: #1c1916; font-size: 11px; line-height: 1.5; }
    .muted { color: #7a6f65; }
    table { width: 100%; border-collapse: collapse; }
    td { vertical-align: top; }
    .right { text-align: right; }
    .title { font-family: 'DejaVu Serif', serif; font-size: 28px; font-weight: bold; letter-spacing: -0.3px; }
    .rule { height: 2px; background: #a8643a; margin: 18px 0 22px; }
    .label { font-size: 8.5px; letter-spacing: 1.5px; text-transform: uppercase; color: #8a7e72; margin-bottom: 3px; }
    .strong { font-weight: bold; font-size: 12.5px; }
    .meta td { padding: 2px 0; }
    .items { margin-top: 26px; }
    .items th { text-align: left; font-size: 8.5px; letter-spacing: 1.3px; text-transform: uppercase; color: #8a7e72; font-weight: normal; padding: 8px 0; border-bottom: 1px solid #e6ded3; }
    .items td { padding: 12px 0; border-bottom: 1px solid #efe8de; }
    .total { margin-top: 18px; background: #14110f; color: #f3ece2; }
    .total td { padding: 16px 18px; vertical-align: middle; }
    .total .amt { font-size: 24px; font-weight: bold; text-align: right; }
    .paid { display: inline-block; padding: 3px 10px; border-radius: 10px; font-size: 9.5px; font-weight: bold; background: #e6f4ec; color: #0f6b3d; }
    .foot { position: fixed; bottom: 0; left: 0; right: 0; font-size: 9px; color: #8a7e72; border-top: 1px solid #e6ded3; padding-top: 9px; }
</style>
</head>
<body>
    <table>
        <tr>
            <td>
                <img src="{{ public_path('brand/librispace-wordmark.png') }}" alt="LibriSpace" style="height: 22px;">
                <div class="muted" style="margin-top: 8px;">
                    {{ $seller['name'] }}@if ($seller['address'])<br>{{ $seller['address'] }}@endif
                    @if ($seller['email'])<br>{{ $seller['email'] }}@endif
                    @if ($seller['gstin'])<br>GSTIN: {{ $seller['gstin'] }}@endif
                </div>
            </td>
            <td class="right">
                <div class="title">Invoice</div>
                <span class="paid">PAID</span>
            </td>
        </tr>
    </table>

    <div class="rule"></div>

    <table>
        <tr>
            <td style="width: 55%;">
                <div class="label">Billed to</div>
                <div class="strong">{{ $buyer['library'] }}</div>
                <div class="muted">
                    {{ $buyer['owner'] }}@if ($buyer['phone']) · {{ $buyer['phone'] }}@endif
                    @if ($buyer['address'])<br>{{ $buyer['address'] }}@endif
                </div>
            </td>
            <td>
                <table class="meta">
                    <tr><td class="muted">Invoice no.</td><td class="right"><strong>{{ $number }}</strong></td></tr>
                    <tr><td class="muted">Date</td><td class="right">{{ $date }}</td></tr>
                    <tr><td class="muted">Paid by</td><td class="right">Online (Razorpay)</td></tr>
                    @if ($reference)<tr><td class="muted">Payment ref.</td><td class="right">{{ $reference }}</td></tr>@endif
                </table>
            </td>
        </tr>
    </table>

    <table class="items">
        <tr><th>Description</th><th class="right">Amount</th></tr>
        <tr>
            <td>
                <strong>{{ $item }}</strong>
                @if ($period)<div class="muted">Service period {{ $period }}</div>@endif
                <div class="muted">Study library management software, all features</div>
            </td>
            <td class="right">₹{{ number_format($amount, $amount == floor($amount) ? 0 : 2) }}</td>
        </tr>
    </table>

    <table class="total">
        <tr>
            <td>
                <div style="font-size: 8.5px; letter-spacing: 1.5px; text-transform: uppercase; color: #bdb2a6;">Total paid</div>
                <div style="color: #bdb2a6; font-size: 10px;">{{ $amountWords }}</div>
            </td>
            <td class="amt">₹{{ number_format($amount, $amount == floor($amount) ? 0 : 2) }}</td>
        </tr>
    </table>

    <p class="muted" style="margin-top: 22px;">Thank you for choosing LibriSpace. Plans don’t renew automatically. For billing questions write to {{ $seller['email'] }}.</p>

    <div class="foot">
        <table>
            <tr>
                <td>Computer-generated invoice; no signature needed.</td>
                <td class="right">LibriSpace is a product of {{ $seller['name'] }}</td>
            </tr>
        </table>
    </div>
</body>
</html>
