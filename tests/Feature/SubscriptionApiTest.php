<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsLibrary;
use Tests\TestCase;

class SubscriptionApiTest extends TestCase
{
    use BuildsLibrary, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-24 10:00:00');
        $this->createLibrary(seats: 3);
    }

    private function subscribe(string $planCode, string $startsAt, string $endsAt): void
    {
        Subscription::updateOrCreate(['library_id' => $this->library->id], [
            'plan_id' => SubscriptionPlan::where('code', $planCode)->value('id'),
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ]);
    }

    public function test_plans_are_seeded_with_launch_prices(): void
    {
        $this->actingAs($this->owner)->getJson('/api/subscription')
            ->assertOk()
            ->assertJsonCount(2, 'data.plans')
            ->assertJsonPath('data.plans.0.code', 'monthly')
            ->assertJsonPath('data.plans.0.price', 299)
            ->assertJsonPath('data.plans.1.code', 'yearly')
            ->assertJsonPath('data.plans.1.price', 2999)
            ->assertJsonPath('data.plans.1.duration_months', 12);
    }

    public function test_registration_starts_a_two_month_trial(): void
    {
        $this->postJson('/api/register', [
            'name' => 'New Owner',
            'phone' => '9123456700',
            'password' => 'secret123',
            'library_name' => 'Fresh Library',
            'total_seats' => 5,
        ])->assertOk();

        $library = Library::where('library_name', 'Fresh Library')->firstOrFail();
        $this->assertSame('2026-12-24', $library->subscription->ends_at->toDateString());
        $this->assertNull($library->subscription->plan_id);
    }

    public function test_library_without_a_subscription_gets_a_trial_on_first_check(): void
    {
        $this->actingAs($this->owner)->getJson('/api/subscription')
            ->assertJsonPath('data.state', 'trial')
            ->assertJsonPath('data.can_write', true)
            ->assertJsonPath('data.plan', null)
            ->assertJsonPath('data.days_left', 61);

        $this->assertSame(1, Subscription::where('library_id', $this->library->id)->count());
    }

    public function test_paid_plan_is_active(): void
    {
        $this->subscribe('yearly', '2026-10-01', '2027-10-01');

        $this->actingAs($this->owner)->getJson('/api/subscription')
            ->assertJsonPath('data.state', 'active')
            ->assertJsonPath('data.plan.code', 'yearly')
            ->assertJsonPath('data.can_write', true);
    }

    public function test_just_after_ending_is_grace_with_full_access(): void
    {
        $this->subscribe('monthly', '2026-09-22', '2026-10-22 10:00:00');

        $this->actingAs($this->owner)->getJson('/api/subscription')
            ->assertJsonPath('data.state', 'grace')
            ->assertJsonPath('data.can_write', true)
            // Grace ends 25 Oct: 1 day of full access left.
            ->assertJsonPath('data.days_left', 1);
    }

    public function test_after_grace_it_is_expired_and_read_only(): void
    {
        $this->subscribe('monthly', '2026-09-01', '2026-10-01');

        $this->actingAs($this->owner)->getJson('/api/subscription')
            ->assertJsonPath('data.state', 'expired')
            ->assertJsonPath('data.can_write', false)
            ->assertJsonPath('data.days_left', 0);
    }

    public function test_trial_moves_to_grace_then_expired_as_time_passes(): void
    {
        $this->actingAs($this->owner)->getJson('/api/subscription')->assertJsonPath('data.state', 'trial');

        $this->travelTo('2026-12-25 10:00:00');
        $this->actingAs($this->owner)->getJson('/api/subscription')->assertJsonPath('data.state', 'grace');

        $this->travelTo('2026-12-28 10:00:00');
        $this->actingAs($this->owner)->getJson('/api/subscription')->assertJsonPath('data.state', 'expired');
    }

    public function test_subscription_requires_authentication(): void
    {
        $this->getJson('/api/subscription')->assertUnauthorized();
    }

    public function test_owner_only_sees_their_own_librarys_subscription(): void
    {
        $myOwner = $this->owner;
        $this->subscribe('yearly', '2026-10-01', '2027-10-01');

        $this->createLibrary();

        $this->actingAs($this->owner)->getJson('/api/subscription')->assertJsonPath('data.state', 'trial');
        $this->actingAs($myOwner)->getJson('/api/subscription')->assertJsonPath('data.state', 'active');
    }
}
