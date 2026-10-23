<?php

namespace App\Http\Controllers;

use App\Models\SubscriptionPayment;
use App\Services\Payments\RazorpayGateway;
use App\Services\Subscriptions\SubscriptionCheckoutService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * The checkout page the app opens in its in-app browser. Both routes are reachable only through
 * signed URLs (no login in the browser), and the payment is only trusted after its Razorpay
 * signature checks out.
 */
class SubscriptionPaymentPageController extends Controller
{
    public function __construct(
        private readonly RazorpayGateway $razorpay,
        private readonly SubscriptionCheckoutService $checkout,
    ) {}

    public function show(SubscriptionPayment $payment)
    {
        if ($payment->status === 'paid') {
            return redirect()->away($this->checkout->returnUrl($payment, 'success'));
        }

        $library = $payment->subscription->library;

        return view('subscription.pay', [
            'keyId' => $this->razorpay->keyId(),
            'orderId' => $payment->provider_order_id,
            'amountInPaise' => (int) round($payment->amount * 100),
            'planName' => $payment->plan->name,
            'libraryName' => $library->library_name,
            'ownerName' => $library->user->name,
            'ownerPhone' => $library->user->phone,
            'callbackUrl' => URL::temporarySignedRoute('subscription.pay.callback', now()->addMinutes(30), ['payment' => $payment->id]),
            'cancelUrl' => $this->checkout->returnUrl($payment, 'cancelled'),
        ]);
    }

    /** Razorpay Checkout posts here (redirect mode) when the payment succeeds or fails. */
    public function callback(Request $request, SubscriptionPayment $payment)
    {
        $paymentId = (string) $request->input('razorpay_payment_id', '');
        $orderId = (string) $request->input('razorpay_order_id', '');
        $signature = (string) $request->input('razorpay_signature', '');

        $genuine = $paymentId !== ''
            && $orderId === $payment->provider_order_id
            && $this->razorpay->isValidPaymentSignature($orderId, $paymentId, $signature);

        if ($genuine) {
            $this->checkout->complete($payment, $paymentId, ['razorpay_order_id' => $orderId, 'razorpay_payment_id' => $paymentId]);

            return redirect()->away($this->checkout->returnUrl($payment, 'success'));
        }

        $this->checkout->fail($payment, ['error' => $request->input('error')]);

        return redirect()->away($this->checkout->returnUrl($payment, 'failed'));
    }
}
