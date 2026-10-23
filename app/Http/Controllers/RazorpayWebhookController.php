<?php

namespace App\Http\Controllers;

use App\Models\SubscriptionPayment;
use App\Services\Payments\RazorpayGateway;
use App\Services\Subscriptions\SubscriptionCheckoutService;
use Illuminate\Http\Request;

/**
 * Server-to-server confirmation from Razorpay. Activates the plan even if the owner closed the
 * browser before the checkout callback ran; completing twice is harmless.
 */
class RazorpayWebhookController extends Controller
{
    public function __construct(
        private readonly RazorpayGateway $razorpay,
        private readonly SubscriptionCheckoutService $checkout,
    ) {}

    public function __invoke(Request $request)
    {
        if (! $this->razorpay->isValidWebhookSignature($request->getContent(), (string) $request->header('X-Razorpay-Signature'))) {
            return response()->json(['status' => false, 'message' => 'Invalid signature'], 400);
        }

        $event = $request->input('event');
        $entity = $request->input('payload.payment.entity', []);

        $payment = isset($entity['order_id'])
            ? SubscriptionPayment::where('provider_order_id', $entity['order_id'])->first()
            : null;

        // Not one of ours (or not a payment event): acknowledge so Razorpay stops retrying.
        if ($payment) {
            if (in_array($event, ['payment.captured', 'order.paid'], true)) {
                $this->checkout->complete($payment, (string) $entity['id'], ['event' => $event]);
            } elseif ($event === 'payment.failed') {
                $this->checkout->fail($payment, ['event' => $event, 'error' => $entity['error_description'] ?? null]);
            }
        }

        return response()->json(['status' => true]);
    }
}
