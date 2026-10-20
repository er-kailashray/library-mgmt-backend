<?php

namespace Tests\Feature;

use App\Models\OtpVerification;
use App\Models\User;
use App\Notifications\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
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
}
