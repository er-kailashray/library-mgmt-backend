<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Services\Receipts\AmountInWords;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsLibrary;
use Tests\TestCase;

class ReceiptTest extends TestCase
{
    use BuildsLibrary, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-20 10:00:00');
        $this->createLibrary(seats: 5, monthlyFee: 1500);
    }

    public function test_a_recorded_payment_comes_with_a_receipt_pdf(): void
    {
        $member = $this->admit('Rahul Sharma', 1, '2026-10-10');

        $url = $this->actingAs($this->owner)->postJson('/api/payments', [
            'member_id' => $member->id,
            'amount' => 1500,
            'payment_mode' => 'upi',
        ])->assertCreated()->json('data.receipt_url');

        $this->assertNotEmpty($url);
        $this->app['auth']->forgetGuards();

        // Opens without signing in (the member gets the link on WhatsApp).
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_a_changed_receipt_link_is_refused(): void
    {
        $member = $this->admit('Rahul Sharma', 1, '2026-10-10');
        $other = $this->recordPayment($this->admit('Someone Else', 2, '2026-10-10'), 900, '2026-10-19');
        $payment = $this->recordPayment($member, 1500, '2026-10-20');
        $url = $this->actingAs($this->owner)->getJson("/api/members/{$member->id}")->json('data.payments.0.receipt_url');

        $this->assertStringContainsString("/receipts/payments/{$payment->id}", $url);
        // Pointing the link at another payment breaks the signature.
        $this->get(str_replace("/payments/{$payment->id}", "/payments/{$other->id}", $url))->assertForbidden();
        $this->get(strtok($url, '?'))->assertForbidden();
    }

    public function test_admission_with_a_payment_returns_its_receipt(): void
    {
        $response = $this->actingAs($this->owner)->postJson('/api/admission', [
            'name' => 'New Student',
            'phone' => '9876501234',
            'allocations' => [['batch_id' => $this->batch->id, 'seat_id' => $this->library->seats()->where('seat_number', 2)->value('id')]],
            'payment' => ['amount' => 1500, 'payment_mode' => 'cash'],
        ])->assertOk();

        $this->get($response->json('receipt.url'))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('RC-', $response->json('receipt.number'));
    }

    public function test_paid_plans_have_an_invoice_pdf(): void
    {
        $plan = SubscriptionPlan::where('code', 'yearly')->firstOrFail();
        $subscription = Subscription::firstOrCreate(['library_id' => $this->library->id], ['starts_at' => now(), 'ends_at' => now()->addYear()]);
        SubscriptionPayment::create([
            'subscription_id' => $subscription->id,
            'plan_id' => $plan->id,
            'amount' => $plan->price,
            'currency' => 'INR',
            'provider' => 'razorpay',
            'status' => 'paid',
            'paid_at' => now(),
            'invoice_number' => 'LIB-2026-000001',
            'payload' => ['period' => ['starts' => '2026-10-20', 'ends' => '2027-10-20']],
        ]);

        $url = $this->actingAs($this->owner)->getJson('/api/subscription/payments')->assertOk()->json('data.0.invoice_url');
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_amounts_are_written_in_indian_words(): void
    {
        $this->assertSame('Rupees One Thousand Seven Hundred Ninety Nine Only', AmountInWords::rupees(1799));
        $this->assertSame('Rupees One Lakh Twenty Thousand Only', AmountInWords::rupees(120000));
        $this->assertSame('Rupees Twelve and Fifty Paise Only', AmountInWords::rupees(12.5));
    }
}
