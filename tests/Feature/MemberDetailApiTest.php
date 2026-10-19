<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsLibrary;
use Tests\TestCase;

class MemberDetailApiTest extends TestCase
{
    use BuildsLibrary, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createLibrary(seats: 3);
    }

    public function test_can_view_member_detail_with_allocations(): void
    {
        $member = $this->admit('Rahul Sharma', 1, '2026-11-01');

        $this->actingAs($this->owner)->getJson("/api/members/{$member->id}")
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.name', 'Rahul Sharma')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonCount(1, 'data.allocations')
            ->assertJsonPath('data.allocations.0.batch_name', 'Morning')
            ->assertJsonPath('data.allocations.0.seat_number', 1);
    }

    public function test_can_deactivate_member_and_free_their_seat(): void
    {
        $member = $this->admit('Rahul Sharma', 1, '2026-11-01');

        $this->actingAs($this->owner)->postJson("/api/members/{$member->id}/deactivate")
            ->assertOk()
            ->assertJsonPath('status', true);

        $this->assertSame('inactive', $member->fresh()->status);
        $this->assertSame('ended', $member->allocations()->first()->fresh()->status);

        $this->actingAs($this->owner)->getJson('/api/seat-matrix')
            ->assertJsonPath('data.seats.0.batches.0.available', true);
    }

    public function test_deactivated_member_disappears_from_members_list(): void
    {
        $member = $this->admit('Rahul Sharma', 1, '2026-11-01');

        $this->actingAs($this->owner)->postJson("/api/members/{$member->id}/deactivate")->assertOk();

        $this->actingAs($this->owner)->getJson('/api/members')->assertJsonCount(0, 'data');
    }

    public function test_cannot_view_or_deactivate_another_librarys_member(): void
    {
        $myOwner = $this->owner;

        $this->createLibrary();
        $stranger = $this->admit('Stranger', 1, '2026-10-10');

        $this->actingAs($myOwner)->getJson("/api/members/{$stranger->id}")->assertNotFound();
        $this->actingAs($myOwner)->postJson("/api/members/{$stranger->id}/deactivate")->assertNotFound();
    }

    public function test_member_detail_requires_authentication(): void
    {
        $member = $this->admit('Rahul Sharma', 1, '2026-11-01');

        $this->getJson("/api/members/{$member->id}")->assertUnauthorized();
    }
}
