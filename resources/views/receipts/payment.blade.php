<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $title }} {{ $number }}</title>
<style>
    @page { margin: 26px 30px; }
    * { font-family: 'DejaVu Sans', sans-serif; }
    body { color: #1c1916; font-size: 10.5px; line-height: 1.45; }
    .muted { color: #7a6f65; }
    .copper { color: #a8643a; }
    table { width: 100%; border-collapse: collapse; }
    .head td { vertical-align: top; }
    .lib { font-family: 'DejaVu Serif', serif; font-size: 19px; font-weight: bold; letter-spacing: -0.2px; }
    .tag { font-size: 9px; letter-spacing: 2px; text-transform: uppercase; color: #a8643a; font-weight: bold; }
    .num { font-size: 12px; font-weight: bold; margin-top: 3px; }
    .rule { height: 2px; background: #a8643a; margin: 12px 0 14px; }
    .label { font-size: 8.5px; letter-spacing: 1.4px; text-transform: uppercase; color: #8a7e72; margin-bottom: 2px; }
    .who { font-size: 13px; font-weight: bold; }
    .items { margin-top: 14px; }
    .items th { text-align: left; font-size: 8.5px; letter-spacing: 1.2px; text-transform: uppercase; color: #8a7e72; font-weight: normal; padding: 6px 0; border-bottom: 1px solid #e6ded3; }
    .items td { padding: 9px 0; border-bottom: 1px solid #efe8de; vertical-align: top; }
    .right { text-align: right; }
    .total { margin-top: 14px; background: #f6f1ea; border-radius: 6px; }
    .total td { padding: 11px 14px; }
    .total .amt { font-size: 20px; font-weight: bold; text-align: right; }
    .words { font-size: 9.5px; color: #5a5048; padding: 0 14px 11px !important; }
    .pill { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 9px; font-weight: bold; }
    .paid { background: #e6f4ec; color: #0f6b3d; }
    .partial { background: #fdf0dc; color: #8a5300; }
    .refund { background: #fde8e4; color: #9f2d13; }
    .foot { position: fixed; bottom: 0; left: 0; right: 0; font-size: 8.5px; color: #8a7e72; border-top: 1px solid #e6ded3; padding-top: 7px; }
    .foot img { height: 10px; vertical-align: middle; }
</style>
</head>
<body>
    <table class="head">
        <tr>
            <td style="width: 62%;">
                <div class="lib">{{ $library['name'] }}</div>
                @if ($library['address'])<div class="muted" style="margin-top: 3px;">{{ $library['address'] }}</div>@endif
                @if ($library['phone'])<div class="muted">Phone: {{ $library['phone'] }}</div>@endif
            </td>
            <td class="right">
                <div class="tag">{{ $title }}</div>
                <div class="num">{{ $number }}</div>
                <div class="muted">{{ $date }}</div>
            </td>
        </tr>
    </table>

    <div class="rule"></div>

    <table>
        <tr>
            <td style="width: 60%;">
                <div class="label">{{ $refund ? 'Refunded to' : 'Received from' }}</div>
                <div class="who">{{ $member['name'] }}</div>
                @if ($member['phone'])<div class="muted">{{ $member['phone'] }}</div>@endif
            </td>
            <td class="right" style="vertical-align: bottom;">
                <div class="label">Status</div>
                @if ($refund)
                    <span class="pill refund">Refunded</span>
                @else
                    <span class="pill {{ $status === 'Partial' ? 'partial' : 'paid' }}">{{ $status }}</span>
                @endif
            </td>
        </tr>
    </table>

    <table class="items">
        <tr><th>Description</th><th class="right">Amount</th></tr>
        <tr>
            <td>
                <strong>{{ $refund ? 'Refund of library fee' : 'Library fee' }}</strong>
                @foreach ($seats as $seat)<div class="muted">{{ $seat }}</div>@endforeach
                @if ($period)<div class="muted">{{ $refund ? 'For' : 'Covers' }} {{ $period }}</div>@endif
                @if ($notes)<div class="muted">{{ $notes }}</div>@endif
            </td>
            <td class="right">₹{{ number_format($amount, $amount == floor($amount) ? 0 : 2) }}</td>
        </tr>
    </table>

    <table class="total">
        <tr>
            <td>
                <div class="label" style="margin: 0;">{{ $refund ? 'Amount refunded' : 'Amount received' }}</div>
                <div class="muted">{{ $refund ? 'Paid back by' : 'Paid by' }} {{ $mode }}</div>
            </td>
            <td class="amt">₹{{ number_format($amount, $amount == floor($amount) ? 0 : 2) }}</td>
        </tr>
        <tr><td colspan="2" class="words">{{ $amountWords }}</td></tr>
    </table>

    @if (! $refund && $status === 'Partial')
        <p class="muted" style="margin-top: 10px;">This was a part payment; the rest of the fee is still due.</p>
    @endif

    <div class="foot">
        <table>
            <tr>
                <td>Computer-generated receipt; no signature needed. Please keep it for your records.</td>
                <td class="right">Made with <img src="{{ public_path('brand/librispace-wordmark.png') }}" alt="LibriSpace"></td>
            </tr>
        </table>
    </div>
</body>
</html>
