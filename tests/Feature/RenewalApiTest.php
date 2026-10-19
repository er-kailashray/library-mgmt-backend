<?php

namespace Tests\Feature;

use App\Notifications\DTO\MessageDTO;
use App\Notifications\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsLibrary;
use Tests\TestCase;

class RenewalApiTest extends TestCase
{
    use BuildsLibrary, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-20 10:00:00');
        $this->createLibrary(seats: 4, monthlyFee: 1000);
    }

    public function test_lists_members_renewing_within_the_window(): void
    {
        $this->admit('Renewing Soon', 1, '2026-10-24'); // 4 days away, inside the default 7-day window
        $this->admit('Already Overdue', 2, '2026-10-10'); // in the past, not "upcoming"
        $this->admit('Renewing Later', 3, '2026-12-01'); // outside the window

        $this->actingAs($this->owner)->getJson('/api/renewals/upcoming')
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.window_days', 7)
            ->assertJsonCount(1, 'data.renewals')
            ->assertJsonPath('data.renewals.0.name', 'Renewing Soon')
            ->assertJsonPath('data.renewals.0.phone', '9876500001')
            ->assertJsonPath('data.renewals.0.amount', 1000)
            ->assertJsonPath('data.renewals.0.renewal_date', '2026-10-24')
            ->assertJsonPath('data.renewals.0.days_until_renewal', 4);
    }

    public function test_renewals_only_counts_own_library(): void
    {
        $this->admit('Mine', 1, '2026-10-24');
        $myOwner = $this->owner;

        $this->createLibrary();
        $this->admit('Other Library', 1, '2026-10-24');

        $this->actingAs($myOwner)->getJson('/api/renewals/upcoming')
            ->assertJsonCount(1, 'data.renewals')
            ->assertJsonPath('data.renewals.0.name', 'Mine');
    }

    public function test_renewals_requires_authentication(): void
    {
        $this->getJson('/api/renewals/upcoming')->assertUnauthorized();
    }

    public function test_bulk_remind_sends_to_every_upcoming_renewal(): void
    {
        $this->admit('Renewing Soon', 1, '2026-10-24');
        $this->admit('Also Renewing Soon', 2, '2026-10-26');
        $this->admit('Renewing Later', 3, '2026-12-01');

        $this->mock(NotificationService::class)
            ->shouldReceive('send')
            ->twice()
            ->withArgs(fn (string $channel, MessageDTO $dto) => $channel === 'sms' && str_contains($dto->message, 'renews on'))
            ->andReturn(['sid' => 'SM1', 'status' => 'queued']);

        $this->actingAs($this->owner)->postJson('/api/renewals/upcoming/remind')
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.sent', 2)
            ->assertJsonPath('data.failed', 0);
    }

    public function test_bulk_remind_requires_authentication(): void
    {
        $this->postJson('/api/renewals/upcoming/remind')->assertUnauthorized();
    }
}
