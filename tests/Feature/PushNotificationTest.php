<?php

namespace Tests\Feature;

use App\Models\PushToken;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsLibrary;
use Tests\TestCase;

class PushNotificationTest extends TestCase
{
    use BuildsLibrary, RefreshDatabase;

    private const TOKEN = 'ExponentPushToken[device-one]';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-10 09:00:00');
        $this->createLibrary(seats: 5);
    }

    private function fakeExpo(string $status = 'ok'): void
    {
        Http::fake(['exp.host/*' => Http::response([
            'data' => [$status === 'ok'
                ? ['status' => 'ok', 'id' => 'ticket-1']
                : ['status' => 'error', 'message' => 'not registered', 'details' => ['error' => $status]]],
        ])]);
    }

    private function registerDevice(?User $user = null): void
    {
        PushToken::create(['user_id' => ($user ?? $this->owner)->id, 'token' => self::TOKEN, 'platform' => 'android']);
    }

    /** @return list<array<string, mixed>> every message sent to Expo */
    private function sentMessages(): array
    {
        return Http::recorded()->flatMap(fn ($pair) => json_decode($pair[0]->body(), true))->values()->all();
    }

    public function test_owner_can_register_a_device(): void
    {
        $this->actingAs($this->owner)->postJson('/api/me/push-tokens', ['token' => self::TOKEN, 'platform' => 'ios'])->assertOk();

        $this->assertSame([self::TOKEN], $this->owner->pushTokens()->pluck('token')->all());
    }

    public function test_a_device_moves_to_the_owner_who_signs_in_on_it(): void
    {
        $this->registerDevice();
        $other = User::factory()->create();

        $this->actingAs($other)->postJson('/api/me/push-tokens', ['token' => self::TOKEN])->assertOk();

        $this->assertSame(0, $this->owner->pushTokens()->count());
        $this->assertSame(1, $other->pushTokens()->count());
    }

    public function test_only_expo_push_tokens_are_accepted(): void
    {
        $this->actingAs($this->owner)->postJson('/api/me/push-tokens', ['token' => 'not-a-token'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('token');
    }

    public function test_owner_can_remove_a_device(): void
    {
        $this->registerDevice();

        $this->actingAs($this->owner)->postJson('/api/me/push-tokens/remove', ['token' => self::TOKEN])->assertOk();

        $this->assertSame(0, PushToken::count());
    }

    public function test_owner_can_turn_notifications_off(): void
    {
        $this->actingAs($this->owner)->postJson('/api/me/notification-settings', ['enabled' => false])
            ->assertOk()
            ->assertJsonPath('data.push_notifications_enabled', false);

        $this->actingAs($this->owner)->postJson('/api/me')->assertJsonPath('push_notifications_enabled', false);
    }

    public function test_morning_sends_renewals_due_today_and_a_dues_summary_once(): void
    {
        $this->fakeExpo();
        $this->registerDevice();
        $this->admit('Rahul', 1, '2026-11-10');   // renews today
        $this->admit('Priya', 2, '2026-11-01');   // overdue
        $this->admit('Aman', 3, '2026-11-13');    // renews in 3 days

        $this->artisan('notifications:morning')->assertSuccessful();
        $this->artisan('notifications:morning')->assertSuccessful();

        $messages = $this->sentMessages();
        $this->assertCount(2, $messages);

        $this->assertSame('1 renewal due today', $messages[0]['title']);
        $this->assertSame('Rahul · ₹1,000 to collect', $messages[0]['body']);
        $this->assertSame('/payments', $messages[0]['data']['url']);

        $this->assertSame('Today at Gyan Library', $messages[1]['title']);
        $this->assertSame('1 member overdue · ₹1,000 pending · 1 renewal in the next 7 days', $messages[1]['body']);
    }

    public function test_morning_sends_nothing_when_nothing_is_due(): void
    {
        $this->fakeExpo();
        $this->registerDevice();
        $this->admit('Rahul', 1, '2026-12-25');

        $this->artisan('notifications:morning')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_nothing_is_sent_when_the_owner_turned_notifications_off(): void
    {
        $this->fakeExpo();
        $this->registerDevice();
        $this->owner->update(['push_notifications_enabled' => false]);
        $this->admit('Priya', 2, '2026-11-01');

        $this->artisan('notifications:morning')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_batch_start_alert_goes_out_five_minutes_before_once(): void
    {
        $this->fakeExpo();
        $this->registerDevice();
        $this->admit('Rahul', 1, '2026-12-01');

        $this->travelTo('2026-11-10 07:54:00');
        $this->artisan('notifications:batch-starts')->assertSuccessful();
        Http::assertNothingSent();

        $this->travelTo('2026-11-10 07:55:00');
        $this->artisan('notifications:batch-starts')->assertSuccessful();
        $this->travelTo('2026-11-10 07:55:40');
        $this->artisan('notifications:batch-starts')->assertSuccessful();

        $messages = $this->sentMessages();
        $this->assertCount(1, $messages);
        $this->assertSame('Morning starts in 5 minutes', $messages[0]['title']);
        $this->assertSame('8:00 AM · 1 member enrolled. Tap to mark attendance.', $messages[0]['body']);
        $this->assertSame('/attendance', $messages[0]['data']['url']);
    }

    public function test_uninstalled_devices_are_forgotten(): void
    {
        $this->fakeExpo('DeviceNotRegistered');
        $this->registerDevice();
        $this->admit('Priya', 2, '2026-11-01');

        $this->artisan('notifications:morning')->assertSuccessful();

        $this->assertSame(0, PushToken::count());
    }

    public function test_subscription_reminder_is_pushed_and_counts_even_when_sms_fails(): void
    {
        $this->fakeExpo();
        $this->registerDevice();
        $this->mock(NotificationService::class)->shouldReceive('send')->andThrow(new \RuntimeException('SMS provider down'));
        Subscription::updateOrCreate(['library_id' => $this->library->id], [
            'plan_id' => null,
            'starts_at' => '2026-09-11',
            'ends_at' => '2026-11-11 09:00:00',
        ]);

        $this->artisan('subscriptions:remind')->assertSuccessful();
        $this->artisan('subscriptions:remind')->assertSuccessful();

        $messages = $this->sentMessages();
        $this->assertCount(1, $messages);
        $this->assertSame('Free trial ends tomorrow', $messages[0]['title']);
        $this->assertSame('/subscription', $messages[0]['data']['url']);
        $this->assertDatabaseHas('subscription_reminders', ['kind' => 'ends_in_1']);
    }
}
