<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use Tests\Concerns\BuildsLibrary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionAccessTest extends TestCase
{
    use BuildsLibrary, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-24 10:00:00');
        $this->createLibrary(seats: 3);
    }

    private function endSubscription(string $endsAt, ?string $plan = 'monthly'): void
    {
        Subscription::updateOrCreate(['library_id' => $this->library->id], [
            'plan_id' => $plan ? SubscriptionPlan::where('code', $plan)->value('id') : null,
            'starts_at' => '2026-08-01',
            'ends_at' => $endsAt,
        ]);
    }

    public function test_expired_library_can_still_read_everything(): void
    {
        $member = $this->admit('Rahul Sharma', 1, '2026-11-01');
        $this->endSubscription('2026-10-01');

        foreach (['/api/dashboard', '/api/members', "/api/members/{$member->id}", '/api/batches', '/api/seat-matrix', '/api/payments', '/api/renewals/upcoming', '/api/attendance', '/api/library', '/api/subscription'] as $url) {
            $this->actingAs($this->owner)->getJson($url)->assertOk();
        }
    }

    public function test_expired_library_cannot_change_anything(): void
    {
        $member = $this->admit('Rahul Sharma', 1, '2026-11-01');
        $this->endSubscription('2026-10-01');

        $writes = [
            ['post', '/api/admission', ['name' => 'New', 'phone' => '9876500099', 'allocations' => [['batch_id' => $this->batch->id, 'seat_id' => 1]]]],
            ['post', '/api/payments', ['member_id' => $member->id, 'amount' => 1000]],
            ['post', '/api/batches', ['name' => 'Evening', 'start_time' => '17:00', 'end_time' => '21:00', 'monthly_fee' => 800]],
            ['post', "/api/members/{$member->id}/update", ['name' => 'Renamed', 'phone' => $member->phone]],
            ['post', "/api/members/{$member->id}/deactivate", []],
            ['post', '/api/library/update', ['library_name' => 'X', 'total_seats' => 3]],
            ['post', '/api/attendance', []],
            ['delete', "/api/members/{$member->id}/id-proof", []],
        ];

        foreach ($writes as [$method, $url, $body]) {
            $this->actingAs($this->owner)->json($method, $url, $body)
                ->assertStatus(402)
                ->assertJsonPath('code', 'subscription_expired');
        }

        // Nothing slipped through.
        $this->assertSame('Rahul Sharma', $member->fresh()->name);
        $this->assertSame('active', $member->fresh()->status);
        $this->assertSame(0, $member->payments()->count());
    }

    public function test_writes_work_during_trial(): void
    {
        $member = $this->admit('Rahul Sharma', 1, '2026-11-01');
        $this->endSubscription('2026-12-24', plan: null);

        $this->actingAs($this->owner)->postJson('/api/payments', ['member_id' => $member->id, 'amount' => 1000])->assertCreated();
    }

    public function test_writes_still_work_during_grace(): void
    {
        $member = $this->admit('Rahul Sharma', 1, '2026-11-01');
        $this->endSubscription('2026-10-23 09:00:00'); // ended yesterday, grace runs 3 days

        $this->actingAs($this->owner)->postJson('/api/payments', ['member_id' => $member->id, 'amount' => 1000])->assertCreated();
    }

    public function test_own_account_can_be_edited_even_when_expired(): void
    {
        $this->endSubscription('2026-10-01');

        $this->actingAs($this->owner)->postJson('/api/me/update', [
            'name' => 'Asha K. Owner',
            'phone' => $this->owner->phone,
        ])->assertOk();
    }

    public function test_renewing_lifts_read_only_mode(): void
    {
        $member = $this->admit('Rahul Sharma', 1, '2026-11-01');
        $this->endSubscription('2026-10-01');
        $this->actingAs($this->owner)->postJson('/api/payments', ['member_id' => $member->id, 'amount' => 1000])->assertStatus(402);

        $this->endSubscription('2026-11-24');
        $this->actingAs($this->owner)->postJson('/api/payments', ['member_id' => $member->id, 'amount' => 1000])->assertCreated();
    }
}
