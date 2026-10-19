<?php

namespace Tests\Feature;

use App\Notifications\DTO\MessageDTO;
use App\Notifications\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsLibrary;
use Tests\TestCase;

class ReminderApiTest extends TestCase
{
    use BuildsLibrary, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-20 10:00:00');
        $this->createLibrary(seats: 3, monthlyFee: 1000);
    }

    public function test_can_remind_a_single_overdue_member(): void
    {
        $member = $this->admit('Rahul Sharma', 1, '2026-09-10');

        $this->mock(NotificationService::class)
            ->shouldReceive('send')
            ->once()
            ->withArgs(function (string $channel, MessageDTO $dto) {
                return $channel === 'sms'
                    && $dto->to === '+919876500001'
                    && str_contains($dto->message, 'Rahul Sharma')
                    && str_contains($dto->message, 'overdue');
            })
            ->andReturn(['sid' => 'SM123', 'status' => 'queued']);

        $this->actingAs($this->owner)->postJson("/api/members/{$member->id}/remind")
            ->assertOk()
            ->assertJsonPath('status', true);
    }

    public function test_remind_uses_renewal_message_for_a_member_who_is_not_yet_overdue(): void
    {
        $member = $this->admit('Rahul Sharma', 1, '2026-10-24'); // 4 days away, not overdue

        $this->mock(NotificationService::class)
            ->shouldReceive('send')
            ->once()
            ->withArgs(function (string $channel, MessageDTO $dto) {
                return $channel === 'sms'
                    && str_contains($dto->message, 'renews on')
                    && ! str_contains($dto->message, 'overdue');
            })
            ->andReturn(['sid' => 'SM123', 'status' => 'queued']);

        $this->actingAs($this->owner)->postJson("/api/members/{$member->id}/remind")
            ->assertOk()
            ->assertJsonPath('status', true);
    }

    public function test_remind_returns_502_when_sms_provider_fails(): void
    {
        $member = $this->admit('Rahul Sharma', 1, '2026-09-10');

        $this->mock(NotificationService::class)
            ->shouldReceive('send')
            ->once()
            ->andThrow(new \Exception('Twilio auth failed'));

        $this->actingAs($this->owner)->postJson("/api/members/{$member->id}/remind")
            ->assertStatus(502)
            ->assertJsonPath('status', false);
    }

    public function test_cannot_remind_another_librarys_member(): void
    {
        $myOwner = $this->owner;

        $this->createLibrary();
        $stranger = $this->admit('Stranger', 1, '2026-10-10');

        $this->mock(NotificationService::class)->shouldNotReceive('send');

        $this->actingAs($myOwner)->postJson("/api/members/{$stranger->id}/remind")
            ->assertNotFound();
    }

    public function test_remind_requires_authentication(): void
    {
        $member = $this->admit('Rahul Sharma', 1, '2026-09-10');

        $this->postJson("/api/members/{$member->id}/remind")->assertUnauthorized();
    }

    public function test_bulk_remind_sends_to_every_overdue_member(): void
    {
        $this->admit('Rahul Sharma', 1, '2026-09-10');
        $this->admit('Aman Verma', 2, '2026-08-01');
        $notDue = $this->admit('Paid Up', 3, '2026-11-01');

        $this->mock(NotificationService::class)
            ->shouldReceive('send')
            ->twice()
            ->andReturn(['sid' => 'SM1', 'status' => 'queued']);

        $this->actingAs($this->owner)->postJson('/api/reminders/overdue')
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.sent', 2)
            ->assertJsonPath('data.failed', 0);
    }

    public function test_bulk_remind_tolerates_individual_failures(): void
    {
        $this->admit('Rahul Sharma', 1, '2026-09-10');
        $this->admit('Aman Verma', 2, '2026-08-01');

        $this->mock(NotificationService::class)
            ->shouldReceive('send')
            ->twice()
            ->andReturnUsing(function () {
                static $call = 0;
                $call++;
                if ($call === 1) {
                    throw new \Exception('Invalid number');
                }

                return ['sid' => 'SM1', 'status' => 'queued'];
            });

        $this->actingAs($this->owner)->postJson('/api/reminders/overdue')
            ->assertOk()
            ->assertJsonPath('data.sent', 1)
            ->assertJsonPath('data.failed', 1);
    }

    public function test_bulk_remind_requires_authentication(): void
    {
        $this->postJson('/api/reminders/overdue')->assertUnauthorized();
    }
}
