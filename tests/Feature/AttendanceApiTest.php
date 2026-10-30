<?php

namespace Tests\Feature;

use App\Models\Attendance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsLibrary;
use Tests\TestCase;

class AttendanceApiTest extends TestCase
{
    use BuildsLibrary, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-20 10:00:00');
        $this->createLibrary(seats: 3);
    }

    public function test_returns_roster_grouped_by_batch_with_unmarked_status(): void
    {
        $this->admit('Rahul Sharma', 1, '2026-11-01');
        $this->admit('Aman Verma', 2, '2026-11-01');

        $this->actingAs($this->owner)->getJson('/api/attendance')
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.date', '2026-10-20')
            ->assertJsonCount(1, 'data.batches')
            ->assertJsonPath('data.batches.0.batch_name', 'Morning')
            ->assertJsonCount(2, 'data.batches.0.members')
            ->assertJsonPath('data.batches.0.members.0.name', 'Rahul Sharma')
            ->assertJsonPath('data.batches.0.members.0.seat_number', 1)
            ->assertJsonPath('data.batches.0.members.0.status', null);
    }

    public function test_can_mark_member_present(): void
    {
        $member = $this->admit('Rahul Sharma', 1, '2026-11-01');

        $this->actingAs($this->owner)->postJson('/api/attendance', [
            'member_id' => $member->id,
            'batch_id' => $this->batch->id,
            'status' => 'present',
        ])->assertOk()->assertJsonPath('status', true);

        $this->assertDatabaseHas('attendances', [
            'member_id' => $member->id,
            'batch_id' => $this->batch->id,
            'date' => '2026-10-20',
            'status' => 'present',
        ]);

        $this->actingAs($this->owner)->getJson('/api/attendance')
            ->assertJsonPath('data.batches.0.members.0.status', 'present');
    }

    public function test_marking_again_updates_the_same_days_record_instead_of_duplicating(): void
    {
        $member = $this->admit('Rahul Sharma', 1, '2026-11-01');

        $this->actingAs($this->owner)->postJson('/api/attendance', [
            'member_id' => $member->id,
            'batch_id' => $this->batch->id,
            'status' => 'present',
        ])->assertOk();

        $this->actingAs($this->owner)->postJson('/api/attendance', [
            'member_id' => $member->id,
            'batch_id' => $this->batch->id,
            'status' => 'absent',
        ])->assertOk();

        $this->assertSame(1, Attendance::where('member_id', $member->id)->where('batch_id', $this->batch->id)->count());
        $this->assertDatabaseHas('attendances', ['member_id' => $member->id, 'status' => 'absent']);
    }

    private function eveningBatch(): \App\Models\Batch
    {
        return \App\Models\Batch::create([
            'library_id' => $this->library->id,
            'name' => 'Evening',
            'start_time' => '17:00',
            'end_time' => '21:00',
            'monthly_fee' => 1000,
        ]);
    }

    public function test_cannot_mark_absent_from_a_batch_the_member_is_not_in(): void
    {
        $member = $this->admit('Rahul Sharma', 1, '2026-11-01');

        $this->actingAs($this->owner)->postJson('/api/attendance', [
            'member_id' => $member->id,
            'batch_id' => $this->eveningBatch()->id,
            'status' => 'absent',
        ])->assertStatus(422)->assertJsonPath('status', false);
    }

    public function test_check_in_today_records_the_time_now(): void
    {
        $member = $this->admit('Rahul Sharma', 1, '2026-11-01');

        $this->actingAs($this->owner)->postJson('/api/attendance', ['member_id' => $member->id, 'batch_id' => $this->batch->id, 'status' => 'present'])
            ->assertOk()
            ->assertJsonPath('data.check_in_at', '10:00');

        $this->actingAs($this->owner)->getJson('/api/attendance')
            ->assertJsonPath('data.batches.0.members.0.check_in_at', '10:00')
            ->assertJsonPath('data.batches.0.is_running', true)
            ->assertJsonPath('data.batches.0.present_count', 1);
    }

    public function test_an_earlier_day_needs_the_time_they_came(): void
    {
        $member = $this->admit('Rahul Sharma', 1, '2026-11-01');
        $payload = ['member_id' => $member->id, 'batch_id' => $this->batch->id, 'status' => 'present', 'date' => '2026-10-18'];

        $this->actingAs($this->owner)->postJson('/api/attendance', $payload)->assertStatus(422)->assertJsonValidationErrors('check_in_at');
        $this->actingAs($this->owner)->postJson('/api/attendance', $payload + ['check_in_at' => '08:15'])->assertOk();

        $this->actingAs($this->owner)->getJson('/api/attendance?date=2026-10-18')->assertJsonPath('data.batches.0.members.0.check_in_at', '08:15');
    }

    public function test_only_the_last_seven_days_can_be_changed(): void
    {
        $member = $this->admit('Rahul Sharma', 1, '2026-11-01');
        $payload = ['member_id' => $member->id, 'batch_id' => $this->batch->id, 'status' => 'present', 'check_in_at' => '08:15'];

        $this->actingAs($this->owner)->postJson('/api/attendance', $payload + ['date' => '2026-10-14'])->assertOk(); // 6 days back
        $this->actingAs($this->owner)->postJson('/api/attendance', $payload + ['date' => '2026-10-13'])->assertStatus(422)->assertJsonValidationErrors('date');
        $this->actingAs($this->owner)->getJson('/api/attendance')->assertJsonPath('data.editable_from', '2026-10-14');
    }

    public function test_no_check_in_by_the_end_of_the_batch_counts_as_absent(): void
    {
        $this->admit('Rahul Sharma', 1, '2026-11-01');

        $this->actingAs($this->owner)->getJson('/api/attendance')->assertJsonPath('data.batches.0.members.0.status', null);

        $this->travelTo('2026-10-20 12:30:00'); // Morning ran 08:00-12:00
        $this->actingAs($this->owner)->getJson('/api/attendance')
            ->assertJsonPath('data.batches.0.has_ended', true)
            ->assertJsonPath('data.batches.0.members.0.status', 'absent')
            ->assertJsonPath('data.batches.0.members.0.auto_absent', true);
    }

    public function test_coming_to_another_batch_for_the_day_is_recorded_there_and_not_absent(): void
    {
        $member = $this->admit('Rahul Sharma', 1, '2026-11-01'); // seated in Morning
        $evening = $this->eveningBatch();

        $this->travelTo('2026-10-20 17:20:00');
        $this->actingAs($this->owner)->postJson('/api/attendance', ['member_id' => $member->id, 'batch_id' => $evening->id, 'status' => 'present'])->assertOk();

        $this->actingAs($this->owner)->getJson('/api/attendance')
            // Morning: not absent - he came to Evening instead.
            ->assertJsonPath('data.batches.0.members.0.status', null)
            ->assertJsonPath('data.batches.0.members.0.auto_absent', false)
            ->assertJsonPath('data.batches.0.members.0.elsewhere.batch_name', 'Evening')
            ->assertJsonPath('data.batches.0.members.0.elsewhere.check_in_at', '17:20')
            // Evening: listed as a visitor with his time.
            ->assertJsonPath('data.batches.1.visitors.0.name', 'Rahul Sharma')
            ->assertJsonPath('data.batches.1.visitors.0.check_in_at', '17:20');
    }

    public function test_a_mark_can_be_undone(): void
    {
        $member = $this->admit('Rahul Sharma', 1, '2026-11-01');
        $this->actingAs($this->owner)->postJson('/api/attendance', ['member_id' => $member->id, 'batch_id' => $this->batch->id, 'status' => 'present']);

        $this->actingAs($this->owner)->postJson('/api/attendance/clear', ['member_id' => $member->id, 'batch_id' => $this->batch->id])->assertOk();

        $this->assertSame(0, Attendance::count());
    }

    public function test_an_overnight_batch_is_not_over_until_the_next_morning(): void
    {
        $night = \App\Models\Batch::create(['library_id' => $this->library->id, 'name' => 'Night', 'start_time' => '22:00', 'end_time' => '02:00', 'monthly_fee' => 800]);
        $this->admit('Rahul Sharma', 1, '2026-11-01', $night);

        $this->travelTo('2026-10-21 01:00:00');
        $this->actingAs($this->owner)->getJson('/api/attendance?date=2026-10-20')
            ->assertJsonPath('data.batches.1.batch_name', 'Night')
            ->assertJsonPath('data.batches.1.has_ended', false)
            ->assertJsonPath('data.batches.1.members.0.status', null);
    }

    public function test_after_midnight_an_overnight_batch_is_checked_in_now_on_the_day_it_began(): void
    {
        $night = \App\Models\Batch::create(['library_id' => $this->library->id, 'name' => 'Night', 'start_time' => '22:00', 'end_time' => '02:00', 'monthly_fee' => 800]);
        $member = $this->admit('Rahul Sharma', 1, '2026-11-01', $night);

        // 1 AM on the 21st: the Night batch that began at 10 PM on the 20th is still on.
        $this->travelTo('2026-10-21 01:05:00');
        $this->actingAs($this->owner)
            ->postJson('/api/attendance', ['member_id' => $member->id, 'batch_id' => $night->id, 'date' => '2026-10-20', 'status' => 'present'])
            ->assertOk()
            ->assertJsonPath('data.check_in_at', '01:05');

        $this->actingAs($this->owner)->getJson('/api/attendance?date=2026-10-20')
            ->assertJsonPath('data.batches.1.is_running', true)
            ->assertJsonPath('data.batches.1.members.0.status', 'present');

        // Once it's over, that day needs a time again.
        $this->travelTo('2026-10-21 03:00:00');
        $this->actingAs($this->owner)
            ->postJson('/api/attendance', ['member_id' => $member->id, 'batch_id' => $night->id, 'date' => '2026-10-20', 'status' => 'present'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('check_in_at');
    }

    public function test_live_session_names_the_running_batch_and_the_day_it_belongs_to(): void
    {
        $night = \App\Models\Batch::create(['library_id' => $this->library->id, 'name' => 'Night', 'start_time' => '22:00', 'end_time' => '02:00', 'monthly_fee' => 800]);

        // 10 AM: the Morning batch (08:00-12:00), today.
        $this->actingAs($this->owner)->getJson('/api/attendance')
            ->assertJsonPath('data.live_session', ['date' => '2026-10-20', 'batch_id' => $this->batch->id]);

        // 1 AM next day: still the 20th's Night batch.
        $this->travelTo('2026-10-21 01:00:00');
        $this->actingAs($this->owner)->getJson('/api/attendance')
            ->assertJsonPath('data.date', '2026-10-21')
            ->assertJsonPath('data.live_session', ['date' => '2026-10-20', 'batch_id' => $night->id]);

        // 3 AM: nothing on.
        $this->travelTo('2026-10-21 03:00:00');
        $this->actingAs($this->owner)->getJson('/api/attendance')->assertJsonPath('data.live_session', null);
    }

    public function test_cannot_mark_attendance_for_another_librarys_member(): void
    {
        $myOwner = $this->owner;
        $myBatch = $this->batch;

        $this->createLibrary();
        $stranger = $this->admit('Stranger', 1, '2026-10-10');

        $this->actingAs($myOwner)->postJson('/api/attendance', [
            'member_id' => $stranger->id,
            'batch_id' => $myBatch->id,
            'status' => 'present',
        ])->assertStatus(422)->assertJsonValidationErrors('member_id');
    }

    public function test_attendance_requires_authentication(): void
    {
        $this->getJson('/api/attendance')->assertUnauthorized();
    }
}
