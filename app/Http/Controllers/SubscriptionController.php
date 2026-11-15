<?php

namespace App\Http\Controllers;

use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Services\Receipts\ReceiptService;
use App\Services\Subscriptions\SubscriptionCheckoutService;
use App\Services\Subscriptions\SubscriptionService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly SubscriptionCheckoutService $checkout,
    ) {}

    public function show(Request $request)
    {
        return response()->json([
            'status' => true,
            'data' => $this->subscriptions->present($this->library($request)),
        ]);
    }

    /**
     * Starts buying a plan. Returns a signed checkout page for the app to open in its browser;
     * the browser comes back to `return_url` (the app's deep link) when done.
     */
    public function checkout(Request $request)
    {
        $validated = $request->validate([
            'plan' => ['required', 'string'],
            // Only the app's own deep links (and Expo Go's, in development), or a page of our own web
            // app (WEB_APP_URL) - never any other site, so the checkout can't be an open redirect.
            'return_url' => ['required', 'string', 'max:500', function (string $attribute, string $value, \Closure $fail) {
                if (! $this->isOwnReturnUrl($value)) {
                    $fail('The return url is not allowed.');
                }
            }],
        ]);

        $plan = SubscriptionPlan::where('code', $validated['plan'])->where('is_active', true)->first();
        if (! $plan) {
            return response()->json(['status' => false, 'message' => 'That plan is not available.'], 422);
        }

        try {
            $payment = $this->checkout->start($this->library($request), $plan, $validated['return_url']);
        } catch (RequestException) {
            return response()->json(['status' => false, 'message' => 'Could not start the payment. Please try again.'], 502);
        }

        return response()->json([
            'status' => true,
            'data' => [
                'payment_id' => $payment->id,
                'pay_url' => $this->checkout->payUrl($payment),
            ],
        ]);
    }

    private function isOwnReturnUrl(string $url): bool
    {
        if (preg_match('/^(mobileapp|exp|exps):\/\//', $url)) {
            return true;
        }
        $webApp = rtrim((string) config('app.web_app_url'), '/');

        return $webApp !== '' && str_starts_with($url, $webApp.'/');
    }

    /** Paid invoices, newest first. */
    public function payments(Request $request)
    {
        $subscription = $this->subscriptions->forLibrary($this->library($request));

        return response()->json([
            'status' => true,
            'data' => $subscription->payments()
                ->where('status', 'paid')
                ->with('plan')
                ->orderByDesc('paid_at')
                ->get()
                ->map(fn (SubscriptionPayment $payment) => [
                    'id' => $payment->id,
                    'invoice_number' => $payment->invoice_number,
                    'invoice_url' => app(ReceiptService::class)->invoiceUrl($payment),
                    'plan' => $payment->plan->name,
                    'amount' => $payment->amount,
                    'paid_at' => $payment->paid_at->toIso8601String(),
                ])
                ->all(),
        ]);
    }
}
