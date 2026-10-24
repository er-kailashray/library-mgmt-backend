<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsLibrary;
use Tests\TestCase;

/**
 * The real-life cases the Record Payment flow has to get right: which days a payment pays for,
 * renewing early, coming back after a break, and giving money back to someone who leaves.
 * The library's monthly fee is 1000 (BuildsLibrary).
 */
class BillingScenariosTest extends TestCase
{
    use BuildsLibrary, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-10 10:00:00');
        $this->createLibrary();
    }

    private function pay(Member $member, float $amount, array $extra = [])
    {
        return $this->actingAs($this->owner)->postJson('/api/payments', ['member_id' => $member->id, 'amount' => $amount, 'payment_mode' => 'cash'] + $extra);
    }

    private function renewalDate(Member $member): string
    {
        return substr($member->allocations()->first()->renewal_date, 0, 10);
    }

    // ---- which days a payment pays for

    public function test_payment_covers_the_month_from_the_paid_through_date(): void
    {
        $member = $this->admit('Rahul Sharma', 1, '2026-11-01');

        $this->pay($member, 1000)
            ->assertCreated()
            ->assertJsonPath('data.period_start', '2026-11-01')
            ->assertJsonPath('data.period_end', '2026-11-30')
            ->assertJsonPath('data.recorded_at', '2026-11-10T10:00:00+05:30');

        $this->assertSame('2026-12-01', $this->renewalDate($member));
    }

    public function test_two_months_paid_together_cover_both(): void
    {
        $member = $this->admit('Rahul Sharma', 1, '2026-11-01');

        $this->pay($member, 2000)
            ->assertJsonPath('data.period_start', '2026-11-01')
            ->assertJsonPath('data.period_end', '2026-12-31');
    }

    public function test_renewing_early_covers_the_month_after_the_paid_time(): void
    {
        $member = $this->admit('Rahul Sharma', 1, '2026-11-20');

        $this->pay($member, 1000)
            ->assertJsonPath('data.status', 'PAID')
            ->assertJsonPath('data.period_start', '2026-11-20')
            ->assertJsonPath('data.period_end', '2026-12-19');

        $this->assertSame('2026-12-20', $this->renewalDate($member));
    }

    public function test_part_payment_names_the_month_it_is_toward_without_renewing(): void
    {
        $member = $this->admit('Rahul Sharma', 1, '2026-11-01');

        $this->pay($member, 400)
            ->assertJsonPath('data.status', 'PARTIAL')
            ->assertJsonPath('data.period_start', '2026-11-01')
            ->assertJsonPath('data.period_end', '2026-11-30');

        $this->assertSame('2026-11-01', $this->renewalDate($member));
    }

    public function test_member_detail_shows_paid_through_and_part_payment_credit(): void
    {
        $member = $this->admit('Rahul Sharma', 1, '2026-11-20');
        $this->pay($member, 300);

        $this->actingAs($this->owner)->getJson("/api/members/{$member->id}")
            ->assertOk()
            ->assertJsonPath('data.paid_through', '2026-11-20')
            ->assertJsonPath('data.partial_credit', 300);
    }

    // ---- back after a break

    public function test_restart_with_payment_skips_the_unpaid_gap(): void
    {
        // Paid till 1 Nov, comes back on 8 Nov: 1-7 Nov is never charged.
        $member = $this->admit('Rahul Sharma', 1, '2026-11-01');

        $this->pay($member, 1000, ['start_date' => '2026-11-08'])
            ->assertCreated()
            ->assertJsonPath('data.period_start', '2026-11-08')
            ->assertJsonPath('data.period_end', '2026-12-07');

        $this->assertSame('2026-12-08', $this->renewalDate($member));
        $this->actingAs($this->owner)->getJson("/api/members/{$member->id}")->assertJsonPath('data.payment_status', 'Paid');
    }

    public function test_restart_now_pay_later_moves_the_due_date(): void
    {
        $member = $this->admit('Rahul Sharma', 1, '2026-10-01');

        $this->actingAs($this->owner)->postJson("/api/members/{$member->id}/restart", ['start_date' => '2026-11-05'])->assertOk();

        $this->assertSame('2026-11-05', $this->renewalDate($member));
        // One month owed from 5 Nov - not October's as well.
        $this->actingAs($this->owner)->getJson("/api/members/{$member->id}")
            ->assertJsonPath('data.payment_status', 'Due')
            ->assertJsonPath('data.due_amount', 1000);
    }

    public function test_restart_cannot_go_back_before_the_paid_time_ends(): void
    {
        $member = $this->admit('Rahul Sharma', 1, '2026-11-01');

        $this->pay($member, 1000, ['start_date' => '2026-10-25'])->assertStatus(422)->assertJsonValidationErrors('start_date');
        $this->assertSame('2026-11-01', $this->renewalDate($member));
    }

    public function test_restart_cannot_be_too_far_ahead(): void
    {
        $member = $this->admit('Rahul Sharma', 1, '2026-11-01');

        $this->actingAs($this->owner)->postJson("/api/members/{$member->id}/restart", ['start_date' => '2027-01-15'])
            ->assertStatus(422)->assertJsonValidationErrors('start_date');
    }

    // ---- leaving early, with money back

    public function test_member_detail_offers_full_and_partial_refund_when_paid_ahead(): void
    {
        // Paid till 30 Nov (no recorded period, so "full" falls back to one month's fee).
        // Cycle 30 Oct - 30 Nov is 31 days; 20 unused from today: 1000 / 31 * 20 = 645.
        $member = $this->admit('Rahul Sharma', 1, '2026-11-30');

        $this->actingAs($this->owner)->getJson("/api/members/{$member->id}")
            ->assertJsonPath('data.refund_options.full', 1000)
            ->assertJsonPath('data.refund_options.partial', 645)
            ->assertJsonPath('data.refund_options.unused_days', 20)
            ->assertJsonPath('data.refund_options.paid_through', '2026-11-30');
    }

    public function test_no_refund_offered_when_nothing_is_paid_ahead(): void
    {
        $member = $this->admit('Rahul Sharma', 1, '2026-11-01');

        $this->actingAs($this->owner)->getJson("/api/members/{$member->id}")->assertJsonPath('data.refund_options', null);
    }

    public function test_leaving_with_a_refund_records_it_and_nets_it_off_collections(): void
    {
        $member = $this->admit('Rahul Sharma', 1, '2026-11-08');
        $this->pay($member, 1000); // 8 Nov - 7 Dec

        $this->actingAs($this->owner)->postJson("/api/members/{$member->id}/deactivate", ['refund_amount' => 600, 'refund_mode' => 'upi'])->assertOk();

        $refund = Payment::where('member_id', $member->id)->where('status', 'refunded')->firstOrFail();
        $this->assertEquals(-600, $refund->amount);
        $this->assertSame('2026-12-07', $refund->period_end->toDateString());
        $this->assertSame('inactive', $member->fresh()->status);

        $this->actingAs($this->owner)->getJson('/api/dashboard')->assertJsonPath('data.monthly_collection', 400);
        $this->actingAs($this->owner)->getJson("/api/members/{$member->id}")
            ->assertJsonPath('data.payments.0.status', 'REFUNDED')
            ->assertJsonPath('data.payments.0.amount', -600);
    }

    public function test_refund_cannot_exceed_what_was_paid_ahead(): void
    {
        $member = $this->admit('Rahul Sharma', 1, '2026-11-08');
        $this->pay($member, 1000);

        $this->actingAs($this->owner)->postJson("/api/members/{$member->id}/deactivate", ['refund_amount' => 1500])->assertStatus(422);
        $this->assertSame('active', $member->fresh()->status);
    }

    public function test_refund_is_refused_when_nothing_is_paid_ahead(): void
    {
        $member = $this->admit('Rahul Sharma', 1, '2026-11-01');

        $this->actingAs($this->owner)->postJson("/api/members/{$member->id}/deactivate", ['refund_amount' => 100])->assertStatus(422);
        $this->assertSame('active', $member->fresh()->status);
    }

    public function test_leaving_without_a_refund_still_works(): void
    {
        $member = $this->admit('Rahul Sharma', 1, '2026-11-30');

        $this->actingAs($this->owner)->postJson("/api/members/{$member->id}/deactivate")->assertOk();
        $this->assertSame(0, Payment::where('member_id', $member->id)->count());
    }
}
