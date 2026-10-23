<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Librum · {{ $planName }} plan</title>
    <style>
        :root { --bg: #FDFCF9; --fg: #171614; --muted: #78716C; --accent: #E26D4D; --card: #FFFFFF; --border: #E7E5E4; }
        @media (prefers-color-scheme: dark) { :root { --bg: #0C0A09; --fg: #FAFAF9; --muted: #A8A29E; --card: #171615; --border: #292524; } }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px 16px;
               background: var(--bg); color: var(--fg); font-family: -apple-system, system-ui, "Segoe UI", Roboto, sans-serif; }
        .card { width: 100%; max-width: 380px; background: var(--card); border: 1px solid var(--border); border-radius: 16px; padding: 24px; text-align: center; }
        h1 { font-size: 18px; margin: 0 0 4px; }
        p { color: var(--muted); font-size: 14px; margin: 0 0 20px; line-height: 1.4; }
        .price { font-size: 28px; font-weight: 800; margin: 8px 0 20px; }
        button { width: 100%; border: 0; border-radius: 12px; padding: 14px; font-size: 15px; font-weight: 700; background: var(--accent); color: #fff; }
        a { display: inline-block; margin-top: 14px; color: var(--muted); font-size: 13px; }
    </style>
</head>
<body>
    <div class="card">
        <h1>{{ $planName }} plan</h1>
        <p>{{ $libraryName }}</p>
        <div class="price">₹{{ number_format($amountInPaise / 100) }}</div>
        <button id="pay" type="button">Pay securely with Razorpay</button>
        <a href="{{ $cancelUrl }}">Cancel and go back to the app</a>
    </div>

    <script src="https://checkout.razorpay.com/v1/checkout.js"></script>
    <script>
        // Redirect mode: Razorpay posts the result to callback_url, where the server verifies the
        // signature before activating anything.
        var checkout = new Razorpay({
            key: @json($keyId),
            order_id: @json($orderId),
            amount: @json($amountInPaise),
            currency: "INR",
            name: "Librum",
            description: @json($planName . ' plan · ' . $libraryName),
            prefill: { name: @json($ownerName), contact: @json('+91' . $ownerPhone) },
            theme: { color: "#E26D4D" },
            callback_url: @json($callbackUrl),
            redirect: true,
            modal: { ondismiss: function () { window.location.href = @json($cancelUrl); } }
        });
        document.getElementById("pay").addEventListener("click", function () { checkout.open(); });
        checkout.open();
    </script>
</body>
</html>
