<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Notifications\DTO\MessageDTO;
use App\Notifications\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Concerns\BuildsLibrary;
use Tests\TestCase;

class SubscriptionReminderTest extends TestCase
{
    use BuildsLibrary, RefreshDatabase;

    /** @var list<MessageDTO> */
    private array $sent = [];

    private bool $smsDown = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-12-17 09:00:00');
        $this->createLibrary(seats: 3);
        $this->owner->update(['phone' => '9876543210']);

        $this->mock(NotificationService::class)->shouldReceive('send')->andReturnUsing(function (string $channel, MessageDTO $dto) {
            if ($this->smsDown) {
                throw new \RuntimeException('SMS provider down');
            }
            $this->sent[] = $dto;

            return ['status' => 'queued'];
        });
    }

    private function subscription(string $endsAt, ?string $plan = null): Subscription
    {
        return Subscription::updateOrCreate(['library_id' => $this->library->id], [
            'plan_id' => $plan ? SubscriptionPlan::where('code', $plan)->value('id') : null,
            'starts_at' => '2026-10-24',
            'ends_at' => $endsAt,
        ]);
    }

    private function remind(): void
    {
        $this->artisan('subscriptions:remind')->assertSuccessful();
    }

    public function test_seven_days_before_the_trial_ends(): void
    {
        $this->subscription('2026-12-24 09:00:00');

        $this->remind();

        $this->assertCount(1, $this->sent);
        $this->assertSame('+919876543210', $this->sent[0]->to);
        $this->assertStringContainsString('free trial for Gyan Library ends on 24 Dec 2026', $this->sent[0]->message);
    }

    public function test_nothing_is_sent_when_the_end_is_far_off(): void
    {
        $this->subscription('2027-01-30');

        $this->remind();

        $this->assertCount(0, $this->sent);
    }

    public function test_each_reminder_goes_out_once_even_if_the_job_runs_again(): void
    {
        $this->subscription('2026-12-24 09:00:00');

        $this->remind();
        $this->remind();

        $this->assertCount(1, $this->sent);
    }

    public function test_reminders_follow_the_countdown_then_grace_then_read_only(): void
    {
        $this->subscription('2026-12-24 09:00:00', 'monthly');
        $days = ['2026-12-17', '2026-12-18', '2026-12-21', '2026-12-23', '2026-12-25', '2026-12-26', '2026-12-28', '2026-12-29'];

        foreach ($days as $day) {
            $this->travelTo("{$day} 09:00:00");
            $this->remind();
        }

        $messages = array_map(fn (MessageDTO $dto) => $dto->message, $this->sent);
        $this->assertCount(5, $messages); // 7 days, 3 days, 1 day, ended, read-only
        $this->assertStringContainsString('Monthly plan', $messages[0]);
        $this->assertStringContainsString('ends tomorrow', $messages[2]);
        $this->assertStringContainsString('has ended', $messages[3]);
        $this->assertStringContainsString('read-only', $messages[4]);
    }

    public function test_after_missed_runs_only_the_most_relevant_reminder_is_sent(): void
    {
        // Job didn't run until 2 days before the end: send "ends in 3", not a late "ends in 7" too.
        $this->travelTo('2026-12-22 09:00:00');
        $this->subscription('2026-12-24 09:00:00');

        $this->remind();

        $this->assertCount(1, $this->sent);
        $this->assertStringContainsString('ends on 24 Dec 2026', $this->sent[0]->message);
        $this->assertStringNotContainsString('tomorrow', $this->sent[0]->message);
    }

    public function test_renewing_starts_a_fresh_set_of_reminders(): void
    {
        $subscription = $this->subscription('2026-12-24 09:00:00');
        $this->remind();

        // Paid for a month; a week before that ends, the reminder comes again.
        $subscription->update(['plan_id' => SubscriptionPlan::where('code', 'monthly')->value('id'), 'ends_at' => '2027-01-24 09:00:00']);
        $this->travelTo('2027-01-17 09:00:00');
        $this->remind();

        $this->assertCount(2, $this->sent);
    }

    public function test_a_failed_send_is_retried_on_the_next_run(): void
    {
        $this->subscription('2026-12-24 09:00:00');

        $this->smsDown = true;
        $this->remind();
        $this->assertCount(0, $this->sent);

        $this->smsDown = false;
        $this->remind();
        $this->assertCount(1, $this->sent);
    }

    public function test_the_job_is_scheduled_daily(): void
    {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->filter(fn ($event) => str_contains($event->command ?? '', 'subscriptions:remind'));

        $this->assertCount(1, $events);
        $this->assertSame('0 9 * * *', $events->first()->expression);
    }
}
