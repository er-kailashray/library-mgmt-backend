<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Member;
use App\Models\Payment;
use App\Models\SeatAllocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsLibrary;
use Tests\TestCase;

class ReportApiTest extends TestCase
{
    use BuildsLibrary, RefreshDatabase;

    private Member $rahul;

    private Member $priya;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-10 10:00:00');
        $this->createLibrary(seats: 10, monthlyFee: 1000);

        // admit() starts each seat a month back, on 10 Oct.
        $this->rahul = $this->admit('Rahul', 1, '2026-12-01');   // paid up
        $this->priya = $this->admit('Priya', 2, '2026-11-01');   // 9 days overdue, 1 cycle
        $aman = $this->admit('Aman', 3, '2026-09-05');           // 66 days overdue, 3 cycles

        $this->recordPayment($this->rahul, 1000, '2026-11-02');
        Payment::create([
            'library_id' => $this->library->id, 'member_id' => $this->rahul->id, 'amount' => -200,
            'status' => 'refunded', 'payment_date' => '2026-11-05', 'payment_mode' => 'upi',
        ]);
        $this->recordPayment($this->priya, 600, '2026-10-15')->update(['payment_mode' => 'cash']);
        $this->recordPayment($aman, 400, '2026-09-20')->update(['payment_mode' => null]);
    }

    private function report(int $months = 3)
    {
        return $this->actingAs($this->owner)->getJson("/api/reports?months={$months}")->assertOk();
    }

    public function test_revenue_by_month_nets_refunds_and_splits_by_mode(): void
    {
        $this->report()
            ->assertJsonPath('data.from', '2026-09-01')
            ->assertJsonPath('data.to', '2026-11-10')
            ->assertJsonPath('data.revenue.by_month', [
                ['month' => '2026-09', 'label' => 'Sep', 'collected' => 400, 'refunded' => 0, 'payments_count' => 1],
                ['month' => '2026-10', 'label' => 'Oct', 'collected' => 600, 'refunded' => 0, 'payments_count' => 1],
                ['month' => '2026-11', 'label' => 'Nov', 'collected' => 800, 'refunded' => 200, 'payments_count' => 1],
            ])
            ->assertJsonPath('data.revenue.total', 1800)
            ->assertJsonPath('data.revenue.this_month', 800)
            ->assertJsonPath('data.revenue.last_month', 600)
            ->assertJsonPath('data.revenue.change_pct', 33.3)
            ->assertJsonPath('data.revenue.by_mode.0.mode', 'upi')
            ->assertJsonPath('data.revenue.by_mode.0.amount', 1000)
            ->assertJsonPath('data.revenue.by_mode.1.label', 'Cash')
            ->assertJsonPath('data.revenue.by_mode.2.label', 'Other');
    }

    public function test_dues_are_grouped_by_age_with_a_collection_rate(): void
    {
        $this->report()
            ->assertJsonPath('data.dues.total_outstanding', 4000)
            ->assertJsonPath('data.dues.overdue_members', 2)
            ->assertJsonPath('data.dues.by_age.0', ['key' => 'week', 'label' => 'Up to 7 days', 'members' => 0, 'amount' => 0])
            ->assertJsonPath('data.dues.by_age.1.members', 1)
            ->assertJsonPath('data.dues.by_age.1.amount', 1000)
            ->assertJsonPath('data.dues.by_age.3.members', 1)
            ->assertJsonPath('data.dues.by_age.3.amount', 3000)
            // 800 collected this month of 800 + 4000.
            ->assertJsonPath('data.dues.collection_rate', 17);
    }

    public function test_members_joined_left_active_and_occupancy(): void
    {
        $leaver = Member::create([
            'library_id' => $this->library->id, 'name' => 'Old Timer', 'phone' => '9876500099',
            'status' => 'inactive', 'join_date' => '2026-09-01',
        ]);
        SeatAllocation::create([
            'library_id' => $this->library->id, 'member_id' => $leaver->id, 'batch_id' => $this->batch->id,
            'seat_id' => $this->library->seats()->where('seat_number', 4)->value('id'),
            'start_date' => '2026-09-01', 'renewal_date' => '2026-10-01', 'ended_on' => '2026-10-20', 'status' => 'ended',
        ]);

        $this->report()
            ->assertJsonPath('data.members.active', 3)
            ->assertJsonPath('data.members.by_month', [
                ['month' => '2026-09', 'label' => 'Sep', 'joined' => 1, 'left' => 0, 'active' => 1],
                ['month' => '2026-10', 'label' => 'Oct', 'joined' => 3, 'left' => 1, 'active' => 3],
                ['month' => '2026-11', 'label' => 'Nov', 'joined' => 0, 'left' => 0, 'active' => 3],
            ])
            ->assertJsonPath('data.members.occupancy.0.name', 'Morning')
            ->assertJsonPath('data.members.occupancy.0.occupied', 3)
            ->assertJsonPath('data.members.occupancy.0.total', 10)
            ->assertJsonPath('data.members.occupancy.0.rate', 30);
    }

    public function test_attendance_averages_over_marked_days_and_lists_who_hasnt_come(): void
    {
        foreach (['2026-11-08', '2026-11-09'] as $day) {
            Attendance::create(['library_id' => $this->library->id, 'member_id' => $this->rahul->id, 'batch_id' => $this->batch->id, 'date' => $day, 'status' => 'present']);
        }
        Attendance::create(['library_id' => $this->library->id, 'member_id' => $this->priya->id, 'batch_id' => $this->batch->id, 'date' => '2026-11-09', 'status' => 'absent']);

        $response = $this->report()
            ->assertJsonPath('data.attendance.tracked', true)
            ->assertJsonPath('data.attendance.by_batch.0.enrolled', 3)
            ->assertJsonPath('data.attendance.by_batch.0.marked_days', 2)
            ->assertJsonPath('data.attendance.by_batch.0.average_present', 1)
            ->assertJsonPath('data.attendance.by_batch.0.rate', 33)
            // Rahul came yesterday; Priya and Aman haven't come since starting on 10 Oct.
            ->assertJsonPath('data.attendance.not_seen.count', 2)
            ->assertJsonPath('data.attendance.not_seen.members.0.last_seen', null)
            ->assertJsonPath('data.attendance.not_seen.members.0.days', 31);

        $this->assertEqualsCanonicalizing(['Priya', 'Aman'], array_column($response->json('data.attendance.not_seen.members'), 'name'));
    }

    public function test_without_attendance_nobody_is_listed_as_missing(): void
    {
        $this->report()
            ->assertJsonPath('data.attendance.tracked', false)
            ->assertJsonPath('data.attendance.by_batch.0.average_present', null)
            ->assertJsonPath('data.attendance.not_seen.count', 0);
    }

    public function test_months_defaults_to_six_and_only_allows_the_offered_ranges(): void
    {
        $this->actingAs($this->owner)->getJson('/api/reports')->assertOk()->assertJsonCount(6, 'data.revenue.by_month');
        $this->actingAs($this->owner)->getJson('/api/reports?months=12')->assertOk()->assertJsonCount(12, 'data.members.by_month');
        $this->actingAs($this->owner)->getJson('/api/reports?months=5')->assertStatus(422);
    }
}
