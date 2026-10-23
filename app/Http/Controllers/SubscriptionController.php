<?php

namespace App\Http\Controllers;

use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
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
            // Only the app's own deep links (and Expo Go's, in development) - never a web URL.
            'return_url' => ['required', 'string', 'max:500', 'regex:/^(mobileapp|exp|exps):\/\//'],
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
                    'plan' => $payment->plan->name,
                    'amount' => $payment->amount,
                    'paid_at' => $payment->paid_at->toIso8601String(),
                ])
                ->all(),
        ]);
    }
}
