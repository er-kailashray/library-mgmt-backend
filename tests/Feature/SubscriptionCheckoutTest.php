<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsLibrary;
use Tests\TestCase;

class SubscriptionCheckoutTest extends TestCase
{
    use BuildsLibrary, RefreshDatabase;

    private const RETURN_URL = 'mobileapp://subscription';

    /** Flip to simulate Razorpay's order API failing. */
    private bool $razorpayDown = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-24 10:00:00');
        config([
            'services.razorpay.key_id' => 'rzp_test_key',
            'services.razorpay.key_secret' => 'test_secret',
            'services.razorpay.webhook_secret' => 'webhook_secret',
        ]);
        Http::fake(['api.razorpay.com/v1/orders' => fn () => $this->razorpayDown
            ? Http::response(['error' => ['description' => 'down']], 500)
            : Http::response(['id' => 'order_TEST1', 'amount' => 299900, 'currency' => 'INR'])]);
        $this->createLibrary(seats: 3);
        // Trial running until 24 Dec.
        Subscription::create(['library_id' => $this->library->id, 'starts_at' => '2026-10-24', 'ends_at' => '2026-12-24 10:00:00']);
    }

    private function startCheckout(string $plan = 'yearly'): SubscriptionPayment
    {
        $this->actingAs($this->owner)->postJson('/api/subscription/checkout', ['plan' => $plan, 'return_url' => self::RETURN_URL])
            ->assertOk()
            ->assertJsonStructure(['data' => ['payment_id', 'pay_url']]);

        return SubscriptionPayment::latest('id')->firstOrFail();
    }

    private function callbackUrl(SubscriptionPayment $payment): string
    {
        return \URL::temporarySignedRoute('subscription.pay.callback', now()->addMinutes(30), ['payment' => $payment->id]);
    }

    private function signature(string $orderId, string $paymentId): string
    {
        return hash_hmac('sha256', $orderId.'|'.$paymentId, 'test_secret');
    }

    private function webhook(array $body, ?string $signature = null)
    {
        $raw = json_encode($body);

        return $this->call('POST', '/api/webhooks/razorpay', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_RAZORPAY_SIGNATURE' => $signature ?? hash_hmac('sha256', $raw, 'webhook_secret'),
        ], $raw);
    }

    public function test_checkout_creates_a_razorpay_order_for_the_plan_price(): void
    {
        $payment = $this->startCheckout('yearly');

        $this->assertSame('order_TEST1', $payment->provider_order_id);
        $this->assertSame(2999.0, $payment->amount);
        $this->assertSame('created', $payment->status);
        Http::assertSent(fn ($request) => $request['amount'] === 299900 && $request['currency'] === 'INR');
    }

    public function test_checkout_only_returns_to_the_app(): void
    {
        $this->actingAs($this->owner)->postJson('/api/subscription/checkout', ['plan' => 'yearly', 'return_url' => 'https://evil.example'])
            ->assertStatus(422);
        $this->actingAs($this->owner)->postJson('/api/subscription/checkout', ['plan' => 'lifetime', 'return_url' => self::RETURN_URL])
            ->assertStatus(422);
    }

    public function test_expired_library_can_still_start_checkout(): void
    {
        Subscription::where('library_id', $this->library->id)->update(['ends_at' => '2026-09-01']);

        $this->startCheckout();
    }

    public function test_checkout_page_needs_the_signed_link(): void
    {
        $payment = $this->startCheckout();

        $this->get("/subscription/pay/{$payment->id}")->assertForbidden();
        $this->get(\URL::temporarySignedRoute('subscription.pay', now()->addMinutes(30), ['payment' => $payment->id]))
            ->assertOk()
            ->assertSee('order_TEST1')
            ->assertSee('rzp_test_key');
    }

    public function test_genuine_payment_activates_the_plan_after_the_remaining_trial(): void
    {
        $payment = $this->startCheckout('yearly');

        $this->post($this->callbackUrl($payment), [
            'razorpay_payment_id' => 'pay_ABC',
            'razorpay_order_id' => 'order_TEST1',
            'razorpay_signature' => $this->signature('order_TEST1', 'pay_ABC'),
        ])->assertRedirect(self::RETURN_URL.'?result=success');

        $subscription = Subscription::where('library_id', $this->library->id)->first();
        $this->assertSame('yearly', $subscription->plan->code);
        // Trial ends 24 Dec 2026, so the year runs to 24 Dec 2027 - no trial days lost.
        $this->assertSame('2027-12-24', $subscription->ends_at->toDateString());
        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertSame('LIB-2026-'.str_pad((string) $payment->id, 6, '0', STR_PAD_LEFT), $payment->fresh()->invoice_number);
    }

    public function test_forged_payment_signature_activates_nothing(): void
    {
        $payment = $this->startCheckout();

        $this->post($this->callbackUrl($payment), [
            'razorpay_payment_id' => 'pay_ABC',
            'razorpay_order_id' => 'order_TEST1',
            'razorpay_signature' => 'forged',
        ])->assertRedirect(self::RETURN_URL.'?result=failed');

        $this->assertNull(Subscription::where('library_id', $this->library->id)->value('plan_id'));
        $this->assertSame('failed', $payment->fresh()->status);
    }

    public function test_callback_and_webhook_together_extend_only_once(): void
    {
        $payment = $this->startCheckout('monthly');
        $paid = ['razorpay_payment_id' => 'pay_ABC', 'razorpay_order_id' => 'order_TEST1', 'razorpay_signature' => $this->signature('order_TEST1', 'pay_ABC')];

        $this->post($this->callbackUrl($payment), $paid);
        $this->webhook(['event' => 'payment.captured', 'payload' => ['payment' => ['entity' => ['id' => 'pay_ABC', 'order_id' => 'order_TEST1']]]])->assertOk();
        $this->post($this->callbackUrl($payment), $paid);

        $this->assertSame('2027-01-24', Subscription::where('library_id', $this->library->id)->first()->ends_at->toDateString());
    }

    public function test_webhook_alone_activates_the_plan(): void
    {
        $this->startCheckout('monthly');

        $this->webhook(['event' => 'payment.captured', 'payload' => ['payment' => ['entity' => ['id' => 'pay_XYZ', 'order_id' => 'order_TEST1']]]])->assertOk();

        $subscription = Subscription::where('library_id', $this->library->id)->first();
        $this->assertSame('monthly', $subscription->plan->code);
        $this->assertSame('2027-01-24', $subscription->ends_at->toDateString());
    }

    public function test_webhook_with_a_bad_signature_is_rejected(): void
    {
        $this->startCheckout();

        $this->webhook(['event' => 'payment.captured', 'payload' => ['payment' => ['entity' => ['id' => 'pay_XYZ', 'order_id' => 'order_TEST1']]]], 'forged')
            ->assertStatus(400);

        $this->assertNull(Subscription::where('library_id', $this->library->id)->value('plan_id'));
    }

    public function test_paying_after_expiry_starts_the_plan_today(): void
    {
        Subscription::where('library_id', $this->library->id)->update(['ends_at' => '2026-09-01']);
        $payment = $this->startCheckout('monthly');

        $this->post($this->callbackUrl($payment), [
            'razorpay_payment_id' => 'pay_ABC',
            'razorpay_order_id' => 'order_TEST1',
            'razorpay_signature' => $this->signature('order_TEST1', 'pay_ABC'),
        ]);

        $this->assertSame('2026-11-24', Subscription::where('library_id', $this->library->id)->first()->ends_at->toDateString());
        // And read-only mode is lifted straight away.
        $this->actingAs($this->owner)->getJson('/api/subscription')->assertJsonPath('data.state', 'active');
    }

    public function test_paid_payments_are_listed_as_invoices(): void
    {
        $payment = $this->startCheckout('yearly');
        $this->post($this->callbackUrl($payment), [
            'razorpay_payment_id' => 'pay_ABC',
            'razorpay_order_id' => 'order_TEST1',
            'razorpay_signature' => $this->signature('order_TEST1', 'pay_ABC'),
        ]);
        $this->startCheckout('monthly'); // started but never paid - not an invoice

        $this->actingAs($this->owner)->getJson('/api/subscription/payments')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.plan', 'Yearly')
            ->assertJsonPath('data.0.amount', 2999);
    }

    public function test_razorpay_outage_returns_a_friendly_error(): void
    {
        $this->razorpayDown = true;

        $this->actingAs($this->owner)->postJson('/api/subscription/checkout', ['plan' => 'yearly', 'return_url' => self::RETURN_URL])
            ->assertStatus(502);
        $this->assertSame('failed', SubscriptionPayment::latest('id')->value('status'));
    }
}
