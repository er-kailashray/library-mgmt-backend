<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsLibrary;
use Tests\TestCase;

class DashboardApiTest extends TestCase
{
    use BuildsLibrary, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-20 10:00:00');
        $this->createLibrary(seats: 10, monthlyFee: 1000);
    }

    public function test_dashboard_matches_mobile_contract(): void
    {
        $this->actingAs($this->owner)->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonStructure(['data' => [
                'library_name', 'owner_name', 'total_seats', 'occupied_seats', 'total_members',
                'monthly_collection', 'pending_collection', 'today_collection', 'expected_collection',
                'due_members_count', 'due_total', 'top_dues', 'critical_dues_count', 'this_month_dues_count', 'upcoming_renewals',
                'overdue_summary' => ['two_months_plus', 'one_month', 'due_this_week'],
                'recent_payments',
            ]])
            ->assertJsonPath('data.library_name', 'Gyan Library')
            ->assertJsonPath('data.owner_name', 'Asha Owner')
            ->assertJsonPath('data.total_seats', 10);
    }

    public function test_dashboard_computes_dues_and_collections(): void
    {
        $paid = $this->admit('Paid Member', 1, '2026-11-10');      // not due
        $this->admit('Renewing Soon', 2, '2026-10-24');             // due within 7 days
        $this->admit('Just Overdue', 3, '2026-10-15');              // 5 days, 1 cycle, this month
        $this->admit('Critical', 4, '2026-10-01');                  // 19 days, 1 cycle, critical
        $this->admit('Long Overdue', 5, '2026-08-10');              // 71 days, 3 cycles

        $this->recordPayment($paid, 1000, '2026-10-20');
        $this->recordPayment($paid, 500, '2026-10-05', 'partial');
        $this->recordPayment($paid, 700, '2026-09-28');             // last month, excluded

        $this->actingAs($this->owner)->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.occupied_seats', 5)
            ->assertJsonPath('data.total_members', 5)
            ->assertJsonPath('data.monthly_collection', 1500)
            ->assertJsonPath('data.today_collection', 1000)
            ->assertJsonPath('data.expected_collection', 5000)
            // 1 + 1 + 3 missed cycles at 1000 each
            ->assertJsonPath('data.pending_collection', 5000)
            ->assertJsonPath('data.due_members_count', 3)
            ->assertJsonPath('data.critical_dues_count', 2)
            ->assertJsonPath('data.this_month_dues_count', 2)
            ->assertJsonPath('data.upcoming_renewals', 1)
            ->assertJsonPath('data.overdue_summary', [
                'two_months_plus' => 1,
                'one_month' => 2,
                'due_this_week' => 1,
            ])
            ->assertJsonCount(3, 'data.recent_payments')
            ->assertJsonPath('data.recent_payments.0.name', 'Paid Member')
            ->assertJsonPath('data.recent_payments.0.seat', 1);
    }

    public function test_dashboard_requires_authentication(): void
    {
        $this->getJson('/api/dashboard')->assertUnauthorized();
    }

    public function test_dashboard_returns_404_without_library(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/dashboard')
            ->assertNotFound()
            ->assertJsonPath('status', false);
    }

    public function test_dashboard_only_counts_own_library(): void
    {
        $this->admit('Mine', 1, '2026-10-01');
        $myOwner = $this->owner;

        $this->createLibrary();
        $this->admit('Other Library', 1, '2026-10-01');

        $this->actingAs($myOwner)->getJson('/api/dashboard')
            ->assertJsonPath('data.due_members_count', 1)
            ->assertJsonPath('data.total_members', 1);
    }

    public function test_dashboard_names_the_most_overdue_members_first(): void
    {
        $this->admit('Due Today', 1, '2026-10-20');
        $this->admit('Long Overdue', 2, '2026-08-25');
        $this->admit('Bit Late', 3, '2026-10-10');
        $this->admit('Paid Up', 4, '2026-11-15');
        $this->admit('Also Late', 5, '2026-10-12');

        $this->actingAs($this->owner)->getJson('/api/dashboard')
            ->assertJsonPath('data.due_members_count', 4)
            // Aug 25 -> 2 missed cycles (2000); the other three owe one fee each.
            ->assertJsonPath('data.due_total', 5000)
            ->assertJsonCount(3, 'data.top_dues')
            ->assertJsonPath('data.top_dues.0.name', 'Long Overdue')
            ->assertJsonPath('data.top_dues.0.amount', 2000)
            ->assertJsonPath('data.top_dues.1.name', 'Bit Late')
            ->assertJsonPath('data.top_dues.1.days_overdue', 10)
            ->assertJsonPath('data.top_dues.2.name', 'Also Late');
    }
}
