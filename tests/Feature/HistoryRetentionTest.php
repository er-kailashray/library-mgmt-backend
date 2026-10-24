<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\Payment;
use App\Models\Seat;
use App\Models\SeatAllocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsLibrary;
use Tests\TestCase;

/**
 * Who sat where, and what each payment was for, must survive anything the owner does later:
 * renaming or deleting a batch, removing seats, or the member leaving.
 */
class HistoryRetentionTest extends TestCase
{
    use BuildsLibrary, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-10 10:00:00');
        $this->createLibrary(seats: 5);
    }

    public function test_a_payment_keeps_the_seats_it_was_for(): void
    {
        $member = $this->admit('Rahul Sharma', 3, '2026-11-01');

        $this->actingAs($this->owner)->postJson('/api/payments', ['member_id' => $member->id, 'amount' => 1000])
            ->assertCreated()
            ->assertJsonPath('data.seats.0.seat_number', 3)
            ->assertJsonPath('data.seats.0.batch_name', 'Morning');
    }

    public function test_the_first_payment_at_admission_keeps_its_seats(): void
    {
        $this->actingAs($this->owner)->postJson('/api/admission', [
            'name' => 'Priya Verma',
            'phone' => '9876543211',
            'allocations' => [['batch_id' => $this->batch->id, 'seat_id' => Seat::where('seat_number', 2)->value('id')]],
            'payment' => ['amount' => 1000, 'payment_mode' => 'cash'],
        ])->assertOk();

        $this->assertSame(2, Payment::firstOrFail()->seats[0]['seat_number']);
    }

    public function test_payment_seats_stay_as_they_were_after_the_batch_is_renamed(): void
    {
        $member = $this->admit('Rahul Sharma', 3, '2026-11-01');
        $this->actingAs($this->owner)->postJson('/api/payments', ['member_id' => $member->id, 'amount' => 1000]);

        $this->batch->update(['name' => 'Early Bird']);

        $this->actingAs($this->owner)->getJson("/api/members/{$member->id}")
            ->assertJsonPath('data.payments.0.seats.0.batch_name', 'Morning');
    }

    public function test_leaving_records_the_day_each_seat_ended(): void
    {
        $member = $this->admit('Rahul Sharma', 3, '2026-11-01');

        $this->actingAs($this->owner)->postJson("/api/members/{$member->id}/deactivate")->assertOk();

        $this->assertSame('2026-11-10', SeatAllocation::where('member_id', $member->id)->first()->ended_on->toDateString());
    }

    public function test_deleting_a_batch_soft_deletes_it_and_keeps_its_history(): void
    {
        $member = $this->admit('Rahul Sharma', 3, '2026-11-01');
        $this->actingAs($this->owner)->postJson('/api/payments', ['member_id' => $member->id, 'amount' => 1000]);
        $this->actingAs($this->owner)->postJson("/api/members/{$member->id}/deactivate")->assertOk();

        $this->actingAs($this->owner)->deleteJson("/api/batches/{$this->batch->id}")->assertOk();

        $this->assertSoftDeleted($this->batch);
        $this->assertSame(1, SeatAllocation::where('member_id', $member->id)->count());
        // Gone from the app...
        $this->actingAs($this->owner)->getJson('/api/batches')->assertJsonCount(0, 'data');
        // ...but the member's past seat and payment still read correctly.
        $this->actingAs($this->owner)->getJson("/api/members/{$member->id}")
            ->assertJsonPath('data.seat_history.0.batch_name', 'Morning')
            ->assertJsonPath('data.seat_history.0.seat_number', 3)
            ->assertJsonPath('data.payments.0.seats.0.batch_name', 'Morning');
    }

    public function test_a_deleted_batch_name_can_be_used_again(): void
    {
        $this->actingAs($this->owner)->deleteJson("/api/batches/{$this->batch->id}")->assertOk();

        $this->actingAs($this->owner)->postJson('/api/batches', [
            'name' => 'Morning', 'start_time' => '08:00', 'end_time' => '12:00', 'monthly_fee' => 900,
        ])->assertOk();
    }

    public function test_cannot_admit_into_a_deleted_batch(): void
    {
        $this->actingAs($this->owner)->deleteJson("/api/batches/{$this->batch->id}")->assertOk();

        $this->actingAs($this->owner)->postJson('/api/admission', [
            'name' => 'Priya Verma',
            'phone' => '9876543211',
            'allocations' => [['batch_id' => $this->batch->id, 'seat_id' => Seat::where('seat_number', 1)->value('id')]],
        ])->assertStatus(422)->assertJsonValidationErrors('allocations.0.batch_id');
    }

    public function test_removing_seats_soft_deletes_them_and_keeps_past_bookings(): void
    {
        $member = $this->admit('Rahul Sharma', 5, '2026-11-01');
        $this->actingAs($this->owner)->postJson("/api/members/{$member->id}/deactivate")->assertOk();

        $this->actingAs($this->owner)->postJson('/api/library/update', ['library_name' => 'Gyan Library', 'total_seats' => 3])->assertOk();

        $removed = Seat::withTrashed()->where('seat_number', 5)->first();
        $this->assertSoftDeleted($removed);
        $this->assertSame(3, $this->library->seats()->count());
        $this->actingAs($this->owner)->getJson('/api/seat-matrix')->assertJsonCount(3, 'data.seats');
        $this->actingAs($this->owner)->getJson("/api/members/{$member->id}")->assertJsonPath('data.seat_history.0.seat_number', 5);
    }

    public function test_seat_history_lists_every_seat_newest_first(): void
    {
        $member = $this->admit('Rahul Sharma', 1, '2026-11-01');
        SeatAllocation::where('member_id', $member->id)->update(['status' => 'ended', 'ended_on' => '2026-10-31', 'start_date' => '2026-09-01']);
        $evening = Batch::create(['library_id' => $this->library->id, 'name' => 'Evening', 'start_time' => '17:00', 'end_time' => '21:00', 'monthly_fee' => 800]);
        // Same member, back in a different batch and seat.
        SeatAllocation::create([
            'library_id' => $this->library->id,
            'member_id' => $member->id,
            'batch_id' => $evening->id,
            'seat_id' => Seat::where('seat_number', 4)->value('id'),
            'start_date' => '2026-11-05',
            'renewal_date' => '2026-12-05',
            'status' => 'active',
        ]);

        $this->actingAs($this->owner)->getJson("/api/members/{$member->id}")
            ->assertJsonCount(2, 'data.seat_history')
            ->assertJsonPath('data.seat_history.0.batch_name', 'Evening')
            ->assertJsonPath('data.seat_history.0.ended_on', null)
            ->assertJsonPath('data.seat_history.1.batch_name', 'Morning')
            ->assertJsonPath('data.seat_history.1.ended_on', '2026-10-31');
    }
}
