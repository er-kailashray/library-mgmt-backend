<?php

namespace Tests\Feature;

use App\Models\OwnerNotification;
use App\Models\PushToken;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsLibrary;
use Tests\TestCase;

class NotificationInboxTest extends TestCase
{
    use BuildsLibrary, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-10 09:00:00');
        $this->createLibrary(seats: 5);
        Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok', 'id' => 'ticket-1']]])]);
    }

    private function inboxFor(User $owner, int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $owner->ownerNotifications()->create(['type' => 'dues_summary', 'title' => "Alert {$i}", 'body' => 'Body']);
        }
    }

    public function test_morning_alerts_reach_the_inbox_without_a_device_and_only_once(): void
    {
        $this->admit('Rahul', 1, '2026-11-10');   // renews today
        $this->admit('Priya', 2, '2026-11-01');   // overdue

        $this->artisan('notifications:morning')->assertSuccessful();
        $this->artisan('notifications:morning')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(
            [OwnerNotification::TYPE_RENEWALS_DUE, OwnerNotification::TYPE_DUES_SUMMARY],
            $this->owner->ownerNotifications()->orderBy('id')->pluck('type')->all(),
        );
        $this->assertDatabaseHas('owner_notifications', ['title' => '1 renewal due today', 'url' => '/payments', 'library_id' => $this->library->id]);
    }

    public function test_alerts_still_reach_the_inbox_with_push_turned_off(): void
    {
        PushToken::create(['user_id' => $this->owner->id, 'token' => 'ExponentPushToken[one]']);
        $this->owner->update(['push_notifications_enabled' => false]);
        $this->admit('Priya', 2, '2026-11-01');

        $this->artisan('notifications:morning')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(1, $this->owner->ownerNotifications()->count());
    }

    public function test_batch_start_heads_up_is_push_only(): void
    {
        $this->travelTo('2026-11-10 07:55:00');
        PushToken::create(['user_id' => $this->owner->id, 'token' => 'ExponentPushToken[one]']);
        $this->admit('Rahul', 1, '2026-12-01');

        $this->artisan('notifications:batch-starts')->assertSuccessful();

        Http::assertSentCount(1);
        $this->assertSame(0, OwnerNotification::count());
    }

    public function test_a_retried_subscription_reminder_is_saved_once(): void
    {
        $this->mock(NotificationService::class)->shouldReceive('send')->andThrow(new \RuntimeException('SMS provider down'));
        Subscription::updateOrCreate(['library_id' => $this->library->id], [
            'plan_id' => null,
            'starts_at' => '2026-09-11',
            'ends_at' => '2026-11-11 09:00:00',
        ]);

        // No device and no SMS: nothing is delivered, so the job tries again next run.
        $this->artisan('subscriptions:remind')->assertSuccessful();
        $this->artisan('subscriptions:remind')->assertSuccessful();

        $this->assertDatabaseCount('subscription_reminders', 0);
        $this->assertSame(1, $this->owner->ownerNotifications()->where('type', OwnerNotification::TYPE_SUBSCRIPTION)->count());
        $this->assertDatabaseHas('owner_notifications', ['title' => 'Free trial ends tomorrow', 'url' => '/subscription']);
    }

    public function test_inbox_lists_newest_first_in_pages_with_the_unread_count(): void
    {
        $this->inboxFor($this->owner, 32);
        $this->inboxFor(User::factory()->create(), 3);

        $first = $this->actingAs($this->owner)->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonCount(30, 'data.items')
            ->assertJsonPath('data.items.0.title', 'Alert 32')
            ->assertJsonPath('data.items.0.read', false)
            ->assertJsonPath('data.has_more', true)
            ->assertJsonPath('data.unread_count', 32);

        $this->actingAs($this->owner)->getJson('/api/notifications?before='.$first->json('data.items.29.id'))
            ->assertOk()
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.1.title', 'Alert 1')
            ->assertJsonPath('data.has_more', false);
    }

    public function test_marking_read_touches_only_the_owners_own_alerts(): void
    {
        $this->inboxFor($this->owner, 3);
        $other = User::factory()->create();
        $this->inboxFor($other, 1);
        $ids = $this->owner->ownerNotifications()->orderBy('id')->pluck('id');
        $othersId = $other->ownerNotifications()->value('id');

        $this->actingAs($this->owner)->postJson('/api/notifications/read', ['ids' => [$ids[0], $othersId]])
            ->assertOk()
            ->assertJsonPath('data.unread_count', 2);
        $this->assertNull(OwnerNotification::find($othersId)->read_at);

        $this->actingAs($this->owner)->postJson('/api/notifications/read')->assertOk()->assertJsonPath('data.unread_count', 0);
        $this->actingAs($this->owner)->getJson('/api/notifications/unread-count')->assertOk()->assertJsonPath('data.unread_count', 0);
        $this->assertSame(1, $other->ownerNotifications()->whereNull('read_at')->count());
    }

    public function test_inbox_needs_sign_in(): void
    {
        $this->getJson('/api/notifications')->assertUnauthorized();
    }
}
