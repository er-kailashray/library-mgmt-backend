<?php

namespace Tests\Feature;

use App\Models\SeatAllocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsLibrary;
use Tests\TestCase;

class PaymentApiTest extends TestCase
{
    use BuildsLibrary, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-20 10:00:00');
        $this->createLibrary(seats: 5, monthlyFee: 1500);
    }

    public function test_lists_pending_dues_then_recorded_payments(): void
    {
        $paid = $this->admit('Rahul Sharma', 1, '2026-11-01');
        $this->admit('Due Member', 2, '2026-10-10');
        $this->recordPayment($paid, 1500, '2026-10-01');

        $this->actingAs($this->owner)->getJson('/api/payments')
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.member_name', 'Due Member')
            ->assertJsonPath('data.0.status', 'PENDING')
            ->assertJsonPath('data.0.amount', 1500)
            ->assertJsonPath('data.0.date', '2026-10-10')
            ->assertJsonPath('data.1.member_name', 'Rahul Sharma')
            ->assertJsonPath('data.1.status', 'PAID')
            ->assertJsonPath('data.1.date', '2026-10-01')
            ->assertJsonPath('data.1.method', 'UPI');
    }

    public function test_full_payment_is_paid_and_extends_renewal(): void
    {
        $member = $this->admit('Due Member', 1, '2026-10-10');

        $this->actingAs($this->owner)->postJson('/api/payments', [
            'member_id' => $member->id,
            'amount' => 1500,
            'payment_mode' => 'cash',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'PAID')
            ->assertJsonPath('data.method', 'Cash')
            ->assertJsonPath('data.date', '2026-10-20');

        $this->assertSame('2026-11-10', SeatAllocation::where('member_id', $member->id)->value('renewal_date'));
    }

    public function test_short_payment_is_partial_and_keeps_renewal(): void
    {
        $member = $this->admit('Due Member', 1, '2026-10-10');

        $this->actingAs($this->owner)->postJson('/api/payments', [
            'member_id' => $member->id,
            'amount' => 500,
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'PARTIAL');

        $this->assertSame('2026-10-10', SeatAllocation::where('member_id', $member->id)->value('renewal_date'));
    }

    public function test_cannot_record_payment_for_another_librarys_member(): void
    {
        $myOwner = $this->owner;

        $this->createLibrary();
        $stranger = $this->admit('Stranger', 1, '2026-10-10');

        $this->actingAs($myOwner)->postJson('/api/payments', [
            'member_id' => $stranger->id,
            'amount' => 1500,
        ])->assertUnprocessable()->assertJsonValidationErrors('member_id');
    }

    public function test_validates_payment_input(): void
    {
        $member = $this->admit('Member', 1, '2026-10-10');

        $this->actingAs($this->owner)->postJson('/api/payments', [
            'member_id' => $member->id,
            'amount' => 0,
            'payment_mode' => 'cheque',
            'payment_date' => '2026-12-01',
        ])->assertUnprocessable()->assertJsonValidationErrors(['amount', 'payment_mode', 'payment_date']);
    }

    public function test_partial_payment_reduces_the_pending_due(): void
    {
        $member = $this->admit('Due Member', 1, '2026-10-10');

        $this->actingAs($this->owner)->postJson('/api/payments', [
            'member_id' => $member->id,
            'amount' => 500,
        ])->assertCreated();

        $this->actingAs($this->owner)->getJson('/api/payments')
            ->assertJsonPath('data.0.status', 'PENDING')
            ->assertJsonPath('data.0.amount', 1000);
    }

    public function test_partial_payments_that_add_up_to_the_fee_settle_the_month(): void
    {
        $member = $this->admit('Due Member', 1, '2026-10-10');

        $this->actingAs($this->owner)->postJson('/api/payments', ['member_id' => $member->id, 'amount' => 500])
            ->assertJsonPath('data.status', 'PARTIAL');
        $this->actingAs($this->owner)->postJson('/api/payments', ['member_id' => $member->id, 'amount' => 1000])
            ->assertJsonPath('data.status', 'PAID');

        $this->assertSame('2026-11-10', SeatAllocation::where('member_id', $member->id)->value('renewal_date'));

        // Settled - no pending row left, only the two recorded payments.
        $this->actingAs($this->owner)->getJson('/api/payments')->assertJsonCount(2, 'data');

        // The next month starts fresh: earlier partials don't carry over.
        $this->actingAs($this->owner)->postJson('/api/payments', ['member_id' => $member->id, 'amount' => 500])
            ->assertJsonPath('data.status', 'PARTIAL');
    }

    public function test_paying_several_overdue_months_extends_renewal_by_each_month(): void
    {
        // Renewed on Aug 10, checked on Oct 20: Aug, Sep and Oct are all missed.
        $member = $this->admit('Due Member', 1, '2026-08-10');

        $this->actingAs($this->owner)->postJson('/api/payments', ['member_id' => $member->id, 'amount' => 3000])
            ->assertJsonPath('data.status', 'PAID');

        $this->assertSame('2026-10-10', SeatAllocation::where('member_id', $member->id)->value('renewal_date'));
    }
}
