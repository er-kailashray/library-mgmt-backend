<?php

namespace App\Services\Payments;

use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper over the Razorpay REST API and its signature checks (HMAC-SHA256), so nothing else
 * in the app talks to Razorpay directly.
 */
class RazorpayGateway
{
    private const API = 'https://api.razorpay.com/v1';

    public function keyId(): string
    {
        return (string) config('services.razorpay.key_id');
    }

    /**
     * @param  array<string, string>  $notes
     * @return array<string, mixed> the Razorpay order (id, amount, currency, ...)
     */
    public function createOrder(int $amountInPaise, string $receipt, array $notes = []): array
    {
        return Http::withBasicAuth($this->keyId(), (string) config('services.razorpay.key_secret'))
            ->acceptJson()
            ->post(self::API.'/orders', [
                'amount' => $amountInPaise,
                'currency' => 'INR',
                'receipt' => $receipt,
                'notes' => $notes,
            ])
            ->throw()
            ->json();
    }

    /** The signature Checkout returns after a successful payment: HMAC(order_id|payment_id, key secret). */
    public function isValidPaymentSignature(string $orderId, string $paymentId, string $signature): bool
    {
        $expected = hash_hmac('sha256', $orderId.'|'.$paymentId, (string) config('services.razorpay.key_secret'));

        return hash_equals($expected, $signature);
    }

    /** Webhooks are signed over the raw request body with the webhook secret. */
    public function isValidWebhookSignature(string $rawBody, string $signature): bool
    {
        $secret = (string) config('services.razorpay.webhook_secret');
        if ($secret === '' || $signature === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $rawBody, $secret), $signature);
    }
}
