<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\Seat;
use App\Models\SeatAllocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsLibrary;
use Tests\TestCase;

/** Moving a member to another seat in the same batch, without touching what they pay. */
class SeatChangeApiTest extends TestCase
{
    use BuildsLibrary, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-10 10:00:00');
        $this->createLibrary(seats: 5);
    }

    private function seatId(int $number): int
    {
        return Seat::where('library_id', $this->library->id)->where('seat_number', $number)->value('id');
    }

    public function test_moves_the_member_and_keeps_the_renewal_date(): void
    {
        $member = $this->admit('Rahul Sharma', 1, '2026-11-25');

        $this->actingAs($this->owner)
            ->postJson("/api/members/{$member->id}/change-seat", ['batch_id' => $this->batch->id, 'seat_id' => $this->seatId(4)])
            ->assertOk()
            ->assertJsonPath('data.seat_number', 4)
            ->assertJsonPath('data.renewal_date', '2026-11-25');

        $active = SeatAllocation::where('member_id', $member->id)->where('status', 'active')->sole();
        $this->assertSame($this->seatId(4), $active->seat_id);
        $this->assertSame('2026-11-10', substr((string) $active->start_date, 0, 10));

        $old = SeatAllocation::where('member_id', $member->id)->where('status', 'ended')->sole();
        $this->assertSame($this->seatId(1), $old->seat_id);
        $this->assertSame('2026-11-10', $old->ended_on->toDateString());
    }

    public function test_the_old_seat_is_free_and_history_shows_both(): void
    {
        $member = $this->admit('Rahul Sharma', 1, '2026-11-25');
        $this->actingAs($this->owner)->postJson("/api/members/{$member->id}/change-seat", ['batch_id' => $this->batch->id, 'seat_id' => $this->seatId(4)]);

        $this->actingAs($this->owner)->getJson("/api/members/{$member->id}")
            ->assertJsonPath('data.allocations.0.seat_number', 4)
            ->assertJsonCount(2, 'data.seat_history')
            ->assertJsonPath('data.payment_status', 'Paid');

        // Someone else can now take seat 1.
        $other = $this->admit('Priya Verma', 2, '2026-11-25');
        $this->actingAs($this->owner)
            ->postJson("/api/members/{$other->id}/change-seat", ['batch_id' => $this->batch->id, 'seat_id' => $this->seatId(1)])
            ->assertOk();
    }

    public function test_the_moved_seat_keeps_its_place_in_the_profile(): void
    {
        $evening = Batch::create(['library_id' => $this->library->id, 'name' => 'Evening', 'start_time' => '16:00', 'end_time' => '20:00', 'monthly_fee' => 800]);
        $member = $this->admit('Rahul Sharma', 1, '2026-11-25');
        $this->admitToBatch($member, 2, $evening);

        // Moving the morning seat creates the newest allocation; it must still be listed first.
        $this->actingAs($this->owner)->postJson("/api/members/{$member->id}/change-seat", ['batch_id' => $this->batch->id, 'seat_id' => $this->seatId(4)])->assertOk();

        $this->actingAs($this->owner)->getJson("/api/members/{$member->id}")
            ->assertJsonPath('data.allocations.0.batch_name', 'Morning')
            ->assertJsonPath('data.allocations.0.seat_number', 4)
            ->assertJsonPath('data.allocations.1.batch_name', 'Evening');
    }

    private function admitToBatch(\App\Models\Member $member, int $seatNumber, Batch $batch): void
    {
        SeatAllocation::create([
            'library_id' => $this->library->id,
            'member_id' => $member->id,
            'batch_id' => $batch->id,
            'seat_id' => $this->seatId($seatNumber),
            'start_date' => now()->subMonth()->toDateString(),
            'renewal_date' => '2026-11-25',
            'status' => 'active',
        ]);
    }

    public function test_a_due_member_stays_due_for_the_same_amount(): void
    {
        $member = $this->admit('Rahul Sharma', 1, '2026-11-01');
        $before = $this->actingAs($this->owner)->getJson("/api/members/{$member->id}")->json('data.due_amount');

        $this->actingAs($this->owner)->postJson("/api/members/{$member->id}/change-seat", ['batch_id' => $this->batch->id, 'seat_id' => $this->seatId(3)])->assertOk();

        $this->actingAs($this->owner)->getJson("/api/members/{$member->id}")
            ->assertJsonPath('data.payment_status', 'Due')
            ->assertJsonPath('data.due_amount', $before);
    }

    public function test_cannot_move_into_a_taken_seat(): void
    {
        $member = $this->admit('Rahul Sharma', 1, '2026-11-25');
        $this->admit('Priya Verma', 2, '2026-11-25');

        $this->actingAs($this->owner)
            ->postJson("/api/members/{$member->id}/change-seat", ['batch_id' => $this->batch->id, 'seat_id' => $this->seatId(2)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('seat_id');

        $this->assertSame(2, SeatAllocation::where('status', 'active')->count());
    }

    public function test_a_seat_taken_in_another_batch_is_fine(): void
    {
        $evening = Batch::create(['library_id' => $this->library->id, 'name' => 'Evening', 'start_time' => '16:00', 'end_time' => '20:00', 'monthly_fee' => 800]);
        $member = $this->admit('Rahul Sharma', 1, '2026-11-25');
        $this->admit('Priya Verma', 2, '2026-11-25', $evening);

        $this->actingAs($this->owner)
            ->postJson("/api/members/{$member->id}/change-seat", ['batch_id' => $this->batch->id, 'seat_id' => $this->seatId(2)])
            ->assertOk();
    }

    public function test_rejects_the_same_seat_and_a_batch_they_are_not_in(): void
    {
        $evening = Batch::create(['library_id' => $this->library->id, 'name' => 'Evening', 'start_time' => '16:00', 'end_time' => '20:00', 'monthly_fee' => 800]);
        $member = $this->admit('Rahul Sharma', 1, '2026-11-25');

        $this->actingAs($this->owner)
            ->postJson("/api/members/{$member->id}/change-seat", ['batch_id' => $this->batch->id, 'seat_id' => $this->seatId(1)])
            ->assertJsonValidationErrors('seat_id');

        $this->actingAs($this->owner)
            ->postJson("/api/members/{$member->id}/change-seat", ['batch_id' => $evening->id, 'seat_id' => $this->seatId(3)])
            ->assertJsonValidationErrors('batch_id');
    }

    public function test_cannot_touch_another_librarys_member(): void
    {
        $member = $this->admit('Rahul Sharma', 1, '2026-11-25');
        $batchId = $this->batch->id;
        $seatId = $this->seatId(3);

        // A second owner with their own library tries to move the first library's member.
        $this->createLibrary(seats: 3);

        $this->actingAs($this->owner)
            ->postJson("/api/members/{$member->id}/change-seat", ['batch_id' => $batchId, 'seat_id' => $seatId])
            ->assertUnprocessable();
        $this->actingAs($this->owner)
            ->postJson("/api/members/{$member->id}/change-seat", ['batch_id' => $this->batch->id, 'seat_id' => $this->seatId(3)])
            ->assertNotFound();

        $this->assertSame(1, SeatAllocation::where('member_id', $member->id)->where('status', 'active')->count());
    }
}
