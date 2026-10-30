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

        $registrationToken = $this->postJson('/api/register', [
            'name' => 'Owner',
            'phone' => '9876543210',
            'password' => 'secret123',
            'library_name' => 'Test Library',
            'total_seats' => 3,
        ])->assertOk()->json('registration_token');

        $user = User::where('phone', '9876543210')->first();
        $this->assertCount(3, $user->libraries()->first()->seats);

        $this->postJson('/api/verify-otp', [
            'phone' => '9876543210',
            'otp' => (string) $loggedOtp,
            'type' => 'register',
            'registration_token' => $registrationToken,
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

    public function test_can_update_own_name(): void
    {
        $user = User::factory()->create(['name' => 'Asha Owner', 'phone' => '9876543210']);

        $this->actingAs($user)->postJson('/api/me/update', [
            'name' => 'Asha K. Owner',
            'phone' => '9876543210',
        ])->assertOk()->assertJsonPath('user.name', 'Asha K. Owner');

        $this->assertSame('Asha K. Owner', $user->fresh()->name);
    }

    public function test_profile_update_cannot_change_the_phone_number(): void
    {
        $user = User::factory()->create(['phone' => '9876543210']);

        $this->actingAs($user)->postJson('/api/me/update', [
            'name' => $user->name,
            'phone' => '9123456780',
        ])->assertStatus(422)->assertJsonValidationErrors('phone');

        $this->assertSame('9876543210', $user->fresh()->phone);
    }

    public function test_changing_the_number_needs_the_password_and_a_code_from_the_new_number(): void
    {
        $user = User::factory()->create(['phone' => '9876543210', 'password' => Hash::make('secret123')]);

        $this->actingAs($user)->postJson('/api/me/phone/request', ['phone' => '9123456780', 'current_password' => 'wrong'])
            ->assertStatus(422)->assertJsonValidationErrors('current_password');

        $sentTo = null;
        $code = null;
        Log::shouldReceive('info')->once()->withArgs(function ($message, $context) use (&$sentTo, &$code) {
            $sentTo = $context['to'];
            $code = (string) $context['otp'];

            return true;
        });
        $this->actingAs($user)->postJson('/api/me/phone/request', ['phone' => '9123456780', 'current_password' => 'secret123'])->assertOk();
        $this->assertSame('+919123456780', $sentTo);

        // The code only confirms the number it was sent to.
        $this->actingAs($user)->postJson('/api/me/phone/verify', ['phone' => '9000000009', 'otp' => $code])->assertStatus(400);
        $this->actingAs($user)->postJson('/api/me/phone/verify', ['phone' => '9123456780', 'otp' => '000000'])->assertStatus(400);
        $this->assertSame('9876543210', $user->fresh()->phone);

        $this->actingAs($user)->postJson('/api/me/phone/verify', ['phone' => '9123456780', 'otp' => $code])
            ->assertOk()->assertJsonPath('user.phone', '9123456780');
    }

    public function test_cannot_change_to_a_number_another_account_uses(): void
    {
        User::factory()->create(['phone' => '9876500001']);
        $user = User::factory()->create(['phone' => '9876543210', 'password' => Hash::make('secret123')]);

        $this->actingAs($user)->postJson('/api/me/phone/request', ['phone' => '9876500001', 'current_password' => 'secret123'])
            ->assertStatus(422)->assertJsonValidationErrors('phone');
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
        $user = User::factory()->phoneUnverified()->create(['phone' => '9876543210', 'registration_token_hash' => hash('sha256', 'device-token')]);
        OtpVerification::create([
            'user_id' => $user->id,
            'otp' => Hash::make('123456'),
            'type' => 'register',
            'expires_at' => now()->addMinutes(5),
        ]);

        // Without this device's sign-up token the right code still doesn't verify.
        $this->postJson('/api/verify-otp', ['phone' => '9876543210', 'otp' => '123456', 'type' => 'register'])
            ->assertForbidden()->assertJsonPath('restart_registration', true);

        $this->postJson('/api/verify-otp', ['phone' => '9876543210', 'otp' => '123456', 'type' => 'register', 'registration_token' => 'device-token'])
            ->assertOk()
            ->assertJsonStructure(['token', 'user']);

        $this->assertNotNull($user->fresh()->phone_verified_at);
        $this->assertNull($user->fresh()->registration_token_hash);
    }

    public function test_someone_re_registering_a_pending_number_cannot_take_it_over(): void
    {
        $codes = [];
        Log::shouldReceive('info')->withArgs(function ($message, $context) use (&$codes) {
            $codes[] = (string) $context['otp'];

            return true;
        });

        // The real owner starts signing up...
        $ownerToken = $this->postJson('/api/register', $this->registerPayload(['password' => 'owner-pass']))->json('registration_token');

        // ...and an attacker registers the same number with their own password.
        $this->travel(31)->seconds();
        $this->postJson('/api/register', $this->registerPayload(['password' => 'attacker-pass']))->assertOk();

        // The owner types the newest code they received: it no longer verifies on their device.
        $this->postJson('/api/verify-otp', ['phone' => '9876543210', 'otp' => end($codes), 'type' => 'register', 'registration_token' => $ownerToken])
            ->assertForbidden();
        $this->assertFalse(User::where('phone', '9876543210')->first()->hasVerifiedPhone());

        // So the attacker's password never becomes a working sign-in.
        $this->postJson('/api/login', ['phone' => '9876543210', 'password' => 'attacker-pass'])->assertForbidden();
    }

    public function test_unverified_number_can_register_again_and_gets_a_new_code(): void
    {
        $this->postJson('/api/register', $this->registerPayload())->assertOk();

        // Not straight away: that would let anyone text the number over and over.
        $this->postJson('/api/register', $this->registerPayload())->assertStatus(429);
        $this->travel(31)->seconds();

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
        $this->assertNotNull($user->fresh()->registration_token_hash);

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
        $user = User::factory()->phoneUnverified()->create(['phone' => '9876543210', 'registration_token_hash' => hash('sha256', 'device-token')]);
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

        $this->postJson('/api/verify-otp', ['phone' => '9876543210', 'otp' => '111111', 'type' => 'register', 'registration_token' => 'device-token'])
            ->assertStatus(400);
        $this->postJson('/api/verify-otp', ['phone' => '9876543210', 'otp' => '222222', 'type' => 'register', 'registration_token' => 'device-token'])
            ->assertOk();
    }

    public function test_resend_otp_is_refused_for_a_verified_number(): void
    {
        User::factory()->create(['phone' => '9876543210']);

        $this->postJson('/api/resend-otp', ['phone' => '9876543210', 'type' => 'register'])
            ->assertStatus(422);
    }

    public function test_forgot_password_sends_a_code_and_respects_the_cooldown(): void
    {
        $user = User::factory()->create(['phone' => '9876543210']);

        $this->postJson('/api/forgot-password', ['phone' => '9876543210'])
            ->assertOk()
            ->assertJsonPath('retry_after', 30);

        $this->postJson('/api/forgot-password', ['phone' => '9876543210'])
            ->assertStatus(429)
            ->assertJsonStructure(['retry_after']);

        $this->assertSame(1, OtpVerification::where('user_id', $user->id)->where('type', 'forgot_password')->count());
    }

    public function test_forgot_password_for_unknown_number_is_404(): void
    {
        $this->postJson('/api/forgot-password', ['phone' => '9876543210'])->assertNotFound();
    }

    public function test_reset_password_with_valid_code_changes_password_and_revokes_tokens(): void
    {
        $user = User::factory()->create(['phone' => '9876543210', 'password' => Hash::make('oldpass1')]);
        $user->createToken('auth_token');
        OtpVerification::create([
            'user_id' => $user->id,
            'otp' => Hash::make('123456'),
            'type' => 'forgot_password',
            'expires_at' => now()->addMinutes(5),
        ]);

        $payload = [
            'phone' => '9876543210',
            'otp' => '123456',
            'password' => 'newpass1',
            'password_confirmation' => 'newpass1',
        ];

        $this->postJson('/api/reset-password', $payload)->assertOk();
        // The code is single-use.
        $this->postJson('/api/reset-password', $payload)->assertStatus(400);

        $this->assertTrue(Hash::check('newpass1', $user->fresh()->password));
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_reset_password_rejects_a_wrong_or_other_type_code(): void
    {
        $user = User::factory()->create(['phone' => '9876543210', 'password' => Hash::make('oldpass1')]);
        OtpVerification::create([
            'user_id' => $user->id,
            'otp' => Hash::make('123456'),
            'type' => 'register',
            'expires_at' => now()->addMinutes(5),
        ]);

        $this->postJson('/api/reset-password', [
            'phone' => '9876543210',
            'otp' => '123456',
            'password' => 'newpass1',
            'password_confirmation' => 'newpass1',
        ])->assertStatus(400);

        $this->assertTrue(Hash::check('oldpass1', $user->fresh()->password));
    }

    public function test_change_password_requires_the_current_password(): void
    {
        $user = User::factory()->create(['password' => Hash::make('oldpass1')]);

        $this->actingAs($user)->postJson('/api/me/password', [
            'current_password' => 'wrongpass',
            'password' => 'newpass1',
            'password_confirmation' => 'newpass1',
        ])->assertStatus(422)->assertJsonValidationErrors('current_password');

        $this->assertTrue(Hash::check('oldpass1', $user->fresh()->password));
    }

    public function test_change_password_keeps_this_device_and_signs_out_others(): void
    {
        $user = User::factory()->create(['password' => Hash::make('oldpass1')]);
        $user->createToken('other_device');
        $token = $user->createToken('this_device')->plainTextToken;

        $this->withToken($token)->postJson('/api/me/password', [
            'current_password' => 'oldpass1',
            'password' => 'newpass1',
            'password_confirmation' => 'newpass1',
        ])->assertOk();

        $this->assertTrue(Hash::check('newpass1', $user->fresh()->password));
        $this->assertSame(['this_device'], $user->tokens()->pluck('name')->all());
    }

    public function test_change_password_requires_matching_confirmation(): void
    {
        $user = User::factory()->create(['password' => Hash::make('oldpass1')]);

        $this->actingAs($user)->postJson('/api/me/password', [
            'current_password' => 'oldpass1',
            'password' => 'newpass1',
            'password_confirmation' => 'different',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }
}
