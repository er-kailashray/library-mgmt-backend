<?php

namespace App\Services\Subscriptions;

use App\Models\Library;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Services\Payments\RazorpayGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

/**
 * Buying a plan: create a Razorpay order, hand the app a signed checkout page, then - once Razorpay
 * confirms (checkout callback or webhook, whichever arrives first) - activate the plan exactly once.
 */
class SubscriptionCheckoutService
{
    public function __construct(
        private readonly RazorpayGateway $razorpay,
        private readonly SubscriptionService $subscriptions,
    ) {}

    public function start(Library $library, SubscriptionPlan $plan, string $returnUrl): SubscriptionPayment
    {
        $subscription = $this->subscriptions->forLibrary($library);

        $payment = SubscriptionPayment::create([
            'subscription_id' => $subscription->id,
            'plan_id' => $plan->id,
            'amount' => $plan->price,
            'currency' => 'INR',
            'provider' => 'razorpay',
            'status' => 'created',
            'payload' => ['return_url' => $returnUrl],
        ]);

        try {
            $order = $this->razorpay->createOrder(
                (int) round($plan->price * 100),
                'sub_'.$payment->id,
                ['library_id' => (string) $library->id, 'plan' => $plan->code],
            );
        } catch (\Throwable $e) {
            // Don't leave a "created" payment that can never be paid.
            $this->fail($payment, ['error' => 'order_creation_failed']);
            throw $e;
        }

        $payment->update(['provider_order_id' => $order['id']]);

        return $payment;
    }

    /** Short-lived signed link to the checkout page for this payment (opened in the in-app browser). */
    public function payUrl(SubscriptionPayment $payment): string
    {
        return URL::temporarySignedRoute('subscription.pay', now()->addMinutes(30), ['payment' => $payment->id]);
    }

    /**
     * Marks the payment paid and extends the subscription. Safe to call more than once (callback and
     * webhook both do): only the first call changes anything.
     *
     * @param  array<string, mixed>  $providerData
     */
    public function complete(SubscriptionPayment $payment, string $providerPaymentId, array $providerData = []): void
    {
        DB::transaction(function () use ($payment, $providerPaymentId, $providerData) {
            $payment = SubscriptionPayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($payment->status === 'paid') {
                return;
            }

            $payment->update([
                'status' => 'paid',
                'paid_at' => now(),
                'provider_payment_id' => $providerPaymentId,
                'invoice_number' => sprintf('LIB-%s-%06d', now()->format('Y'), $payment->id),
                'payload' => array_merge($payment->payload ?? [], ['provider' => $providerData]),
            ]);

            $subscription = $payment->subscription()->lockForUpdate()->firstOrFail();
            $plan = $payment->plan;

            // A new plan starts when the current trial/plan ends, so paying early never loses days;
            // once that's in the past (grace or expired) it starts today.
            $startsFrom = $subscription->ends_at->isFuture() ? $subscription->ends_at->copy() : now();

            $subscription->update([
                'plan_id' => $plan->id,
                'provider' => 'razorpay',
                'ends_at' => $startsFrom->addMonthsNoOverflow($plan->duration_months),
                'cancelled_at' => null,
            ]);
        });
    }

    /** @param  array<string, mixed>  $providerData */
    public function fail(SubscriptionPayment $payment, array $providerData = []): void
    {
        if ($payment->status === 'paid') {
            return;
        }

        $payment->update([
            'status' => 'failed',
            'payload' => array_merge($payment->payload ?? [], ['provider' => $providerData]),
        ]);
    }

    /** Where to send the in-app browser afterwards, e.g. "mobileapp://subscription?result=success". */
    public function returnUrl(SubscriptionPayment $payment, string $result): string
    {
        $base = $payment->payload['return_url'] ?? 'mobileapp://subscription';

        return $base.(str_contains($base, '?') ? '&' : '?').'result='.$result;
    }
}
