<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsLibrary;
use Tests\TestCase;

class MemberApiTest extends TestCase
{
    use BuildsLibrary, RefreshDatabase;

    public function test_can_list_members_with_payment_status(): void
    {
        $user = \App\Models\User::factory()->create();
        $library = \App\Models\Library::create([
            'user_id' => $user->id,
            'library_name' => 'Test Library',
            'total_seats' => 2,
        ]);

        $batch = \App\Models\Batch::create([
            'library_id' => $library->id,
            'name' => 'Morning',
            'start_time' => '08:00:00',
            'end_time' => '12:00:00',
            'monthly_fee' => 500,
        ]);

        $seat = \App\Models\Seat::create([
            'library_id' => $library->id,
            'seat_number' => 1,
        ]);

        $seat2 = \App\Models\Seat::create([
            'library_id' => $library->id,
            'seat_number' => 2,
        ]);

        // Member 1: Paid (Future renewal)
        $memberPaid = \App\Models\Member::create([
            'library_id' => $library->id,
            'name' => 'John Paid',
            'phone' => '9876543210',
            'status' => 'active',
        ]);

        \App\Models\SeatAllocation::create([
            'library_id' => $library->id,
            'member_id' => $memberPaid->id,
            'seat_id' => $seat->id,
            'batch_id' => $batch->id,
            'start_date' => now()->subDays(5),
            'renewal_date' => now()->addDays(25),
            'status' => 'active',
        ]);

        // Member 2: Due (Past renewal)
        $memberDue = \App\Models\Member::create([
            'library_id' => $library->id,
            'name' => 'Jane Due',
            'phone' => '9876543211',
            'status' => 'active',
        ]);

        \App\Models\SeatAllocation::create([
            'library_id' => $library->id,
            'member_id' => $memberDue->id,
            'seat_id' => $seat2->id,
            'batch_id' => $batch->id,
            'start_date' => now()->subMonth(),
            'renewal_date' => now()->subDay(),
            'status' => 'active',
        ]);

        $response = $this->actingAs($user)->getJson('/api/members');

        $response->assertStatus(200)
            ->assertJsonPath('status', true)
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment([
                'name' => 'John Paid',
                'payment_status' => 'Paid',
            ])
            ->assertJsonFragment([
                'name' => 'Jane Due',
                'payment_status' => 'Due',
            ]);
    }

    public function test_member_list_includes_inactive_members_after_active_ones(): void
    {
        $this->createLibrary(seats: 3);
        $left = $this->admit('Left Member', 1, '2026-11-01');
        $left->update(['status' => 'inactive', 'outstanding_balance' => 500]);
        $left->allocations()->update(['status' => 'ended']);
        $this->admit('Current Member', 2, '2026-11-01');

        $this->actingAs($this->owner)->getJson('/api/members')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Current Member')
            ->assertJsonPath('data.0.status', 'active')
            ->assertJsonPath('data.1.name', 'Left Member')
            ->assertJsonPath('data.1.status', 'inactive')
            ->assertJsonPath('data.1.outstanding_balance', 500)
            ->assertJsonPath('data.1.active_allocations_count', 0);
    }

    public function test_member_list_shows_each_members_current_batch_and_seat(): void
    {
        $this->createLibrary(seats: 5);
        $evening = \App\Models\Batch::create([
            'library_id' => $this->library->id, 'name' => 'Evening', 'start_time' => '17:00', 'end_time' => '21:00', 'monthly_fee' => 800,
        ]);
        // Evening seat first, so the list has to put Morning (earlier in the day) ahead of it.
        $member = $this->admit('Two Batches', 4, '2026-11-01', $evening);
        \App\Models\SeatAllocation::create([
            'library_id' => $this->library->id, 'member_id' => $member->id, 'batch_id' => $this->batch->id,
            'seat_id' => $this->library->seats()->where('seat_number', 2)->value('id'),
            'start_date' => now()->toDateString(), 'renewal_date' => '2026-11-01', 'status' => 'active',
        ]);
        $left = $this->admit('Left Member', 1, '2026-11-01');
        $left->update(['status' => 'inactive']);
        $left->allocations()->update(['status' => 'ended']);

        $this->actingAs($this->owner)->getJson('/api/members')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Two Batches')
            ->assertJsonPath('data.0.seats.0.batch_name', 'Morning')
            ->assertJsonPath('data.0.seats.0.seat_number', 2)
            ->assertJsonPath('data.0.seats.1.batch_name', 'Evening')
            ->assertJsonPath('data.0.seats.1.seat_number', 4)
            ->assertJsonPath('data.1.name', 'Left Member')
            ->assertJsonPath('data.1.seats', []);
    }

    public function test_member_list_shows_what_each_member_owes_or_when_they_renew(): void
    {
        $this->travelTo('2026-11-10 10:00:00');
        $this->createLibrary(seats: 5, monthlyFee: 1000);
        $this->admit('Owes', 1, '2026-11-01');        // 9 days overdue, one cycle
        $this->admit('Paid Up', 2, '2026-12-05');
        $left = $this->admit('Left', 3, '2026-11-01');
        $left->update(['status' => 'inactive']);
        $left->allocations()->update(['status' => 'ended', 'ended_on' => '2026-10-20']);

        $this->actingAs($this->owner)->getJson('/api/members')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Owes')
            ->assertJsonPath('data.0.due_amount', 1000)
            ->assertJsonPath('data.0.days_overdue', 9)
            ->assertJsonPath('data.0.next_renewal_date', '2026-11-01')
            ->assertJsonPath('data.1.name', 'Paid Up')
            ->assertJsonPath('data.1.due_amount', null)
            ->assertJsonPath('data.1.next_renewal_date', '2026-12-05')
            ->assertJsonPath('data.2.name', 'Left')
            ->assertJsonPath('data.2.left_on', '2026-10-20')
            ->assertJsonPath('data.2.next_renewal_date', null);
    }
}
