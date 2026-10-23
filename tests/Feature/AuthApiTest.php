<?php

namespace Tests\Feature;

use App\Models\OtpVerification;
use App\Models\User;
use App\Notifications\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_logs_otp_without_sending_sms(): void
    {
        $this->mock(NotificationService::class)->shouldNotReceive('send');

        $loggedOtp = null;
        Log::shouldReceive('info')
            ->once()
            ->withArgs(function ($message, $context) use (&$loggedOtp) {
                $loggedOtp = $context['otp'];

                return $context['to'] === '+919876543210';
            });

        $this->postJson('/api/register', [
            'name' => 'Owner',
            'phone' => '9876543210',
            'password' => 'secret123',
            'library_name' => 'Test Library',
            'total_seats' => 3,
        ])->assertOk();

        $user = User::where('phone', '9876543210')->first();
        $this->assertCount(3, $user->libraries()->first()->seats);

        $this->postJson('/api/verify-otp', [
            'phone' => '9876543210',
            'otp' => (string) $loggedOtp,
            'type' => 'register',
        ])->assertOk()->assertJsonStructure(['token']);

        $this->assertTrue(OtpVerification::where('user_id', $user->id)->first()->is_verified);
    }

    public function test_verified_otp_cannot_be_reused(): void
    {
        $user = User::factory()->create(['phone' => '9876543210']);
        OtpVerification::create([
            'user_id' => $user->id,
            'otp' => Hash::make('123456'),
            'type' => 'login',
            'expires_at' => now()->addMinutes(5),
        ]);

        $payload = ['phone' => '9876543210', 'otp' => '123456', 'type' => 'login'];

        $this->postJson('/api/verify-otp', $payload)->assertOk();
        $this->postJson('/api/verify-otp', $payload)->assertStatus(400);
    }

    public function test_otp_is_locked_after_max_attempts(): void
    {
        $user = User::factory()->create(['phone' => '9876543210']);
        OtpVerification::create([
            'user_id' => $user->id,
            'otp' => Hash::make('123456'),
            'type' => 'login',
            'expires_at' => now()->addMinutes(5),
        ]);

        for ($i = 0; $i < OtpVerification::MAX_ATTEMPTS; $i++) {
            $this->postJson('/api/verify-otp', [
                'phone' => '9876543210',
                'otp' => '000000',
                'type' => 'login',
            ])->assertStatus(400);
        }

        // Even the correct OTP is rejected once the limit is reached
        $this->postJson('/api/verify-otp', [
            'phone' => '9876543210',
            'otp' => '123456',
            'type' => 'login',
        ])->assertStatus(429);
    }

    public function test_can_update_own_name_and_phone(): void
    {
        $user = User::factory()->create(['name' => 'Asha Owner', 'phone' => '9876543210']);

        $this->actingAs($user)->postJson('/api/me/update', [
            'name' => 'Asha K. Owner',
            'phone' => '9123456780',
        ])->assertOk()->assertJsonPath('user.name', 'Asha K. Owner');

        $user->refresh();
        $this->assertSame('Asha K. Owner', $user->name);
        $this->assertSame('9123456780', $user->phone);
    }

    public function test_cannot_update_phone_to_one_already_used_by_another_user(): void
    {
        User::factory()->create(['phone' => '9876500001']);
        $user = User::factory()->create(['phone' => '9876543210']);

        $this->actingAs($user)->postJson('/api/me/update', [
            'name' => $user->name,
            'phone' => '9876500001',
        ])->assertStatus(422)->assertJsonValidationErrors('phone');
    }

    public function test_update_profile_requires_authentication(): void
    {
        $this->postJson('/api/me/update', [
            'name' => 'Someone',
            'phone' => '9876543210',
        ])->assertUnauthorized();
    }

    public function test_can_upload_profile_photo_and_replace_it(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();

        $first = $this->actingAs($user)->postJson('/api/me/photo', [
            'photo' => UploadedFile::fake()->image('me.jpg'),
        ])->assertOk();

        $firstPath = $user->fresh()->profile_photo;
        Storage::disk('public')->assertExists($firstPath);
        $this->assertNotNull($first->json('user.profile_photo_url'));

        $this->actingAs($user)->postJson('/api/me/photo', [
            'photo' => UploadedFile::fake()->image('me2.jpg'),
        ])->assertOk();

        Storage::disk('public')->assertMissing($firstPath);
        Storage::disk('public')->assertExists($user->fresh()->profile_photo);
    }

    public function test_profile_photo_must_be_an_image(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/me/photo', [
            'photo' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'),
        ])->assertStatus(422)->assertJsonValidationErrors('photo');
    }

    public function test_profile_photo_url_uses_the_host_the_app_called(): void
    {
        // APP_URL is often "http://localhost", which a phone on the LAN can't reach.
        Storage::fake('public');
        $user = User::factory()->create();

        $url = $this->actingAs($user)
            ->postJson('http://192.168.1.10:8000/api/me/photo', ['photo' => UploadedFile::fake()->image('me.jpg')])
            ->assertOk()
            ->json('user.profile_photo_url');

        $this->assertStringStartsWith('http://192.168.1.10:8000/storage/profile-photos/', $url);
    }

    private function registerPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Owner',
            'phone' => '9876543210',
            'password' => 'secret123',
            'library_name' => 'Test Library',
            'total_seats' => 3,
        ], $overrides);
    }

    public function test_verifying_the_register_otp_marks_the_phone_verified(): void
    {
        $user = User::factory()->phoneUnverified()->create(['phone' => '9876543210']);
        OtpVerification::create([
            'user_id' => $user->id,
            'otp' => Hash::make('123456'),
            'type' => 'register',
            'expires_at' => now()->addMinutes(5),
        ]);

        $this->postJson('/api/verify-otp', ['phone' => '9876543210', 'otp' => '123456', 'type' => 'register'])
            ->assertOk()
            ->assertJsonStructure(['token', 'user']);

        $this->assertNotNull($user->fresh()->phone_verified_at);
    }

    public function test_unverified_number_can_register_again_and_gets_a_new_code(): void
    {
        $this->postJson('/api/register', $this->registerPayload())->assertOk();

        $this->postJson('/api/register', $this->registerPayload([
            'name' => 'Owner Again',
            'library_name' => 'Renamed Library',
            'total_seats' => 5,
        ]))->assertOk();

        $this->assertSame(1, User::where('phone', '9876543210')->count());
        $user = User::where('phone', '9876543210')->first();
        $library = $user->libraries()->first();

        $this->assertSame('Owner Again', $user->name);
        $this->assertSame('Renamed Library', $library->library_name);
        $this->assertCount(5, $library->seats);
        $this->assertSame(1, $user->libraries()->count());
        $this->assertSame(2, OtpVerification::where('user_id', $user->id)->where('type', 'register')->count());
    }

    public function test_verified_number_cannot_register_again(): void
    {
        User::factory()->create(['phone' => '9876543210']);

        $this->postJson('/api/register', $this->registerPayload())
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');
    }

    public function test_unverified_user_must_verify_before_login(): void
    {
        $user = User::factory()->phoneUnverified()->create([
            'phone' => '9876543210',
            'password' => Hash::make('secret123'),
        ]);

        $this->postJson('/api/login', ['phone' => '9876543210', 'password' => 'secret123'])
            ->assertForbidden()
            ->assertJsonPath('requires_verification', true)
            ->assertJsonMissingPath('token');

        $this->assertSame(1, OtpVerification::where('user_id', $user->id)->where('type', 'register')->count());
    }

    public function test_verified_user_can_login(): void
    {
        User::factory()->create(['phone' => '9876543210', 'password' => Hash::make('secret123')]);

        $this->postJson('/api/login', ['phone' => '9876543210', 'password' => 'secret123'])
            ->assertOk()
            ->assertJsonStructure(['token', 'user']);
    }

    public function test_resend_otp_waits_for_the_cooldown_then_sends_a_new_code(): void
    {
        $this->postJson('/api/register', $this->registerPayload())->assertOk();
        $user = User::where('phone', '9876543210')->first();

        $this->postJson('/api/resend-otp', ['phone' => '9876543210', 'type' => 'register'])
            ->assertStatus(429)
            ->assertJsonStructure(['retry_after']);

        $this->travel(31)->seconds();

        $this->postJson('/api/resend-otp', ['phone' => '9876543210', 'type' => 'register'])
            ->assertOk()
            ->assertJsonPath('retry_after', 30);

        $this->assertSame(2, OtpVerification::where('user_id', $user->id)->where('type', 'register')->count());
    }

    public function test_only_the_newest_code_works_after_a_resend(): void
    {
        $user = User::factory()->phoneUnverified()->create(['phone' => '9876543210']);
        OtpVerification::create([
            'user_id' => $user->id,
            'otp' => Hash::make('111111'),
            'type' => 'register',
            'expires_at' => now()->addMinutes(5),
        ]);
        OtpVerification::create([
            'user_id' => $user->id,
            'otp' => Hash::make('222222'),
            'type' => 'register',
            'expires_at' => now()->addMinutes(5),
        ]);

        $this->postJson('/api/verify-otp', ['phone' => '9876543210', 'otp' => '111111', 'type' => 'register'])
            ->assertStatus(400);
        $this->postJson('/api/verify-otp', ['phone' => '9876543210', 'otp' => '222222', 'type' => 'register'])
            ->assertOk();
    }

    public function test_resend_otp_is_refused_for_a_verified_number(): void
    {
        User::factory()->create(['phone' => '9876543210']);

        $this->postJson('/api/resend-otp', ['phone' => '9876543210', 'type' => 'register'])
            ->assertStatus(422);
    }
}
