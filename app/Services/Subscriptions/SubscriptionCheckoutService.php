<?php

namespace App\Services\Subscriptions;

use App\Models\Library;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Services\Payments\RazorpayGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

    /** How long a checkout link (and an unpaid order behind it) stays usable. */
    public const CHECKOUT_MINUTES = 120;

    /**
     * Starts paying for a plan. If this library already has an unpaid order for the same plan and
     * price from the last CHECKOUT_MINUTES (Continue tapped twice, or a second device), that order
     * is reused rather than a new one made: Razorpay accepts only one payment per order, so the
     * owner can't be charged twice for one purchase.
     */
    public function start(Library $library, SubscriptionPlan $plan, string $returnUrl): SubscriptionPayment
    {
        $subscription = $this->subscriptions->forLibrary($library);

        $orderError = null;

        $payment = DB::transaction(function () use ($subscription, $plan, $returnUrl, &$orderError) {
            // Serialises two checkouts started at the same moment for this library.
            $subscription->newQuery()->whereKey($subscription->id)->lockForUpdate()->first();

            $open = SubscriptionPayment::where('subscription_id', $subscription->id)
                ->where('plan_id', $plan->id)
                ->where('amount', $plan->price)
                ->whereIn('status', ['created', 'failed'])
                ->whereNotNull('provider_order_id')
                ->where('created_at', '>=', now()->subMinutes(self::CHECKOUT_MINUTES))
                ->latest('id')
                ->first();

            if ($open) {
                $open->update([
                    'status' => 'created',
                    'payload' => array_merge($open->payload ?? [], ['return_url' => $returnUrl]),
                ]);

                return $open;
            }

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
                    ['library_id' => (string) $subscription->library_id, 'plan' => $plan->code],
                );
            } catch (\Throwable $e) {
                // Don't leave a "created" payment that can never be paid. It's marked failed here
                // and the error rethrown after commit, so the rollback doesn't undo the mark.
                $this->fail($payment, ['error' => 'order_creation_failed']);
                $orderError = $e;

                return $payment;
            }

            $payment->update(['provider_order_id' => $order['id']]);

            return $payment;
        });

        if ($orderError) {
            throw $orderError;
        }

        return $payment;
    }

    /** Short-lived signed link to the checkout page for this payment (opened in the in-app browser). */
    public function payUrl(SubscriptionPayment $payment): string
    {
        return URL::temporarySignedRoute('subscription.pay', now()->addMinutes(self::CHECKOUT_MINUTES), ['payment' => $payment->id]);
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
                if ($payment->provider_payment_id !== $providerPaymentId) {
                    $this->recordExtraPayment($payment, $providerPaymentId, $providerData);
                }

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

    /**
     * Marks the payment failed, unless it has been paid. Locked like complete(), so a failure from
     * an earlier attempt that arrives alongside the success can never overwrite "paid".
     *
     * @param  array<string, mixed>  $providerData
     */
    public function fail(SubscriptionPayment $payment, array $providerData = []): void
    {
        DB::transaction(function () use ($payment, $providerData) {
            $payment = SubscriptionPayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($payment->status === 'paid') {
                return;
            }

            $payment->update([
                'status' => 'failed',
                'payload' => array_merge($payment->payload ?? [], ['provider' => $providerData]),
            ]);
        });
    }

    /**
     * A second captured payment on an order that is already paid (e.g. a late UPI confirmation).
     * The plan isn't extended again; the money is kept on record as "refund_due" so it can be
     * refunded from the Razorpay dashboard. Recorded once per Razorpay payment id.
     *
     * @param  array<string, mixed>  $providerData
     */
    private function recordExtraPayment(SubscriptionPayment $paid, string $providerPaymentId, array $providerData): void
    {
        if (SubscriptionPayment::where('provider_payment_id', $providerPaymentId)->exists()) {
            return;
        }

        SubscriptionPayment::create([
            'subscription_id' => $paid->subscription_id,
            'plan_id' => $paid->plan_id,
            'amount' => $paid->amount,
            'currency' => $paid->currency,
            'provider' => $paid->provider,
            'provider_order_id' => $paid->provider_order_id,
            'provider_payment_id' => $providerPaymentId,
            'status' => 'refund_due',
            'payload' => ['duplicate_of' => $paid->id, 'provider' => $providerData],
        ]);

        Log::warning('Duplicate subscription payment captured; refund needed', [
            'subscription_payment_id' => $paid->id,
            'order_id' => $paid->provider_order_id,
            'razorpay_payment_id' => $providerPaymentId,
        ]);
    }

    /** Where to send the in-app browser afterwards, e.g. "mobileapp://subscription?result=success". */
    public function returnUrl(SubscriptionPayment $payment, string $result): string
    {
        $base = $payment->payload['return_url'] ?? 'mobileapp://subscription';

        return $base.(str_contains($base, '?') ? '&' : '?').'result='.$result;
    }
}
