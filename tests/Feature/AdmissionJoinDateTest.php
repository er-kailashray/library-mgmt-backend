<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\Payment;
use App\Models\Seat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsLibrary;
use Tests\TestCase;

/**
 * Admission with a joining date: today by default, or earlier when entering a member who was
 * already coming before the library started using the app. Monthly fee is 1000.
 */
class AdmissionJoinDateTest extends TestCase
{
    use BuildsLibrary, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-10 10:00:00');
        $this->createLibrary(seats: 5);
    }

    private function admitWith(array $extra)
    {
        return $this->actingAs($this->owner)->postJson('/api/admission', [
            'name' => 'Priya Verma',
            'phone' => '9876543211',
            'allocations' => [['batch_id' => $this->batch->id, 'seat_id' => Seat::where('seat_number', 2)->value('id')]],
        ] + $extra);
    }

    private function member(): Member
    {
        return Member::where('phone', '9876543211')->firstOrFail();
    }

    private function renewal(): string
    {
        return substr($this->member()->allocations()->first()->renewal_date, 0, 10);
    }

    public function test_joining_date_defaults_to_today(): void
    {
        $this->admitWith(['payment' => ['amount' => 1000]])->assertOk();

        $this->assertSame('2026-11-10', substr($this->member()->allocations()->first()->start_date, 0, 10));
        $this->assertSame('2026-12-10', $this->renewal());
    }

    public function test_paying_two_months_at_admission_covers_both(): void
    {
        $this->admitWith(['payment' => ['amount' => 2000]])->assertOk();

        $this->assertSame('2027-01-10', $this->renewal());
        $this->assertSame('2027-01-09', Payment::firstOrFail()->period_end->toDateString());
    }

    public function test_existing_member_already_paid_owes_nothing_until_paid_time_ends(): void
    {
        // Joined 1 Aug, paid up to 30 Nov before the library used the app.
        $this->admitWith(['join_date' => '2026-08-01', 'paid_until' => '2026-11-30'])->assertOk();

        $this->assertSame('2026-08-01', substr($this->member()->allocations()->first()->start_date, 0, 10));
        $this->assertSame('2026-08-01', $this->member()->join_date);
        $this->assertSame('2026-12-01', $this->renewal());
        $this->assertSame(0, Payment::count()); // nothing counted as received today
        $this->actingAs($this->owner)->getJson("/api/members/{$this->member()->id}")->assertJsonPath('data.payment_status', 'Paid');
    }

    public function test_payment_taken_today_continues_after_paid_until(): void
    {
        // Paid till 31 Oct; pays November today.
        $this->admitWith(['join_date' => '2026-08-01', 'paid_until' => '2026-10-31', 'payment' => ['amount' => 1000]])->assertOk();

        $payment = Payment::firstOrFail();
        $this->assertSame('2026-11-01', $payment->period_start->toDateString());
        $this->assertSame('2026-11-30', $payment->period_end->toDateString());
        $this->assertSame('2026-11-10', $payment->payment_date->toDateString());
        $this->assertSame('2026-12-01', $this->renewal());
    }

    public function test_backdated_member_with_nothing_paid_owes_from_joining(): void
    {
        // Joined 1 Oct, never paid: Oct and Nov are due.
        $this->admitWith(['join_date' => '2026-10-01'])->assertOk();

        $this->assertSame('2026-10-01', $this->renewal());
        $this->actingAs($this->owner)->getJson("/api/members/{$this->member()->id}")
            ->assertJsonPath('data.payment_status', 'Due')
            ->assertJsonPath('data.due_amount', 2000);
    }

    public function test_paid_until_cannot_be_before_joining(): void
    {
        $this->admitWith(['join_date' => '2026-08-01', 'paid_until' => '2026-07-15'])->assertStatus(422)->assertJsonValidationErrors('paid_until');
        $this->assertSame(0, Member::count());
    }

    public function test_joining_date_cannot_be_too_far_ahead(): void
    {
        $this->admitWith(['join_date' => '2027-01-20'])->assertStatus(422)->assertJsonValidationErrors('join_date');
    }
}
