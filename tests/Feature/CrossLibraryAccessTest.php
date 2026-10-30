<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Batch;
use App\Models\Library;
use App\Models\LibraryPhoto;
use App\Models\Member;
use App\Models\OwnerNotification;
use App\Models\Payment;
use App\Models\PushToken;
use App\Models\Seat;
use App\Models\SeatAllocation;
use App\Models\SeatBlock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsLibrary;
use Tests\TestCase;

/**
 * IDOR guard: an owner who sends another library's ids - members, batches, seats, blocks, photos,
 * notifications, push tokens - must never read or change that library's data. Every attempt is
 * refused and the victim's rows are left exactly as they were.
 */
class CrossLibraryAccessTest extends TestCase
{
    use BuildsLibrary, RefreshDatabase;

    private User $victim;

    private Library $victimLibrary;

    private Batch $victimBatch;

    private Member $victimMember;

    private int $victimSeatId;

    private int $victimFreeSeatId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-10 10:00:00');
        Storage::fake('local');
        Storage::fake('public');

        // The victim's library, with a member, a blocked seat, a photo, an alert and a push token.
        $this->createLibrary(seats: 5);
        $this->victim = $this->owner;
        $this->victimLibrary = $this->library;
        $this->victimBatch = $this->batch;
        $this->victimMember = $this->admit('Rahul Sharma', 1, '2026-11-25');
        $this->victimSeatId = Seat::where('library_id', $this->library->id)->where('seat_number', 1)->value('id');
        $this->victimFreeSeatId = Seat::where('library_id', $this->library->id)->where('seat_number', 3)->value('id');
        SeatBlock::create(['library_id' => $this->library->id, 'seat_id' => Seat::where('library_id', $this->library->id)->where('seat_number', 2)->value('id'), 'starts_on' => '2026-11-10']);
        LibraryPhoto::create(['library_id' => $this->library->id, 'path' => 'library-photos/x.jpg']);
        OwnerNotification::create(['user_id' => $this->victim->id, 'library_id' => $this->library->id, 'type' => 'test', 'title' => 'Hi', 'body' => 'Body']);
        PushToken::create(['user_id' => $this->victim->id, 'token' => 'ExponentPushToken[victim]', 'platform' => 'android']);
        Storage::disk('local')->put('id-proofs/victim.jpg', 'secret');
        $this->victimMember->update(['id_proof_type' => 'aadhaar', 'id_proof_front_path' => 'id-proofs/victim.jpg']);

        // The attacker: a different owner with their own library.
        $this->createLibrary(seats: 5);
    }

    /** @return array<string, mixed> everything of the victim's that an attack could change */
    private function victimSnapshot(): array
    {
        return [
            'member' => Member::find($this->victimMember->id)->only(['name', 'phone', 'status', 'address', 'id_proof_type', 'id_proof_front_path', 'outstanding_balance']),
            'allocations' => SeatAllocation::where('library_id', $this->victimLibrary->id)->orderBy('id')->get(['seat_id', 'batch_id', 'status', 'renewal_date', 'ended_on'])->toArray(),
            'batch' => Batch::withTrashed()->find($this->victimBatch->id)->only(['name', 'monthly_fee', 'start_time', 'end_time', 'deleted_at']),
            'payments' => Payment::where('library_id', $this->victimLibrary->id)->count(),
            'attendance' => Attendance::where('library_id', $this->victimLibrary->id)->count(),
            'blocks' => SeatBlock::where('library_id', $this->victimLibrary->id)->orderBy('id')->get(['seat_id', 'batch_id', 'starts_on', 'ends_on'])->toArray(),
            'photos' => LibraryPhoto::where('library_id', $this->victimLibrary->id)->count(),
            'unread' => OwnerNotification::where('user_id', $this->victim->id)->whereNull('read_at')->count(),
            'push_tokens' => PushToken::where('user_id', $this->victim->id)->count(),
            'id_proof_file' => Storage::disk('local')->exists('id-proofs/victim.jpg'),
        ];
    }

    public function test_another_owner_cannot_read_or_change_anything_by_id(): void
    {
        $before = $this->victimSnapshot();
        $m = $this->victimMember->id;
        $b = $this->victimBatch->id;
        $blockId = SeatBlock::where('library_id', $this->victimLibrary->id)->value('id');
        $photoId = LibraryPhoto::where('library_id', $this->victimLibrary->id)->value('id');
        $notificationId = OwnerNotification::where('user_id', $this->victim->id)->value('id');

        $attempts = [
            ['GET', "/api/members/{$m}", []],
            ['GET', "/api/members/{$m}/id-proof/front", []],
            ['POST', "/api/members/{$m}/update", ['name' => 'Hacked', 'phone' => '9000000001']],
            ['POST', "/api/members/{$m}/deactivate", []],
            ['POST', "/api/members/{$m}/restart", ['start_date' => '2026-12-01']],
            ['POST', "/api/members/{$m}/change-seat", ['batch_id' => $b, 'seat_id' => $this->victimFreeSeatId]],
            ['POST', "/api/members/{$m}/remind", []],
            ['DELETE', "/api/members/{$m}/id-proof", []],
            ['DELETE', "/api/members/{$m}/id-proof/front", []],
            ['POST', "/api/batches/{$b}/update", ['name' => 'Hacked', 'start_time' => '01:00', 'end_time' => '02:00', 'monthly_fee' => 1]],
            ['DELETE', "/api/batches/{$b}", []],
            ['POST', '/api/payments', ['member_id' => $m, 'amount' => 1000]],
            ['POST', '/api/admission', ['name' => 'X', 'phone' => '9000000002', 'allocations' => [['batch_id' => $b, 'seat_id' => $this->victimFreeSeatId]]]],
            ['POST', '/api/attendance', ['member_id' => $m, 'batch_id' => $b, 'status' => 'present']],
            ['POST', '/api/attendance/clear', ['member_id' => $m, 'batch_id' => $b]],
            ['POST', "/api/seats/{$this->victimFreeSeatId}/block", []],
            ['DELETE', "/api/seat-blocks/{$blockId}", []],
            ['DELETE', "/api/library/photos/{$photoId}", []],
        ];

        foreach ($attempts as [$method, $uri, $data]) {
            $status = $this->actingAs($this->owner)->json($method, $uri, $data)->status();
            $this->assertContains($status, [403, 404, 422], "{$method} {$uri} answered {$status}");
        }

        // Ids of another owner's own rows are simply not theirs to touch (these answer 200 with no effect).
        $this->actingAs($this->owner)->postJson('/api/notifications/read', ['ids' => [$notificationId]])->assertOk();
        $this->actingAs($this->owner)->postJson('/api/me/push-tokens/remove', ['token' => 'ExponentPushToken[victim]'])->assertOk();

        // The victim's seat map doesn't leak into theirs, even when asked for by batch id.
        $matrix = $this->actingAs($this->owner)->getJson('/api/seat-matrix?batch_ids[]='.$b)->assertOk()->json('data');
        $this->assertSame([], $matrix['batches']);
        $this->assertNotContains($this->victimSeatId, array_column($matrix['seats'], 'seat_id'));

        // Nor do their members, payments or renewals.
        $this->assertNotContains($m, array_column($this->actingAs($this->owner)->getJson('/api/members')->json('data'), 'id'));
        $this->assertSame([], $this->actingAs($this->owner)->getJson('/api/renewals/upcoming')->json('data.renewals'));

        $this->assertSame($before, $this->victimSnapshot());
    }

    public function test_every_api_route_needs_a_login_except_the_public_ones(): void
    {
        $public = ['api/register', 'api/verify-otp', 'api/resend-otp', 'api/login', 'api/forgot-password', 'api/reset-password', 'api/webhooks/razorpay'];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (! str_starts_with($uri, 'api/') || in_array($uri, $public, true)) {
                continue;
            }
            $method = collect($route->methods())->first(fn ($m) => $m !== 'HEAD');
            $path = '/'.preg_replace('/\{[^}]+\??\}/', '1', $uri);

            $this->json($method, $path)->assertUnauthorized();
        }
    }

    public function test_login_refuses_non_text_credentials_and_slows_down_guessing(): void
    {
        $this->postJson('/api/login', ['phone' => ['$ne' => ''], 'password' => ['x']])->assertUnprocessable();

        // Ten tries a minute per IP, the refused one above included.
        for ($i = 0; $i < 9; $i++) {
            $this->postJson('/api/login', ['phone' => $this->owner->phone, 'password' => "wrong-{$i}"])->assertUnauthorized();
        }
        $this->postJson('/api/login', ['phone' => $this->owner->phone, 'password' => 'wrong-again'])->assertTooManyRequests();
    }

    public function test_sign_in_tokens_expire(): void
    {
        $token = $this->owner->createToken('auth_token')->plainTextToken;
        $this->withToken($token)->getJson('/api/dashboard')->assertOk();

        $this->travel(91)->days();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/dashboard')->assertUnauthorized();
    }

    public function test_a_forged_razorpay_webhook_is_rejected(): void
    {
        config(['services.razorpay.webhook_secret' => 'real-secret']);

        $body = json_encode(['event' => 'payment.captured', 'payload' => ['payment' => ['entity' => ['id' => 'pay_x', 'order_id' => 'order_x']]]]);
        $this->call('POST', '/api/webhooks/razorpay', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_RAZORPAY_SIGNATURE' => hash_hmac('sha256', $body, 'guessed')], $body)
            ->assertStatus(400);
    }
}
