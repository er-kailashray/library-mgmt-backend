<?php

namespace Tests\Feature;

use App\Models\AdminActivity;
use App\Models\Library;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Settings\AppSettings;
use App\Services\Subscriptions\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\BuildsLibrary;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use BuildsLibrary, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-10 10:00:00');
        $this->admin = User::factory()->create(['phone' => '9000000001', 'role' => 'admin', 'password' => Hash::make('admin-pass-1')]);
        $this->createLibrary();
        app(SubscriptionService::class)->startTrial($this->library);
    }

    // ---- who can get in

    public function test_signed_out_visitors_are_sent_to_sign_in(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_admin_can_sign_in_and_see_libraries(): void
    {
        $this->post('/admin/login', ['phone' => '9000000001', 'password' => 'admin-pass-1'])->assertRedirect(route('admin.libraries'));

        $this->get('/admin')->assertOk()->assertSee('Gyan Library')->assertSee('On free trial');
    }

    public function test_library_owner_cannot_sign_in_to_admin(): void
    {
        $this->owner->update(['password' => Hash::make('owner-pass-1')]);

        $this->post('/admin/login', ['phone' => $this->owner->phone, 'password' => 'owner-pass-1'])->assertSessionHasErrors('phone');
        $this->assertGuest();
    }

    public function test_owner_signed_in_on_the_web_is_kept_out(): void
    {
        $this->actingAs($this->owner)->get('/admin')->assertRedirect(route('admin.login'));
    }

    public function test_wrong_password_is_refused(): void
    {
        $this->post('/admin/login', ['phone' => '9000000001', 'password' => 'nope'])->assertSessionHasErrors('phone');
        $this->assertGuest();
    }

    // ---- default trial length

    public function test_new_libraries_get_the_trial_length_from_settings(): void
    {
        $this->actingAs($this->admin)->post('/admin/settings/trial', ['trial_days' => 15])->assertSessionHasNoErrors();

        $library = Library::create(['user_id' => User::factory()->create()->id, 'library_name' => 'New Library', 'total_seats' => 5]);
        $trial = app(SubscriptionService::class)->startTrial($library);

        $this->assertSame('2026-11-25', $trial->ends_at->toDateString());
        $this->assertDatabaseHas('admin_activities', ['action' => 'settings.trial_days', 'user_id' => $this->admin->id]);
    }

    public function test_changing_the_trial_length_leaves_existing_trials_alone(): void
    {
        $before = $this->library->subscription()->first()->ends_at->toDateString();

        $this->actingAs($this->admin)->post('/admin/settings/trial', ['trial_days' => 7]);

        $this->assertSame($before, $this->library->subscription()->first()->ends_at->toDateString());
    }

    public function test_trial_length_must_be_sensible(): void
    {
        $this->actingAs($this->admin)->post('/admin/settings/trial', ['trial_days' => 0])->assertSessionHasErrors('trial_days');
        $this->actingAs($this->admin)->post('/admin/settings/trial', ['trial_days' => 400])->assertSessionHasErrors('trial_days');
        $this->assertSame(60, app(AppSettings::class)->trialDays());
    }

    // ---- one library's trial or plan

    public function test_admin_can_extend_one_librarys_trial(): void
    {
        $subscription = $this->library->subscription()->first();
        $before = $subscription->ends_at->copy();

        $this->actingAs($this->admin)
            ->post("/admin/libraries/{$this->library->id}/subscription", ['days' => 30, 'reason' => 'Launch offer'])
            ->assertSessionHasNoErrors();

        $this->assertSame($before->addDays(30)->toDateString(), $subscription->fresh()->ends_at->toDateString());
        $activity = AdminActivity::where('action', 'subscription.adjusted')->firstOrFail();
        $this->assertSame(30, $activity->details['days']);
        $this->assertSame('Launch offer', $activity->details['reason']);
        $this->assertSame($this->library->id, $activity->library_id);
    }

    public function test_admin_can_shorten_a_trial_but_not_before_it_started(): void
    {
        $subscription = $this->library->subscription()->first();
        $before = $subscription->ends_at->copy();

        $this->actingAs($this->admin)->post("/admin/libraries/{$this->library->id}/subscription", ['days' => -10])->assertSessionHasNoErrors();
        $this->assertSame($before->copy()->subDays(10)->toDateString(), $subscription->fresh()->ends_at->toDateString());

        $this->actingAs($this->admin)->post("/admin/libraries/{$this->library->id}/subscription", ['days' => -365])->assertSessionHasErrors('days');
    }

    public function test_adjusting_one_library_leaves_others_alone(): void
    {
        $other = Library::create(['user_id' => User::factory()->create()->id, 'library_name' => 'Other', 'total_seats' => 5]);
        $otherTrial = app(SubscriptionService::class)->startTrial($other);

        $this->actingAs($this->admin)->post("/admin/libraries/{$this->library->id}/subscription", ['days' => 45]);

        $this->assertSame($otherTrial->ends_at->toDateString(), Subscription::where('library_id', $other->id)->first()->ends_at->toDateString());
    }

    // ---- plans

    public function test_admin_can_change_a_plan_price_and_availability(): void
    {
        $monthly = SubscriptionPlan::where('code', 'monthly')->firstOrFail();

        $this->actingAs($this->admin)->post("/admin/settings/plans/{$monthly->id}", ['name' => 'Monthly', 'price' => 349])->assertSessionHasNoErrors();

        $monthly->refresh();
        $this->assertEquals(349, $monthly->price);
        $this->assertFalse($monthly->is_active); // checkbox left unticked
        $this->assertDatabaseHas('admin_activities', ['action' => 'plan.updated']);
    }

    // ---- pages render

    public function test_admin_pages_render(): void
    {
        $this->actingAs($this->admin)->post("/admin/libraries/{$this->library->id}/subscription", ['days' => 5, 'reason' => 'Test']);

        $this->actingAs($this->admin)->get("/admin/libraries/{$this->library->id}")->assertOk()->assertSee('Extend or shorten this trial')->assertSee('Test');
        $this->actingAs($this->admin)->get('/admin/settings')->assertOk()->assertSee('Free trial for new libraries');
        $this->actingAs($this->admin)->get('/admin/activity')->assertOk()->assertSee('Extended trial by 5 days');
        $this->actingAs($this->admin)->get('/admin?q=Gyan&status=trial')->assertOk()->assertSee('Gyan Library');
    }
}
