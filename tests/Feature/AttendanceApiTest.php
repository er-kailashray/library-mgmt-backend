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

    public function test_rejects_marking_member_not_enrolled_in_batch(): void
    {
        $member = $this->admit('Rahul Sharma', 1, '2026-11-01');

        $evening = \App\Models\Batch::create([
            'library_id' => $this->library->id,
            'name' => 'Evening',
            'start_time' => '17:00',
            'end_time' => '21:00',
            'monthly_fee' => 1000,
        ]);

        $this->actingAs($this->owner)->postJson('/api/attendance', [
            'member_id' => $member->id,
            'batch_id' => $evening->id,
            'status' => 'present',
        ])->assertStatus(422)->assertJsonPath('status', false);
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
