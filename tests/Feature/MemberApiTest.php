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
}
