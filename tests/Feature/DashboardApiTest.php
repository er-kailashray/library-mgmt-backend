<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsLibrary;
use Tests\TestCase;

class DashboardApiTest extends TestCase
{
    use BuildsLibrary, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-20 10:00:00');
        $this->createLibrary(seats: 10, monthlyFee: 1000);
    }

    public function test_dashboard_matches_mobile_contract(): void
    {
        $this->actingAs($this->owner)->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonStructure(['data' => [
                'library_name', 'owner_name', 'total_seats', 'occupied_seats', 'total_members',
                'monthly_collection', 'pending_collection', 'today_collection', 'expected_collection',
                'collection_month', 'days_in_month', 'day_of_month', 'last_month_same_period',
                'month_fees' => ['expected', 'collected', 'due', 'due_members', 'upcoming', 'upcoming_members', 'arrears', 'arrears_members'],
                'month_cash' => ['received', 'for_this_month', 'for_earlier_months', 'in_advance'],
                'due_members_count', 'due_total', 'top_dues', 'critical_dues_count', 'this_month_dues_count', 'upcoming_renewals',
                'overdue_summary' => ['two_months_plus', 'one_month', 'due_this_week'],
                'recent_payments',
            ]])
            ->assertJsonPath('data.library_name', 'Gyan Library')
            ->assertJsonPath('data.owner_name', 'Asha Owner')
            ->assertJsonPath('data.total_seats', 10);
    }

    public function test_dashboard_computes_dues_and_collections(): void
    {
        $paid = $this->admit('Paid Member', 1, '2026-11-10');      // not due
        $this->admit('Renewing Soon', 2, '2026-10-24');             // due within 7 days
        $this->admit('Just Overdue', 3, '2026-10-15');              // 5 days, 1 cycle, this month
        $this->admit('Critical', 4, '2026-10-01');                  // 19 days, 1 cycle, critical
        $this->admit('Long Overdue', 5, '2026-08-10');              // 71 days, 3 cycles

        $this->recordPayment($paid, 1000, '2026-10-20');
        $this->recordPayment($paid, 500, '2026-10-05', 'partial');
        $this->recordPayment($paid, 700, '2026-09-28');             // last month, excluded

        $this->actingAs($this->owner)->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.occupied_seats', 5)
            ->assertJsonPath('data.total_members', 5)
            ->assertJsonPath('data.monthly_collection', 1500)
            ->assertJsonPath('data.today_collection', 1000)
            ->assertJsonPath('data.expected_collection', 5000)
            // 1 + 1 + 3 missed cycles at 1000 each
            ->assertJsonPath('data.pending_collection', 5000)
            ->assertJsonPath('data.due_members_count', 3)
            ->assertJsonPath('data.critical_dues_count', 2)
            ->assertJsonPath('data.this_month_dues_count', 2)
            ->assertJsonPath('data.upcoming_renewals', 1)
            ->assertJsonPath('data.overdue_summary', [
                'two_months_plus' => 1,
                'one_month' => 2,
                'due_this_week' => 1,
            ])
            ->assertJsonCount(3, 'data.recent_payments')
            ->assertJsonPath('data.recent_payments.0.name', 'Paid Member')
            ->assertJsonPath('data.recent_payments.0.seat', 1);
    }

    public function test_dashboard_requires_authentication(): void
    {
        $this->getJson('/api/dashboard')->assertUnauthorized();
    }

    public function test_dashboard_returns_404_without_library(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/dashboard')
            ->assertNotFound()
            ->assertJsonPath('status', false);
    }

    public function test_dashboard_only_counts_own_library(): void
    {
        $this->admit('Mine', 1, '2026-10-01');
        $myOwner = $this->owner;

        $this->createLibrary();
        $this->admit('Other Library', 1, '2026-10-01');

        $this->actingAs($myOwner)->getJson('/api/dashboard')
            ->assertJsonPath('data.due_members_count', 1)
            ->assertJsonPath('data.total_members', 1);
    }

    public function test_dashboard_names_the_most_overdue_members_first(): void
    {
        $this->admit('Due Today', 1, '2026-10-20');
        $this->admit('Long Overdue', 2, '2026-08-25');
        $this->admit('Bit Late', 3, '2026-10-10');
        $this->admit('Paid Up', 4, '2026-11-15');
        $this->admit('Also Late', 5, '2026-10-12');

        $this->actingAs($this->owner)->getJson('/api/dashboard')
            ->assertJsonPath('data.due_members_count', 4)
            // Aug 25 -> 2 missed cycles (2000); the other three owe one fee each.
            ->assertJsonPath('data.due_total', 5000)
            ->assertJsonCount(3, 'data.top_dues')
            ->assertJsonPath('data.top_dues.0.name', 'Long Overdue')
            ->assertJsonPath('data.top_dues.0.amount', 2000)
            ->assertJsonPath('data.top_dues.1.name', 'Bit Late')
            ->assertJsonPath('data.top_dues.1.days_overdue', 10)
            ->assertJsonPath('data.top_dues.2.name', 'Also Late');
    }

    public function test_dashboard_compares_this_month_with_last_month_so_far(): void
    {
        // Today is 2026-10-20.
        $member = $this->admit('Rahul Sharma', 1, '2026-11-15');
        $this->recordPayment($member, 1000, '2026-09-05');  // counts: Sep 1-20
        $this->recordPayment($member, 500, '2026-09-25');   // after Sep 20 - excluded
        $this->recordPayment($member, 700, '2026-10-02');

        $this->actingAs($this->owner)->getJson('/api/dashboard')
            ->assertJsonPath('data.collection_month', '2026-10')
            ->assertJsonPath('data.days_in_month', 31)
            ->assertJsonPath('data.day_of_month', 20)
            ->assertJsonPath('data.monthly_collection', 700)
            ->assertJsonPath('data.last_month_same_period', 1000);
    }

    public function test_can_fetch_a_past_months_collections(): void
    {
        $member = $this->admit('Rahul Sharma', 1, '2026-11-15');
        $this->recordPayment($member, 1000, '2026-09-05');
        $this->recordPayment($member, 500, '2026-09-30');
        $this->recordPayment($member, 700, '2026-10-02');

        $this->actingAs($this->owner)->getJson('/api/collections?month=2026-09')
            ->assertOk()
            ->assertJsonPath('data.month', '2026-09')
            ->assertJsonPath('data.collected', 1500)
            ->assertJsonPath('data.payments_count', 2);
    }

    public function test_cannot_fetch_collections_for_a_future_month(): void
    {
        $this->actingAs($this->owner)->getJson('/api/collections?month=2026-11')
            ->assertStatus(422)
            ->assertJsonValidationErrors('month');
    }

    public function test_dashboard_reports_seats_filled_per_batch_in_time_order(): void
    {
        $evening = \App\Models\Batch::create([
            'library_id' => $this->library->id,
            'name' => 'Evening',
            'start_time' => '17:00:00',
            'end_time' => '21:00:00',
            'monthly_fee' => 800,
        ]);
        $this->admit('Morning One', 1, '2026-11-15');
        $this->admit('Morning Two', 2, '2026-11-15');
        $this->admit('Evening One', 1, '2026-11-15', $evening);
        $left = $this->admit('Left Member', 3, '2026-11-15');
        $left->allocations()->update(['status' => 'ended']);

        $this->actingAs($this->owner)->getJson('/api/dashboard')
            ->assertJsonCount(2, 'data.batch_occupancy')
            ->assertJsonPath('data.batch_occupancy.0.name', $this->batch->name)
            ->assertJsonPath('data.batch_occupancy.0.occupied', 2)
            ->assertJsonPath('data.batch_occupancy.0.total', 10)
            ->assertJsonPath('data.batch_occupancy.1.name', 'Evening')
            ->assertJsonPath('data.batch_occupancy.1.occupied', 1);
    }

    /** Today is 2026-10-20 and the fee is 1000 (see setUp). */
    private function monthFees(): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->owner)->getJson('/api/dashboard');
    }

    public function test_paying_two_months_ahead_counts_once_for_this_month(): void
    {
        // Renews on the 5th; paid through 5 Dec, i.e. October and November both paid on 5 Oct.
        $member = $this->admit('Kailash', 1, '2026-12-05');
        $this->recordPayment($member, 2000, '2026-10-05');

        $this->monthFees()
            ->assertJsonPath('data.monthly_collection', 2000)          // cash received
            ->assertJsonPath('data.month_fees.expected', 1000)
            ->assertJsonPath('data.month_fees.collected', 1000)        // only October's fee
            ->assertJsonPath('data.month_fees.due', 0);
    }

    public function test_renewal_later_this_month_is_upcoming_not_due(): void
    {
        $this->admit('Renews Tomorrow', 1, '2026-10-21');

        $this->monthFees()
            ->assertJsonPath('data.month_fees.due', 0)
            ->assertJsonPath('data.month_fees.upcoming', 1000)
            ->assertJsonPath('data.month_fees.upcoming_members', 1)
            ->assertJsonPath('data.month_fees.collected', 0);
    }

    public function test_renewal_earlier_this_month_unpaid_is_due(): void
    {
        $this->admit('Missed The 10th', 1, '2026-10-10');

        $this->monthFees()
            ->assertJsonPath('data.month_fees.due', 1000)
            ->assertJsonPath('data.month_fees.due_members', 1)
            ->assertJsonPath('data.month_fees.arrears', 0);
    }

    public function test_months_missed_before_this_one_are_arrears(): void
    {
        // Paid through 15 Sep: September and October both unpaid.
        $this->admit('Behind', 1, '2026-09-15');

        $this->monthFees()
            ->assertJsonPath('data.month_fees.due', 1000)
            ->assertJsonPath('data.month_fees.arrears', 1000)
            ->assertJsonPath('data.month_fees.arrears_members', 1);
    }

    public function test_partial_payment_settles_old_dues_before_this_month(): void
    {
        $member = $this->admit('Behind', 1, '2026-09-15');
        $this->recordPayment($member, 1500, '2026-10-18', 'partial');

        // 1000 clears September, the other 500 goes toward October.
        $this->monthFees()
            ->assertJsonPath('data.month_fees.arrears', 0)
            ->assertJsonPath('data.month_fees.collected', 500)
            ->assertJsonPath('data.month_fees.due', 500);
    }

    public function test_each_batch_a_member_takes_is_its_own_fee(): void
    {
        $evening = \App\Models\Batch::create([
            'library_id' => $this->library->id,
            'name' => 'Evening',
            'start_time' => '17:00:00',
            'end_time' => '21:00:00',
            'monthly_fee' => 800,
        ]);
        $member = $this->admit('Two Batches', 1, '2026-11-12');
        \App\Models\SeatAllocation::create([
            'library_id' => $this->library->id,
            'member_id' => $member->id,
            'batch_id' => $evening->id,
            'seat_id' => \App\Models\Seat::where('library_id', $this->library->id)->where('seat_number', 2)->value('id'),
            'start_date' => '2026-10-12',
            'renewal_date' => '2026-10-12',
            'status' => 'active',
        ]);

        // Morning paid (through 12 Nov), Evening due since the 12th.
        $this->monthFees()
            ->assertJsonPath('data.month_fees.expected', 1800)
            ->assertJsonPath('data.month_fees.collected', 1000)
            ->assertJsonPath('data.month_fees.due', 800);
    }

    public function test_expected_splits_exactly_into_collected_due_and_upcoming(): void
    {
        $this->admit('Paid', 1, '2026-11-03');
        $this->admit('Due', 2, '2026-10-03');
        $this->admit('Later', 3, '2026-10-28');

        $fees = $this->monthFees()->json('data.month_fees');
        $this->assertSame(3000.0, (float) $fees['expected']);
        $this->assertSame((float) $fees['expected'], (float) ($fees['collected'] + $fees['due'] + $fees['upcoming']));
    }

    public function test_cash_paid_two_months_ahead_splits_into_this_month_and_advance(): void
    {
        $member = $this->admit('Kailash', 1, '2026-12-05');
        $this->recordPayment($member, 2000, '2026-10-05');

        $this->monthFees()
            ->assertJsonPath('data.month_cash.received', 2000)
            ->assertJsonPath('data.month_cash.for_this_month', 1000)
            ->assertJsonPath('data.month_cash.in_advance', 1000)
            ->assertJsonPath('data.month_cash.for_earlier_months', 0);
    }

    public function test_cash_clearing_old_months_counts_as_earlier_dues(): void
    {
        // Was paid through 10 Aug; on 15 Oct paid Aug, Sep and Oct -> now paid through 10 Nov.
        $member = $this->admit('Catching Up', 1, '2026-11-10');
        $this->recordPayment($member, 3000, '2026-10-15');

        $this->monthFees()
            ->assertJsonPath('data.month_cash.for_this_month', 1000)
            ->assertJsonPath('data.month_cash.for_earlier_months', 2000)
            ->assertJsonPath('data.month_cash.in_advance', 0);
    }

    public function test_partial_cash_toward_an_old_month_counts_as_earlier_dues(): void
    {
        $member = $this->admit('Long Behind', 1, '2026-05-05');
        $this->recordPayment($member, 600, '2026-10-18', 'partial');

        $this->monthFees()
            ->assertJsonPath('data.month_cash.received', 600)
            ->assertJsonPath('data.month_cash.for_earlier_months', 600);
    }

    public function test_readmission_settlement_counts_as_earlier_dues(): void
    {
        $member = $this->admit('Came Back', 1, '2026-11-20');
        \App\Models\Payment::create([
            'library_id' => $this->library->id,
            'member_id' => $member->id,
            'amount' => 300,
            'status' => 'paid',
            'payment_date' => '2026-10-20',
            'notes' => 'Previous dues settled (₹200 waived)',
        ]);
        $this->recordPayment($member, 1000, '2026-10-20');

        $this->monthFees()
            ->assertJsonPath('data.month_cash.received', 1300)
            ->assertJsonPath('data.month_cash.for_earlier_months', 300)
            ->assertJsonPath('data.month_cash.for_this_month', 1000);
    }

    public function test_cash_split_always_adds_up_to_cash_received(): void
    {
        $a = $this->admit('Ahead', 1, '2026-12-05');
        $this->recordPayment($a, 2000, '2026-10-05');
        $b = $this->admit('Behind', 2, '2026-05-05');
        $this->recordPayment($b, 600, '2026-10-18', 'partial');
        $c = $this->admit('On Time', 3, '2026-11-12');
        $this->recordPayment($c, 1000, '2026-10-12');

        $cash = $this->monthFees()->json('data.month_cash');
        $this->assertSame(3600.0, (float) $cash['received']);
        $this->assertSame((float) $cash['received'], (float) ($cash['for_this_month'] + $cash['for_earlier_months'] + $cash['in_advance']));
    }

    public function test_payment_from_a_member_who_since_left_still_counts_for_its_month(): void
    {
        $member = $this->admit('Joined Then Left', 1, '2026-11-02');
        $this->recordPayment($member, 1000, '2026-10-02');
        $member->update(['status' => 'inactive']);
        $member->allocations()->update(['status' => 'ended']);

        $this->monthFees()
            ->assertJsonPath('data.month_cash.for_this_month', 1000)
            ->assertJsonPath('data.month_cash.for_earlier_months', 0);
    }

    public function test_batch_occupancy_includes_todays_check_ins(): void
    {
        $member = $this->admit('Rahul Sharma', 1, '2026-11-01');
        \App\Models\Attendance::create([
            'library_id' => $this->library->id,
            'member_id' => $member->id,
            'batch_id' => $this->batch->id,
            'date' => now()->toDateString(),
            'status' => 'present',
            'check_in_at' => '09:10',
        ]);

        $this->actingAs($this->owner)->getJson('/api/dashboard')
            ->assertJsonPath('data.batch_occupancy.0.checked_in_today', 1)
            ->assertJsonPath('data.batch_occupancy.0.occupied', 1);
    }

    public function test_recent_payments_carry_mode_time_and_period(): void
    {
        $member = $this->admit('Rahul Sharma', 1, '2026-10-25');
        $this->actingAs($this->owner)->postJson('/api/payments', ['member_id' => $member->id, 'amount' => 1000, 'payment_mode' => 'upi'])->assertCreated();

        $this->actingAs($this->owner)->getJson('/api/dashboard')
            ->assertJsonPath('data.recent_payments.0.status', 'PAID')
            ->assertJsonPath('data.recent_payments.0.method', 'UPI')
            ->assertJsonPath('data.recent_payments.0.period_start', '2026-10-25')
            ->assertJsonPath('data.recent_payments.0.period_end', '2026-11-24');
    }
}
