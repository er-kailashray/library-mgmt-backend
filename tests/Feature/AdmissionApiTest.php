<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Models\Batch;
use App\Models\Member;
use App\Models\Payment;
use App\Models\Seat;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsLibrary;
use Tests\TestCase;

class AdmissionApiTest extends TestCase
{
    use BuildsLibrary, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createLibrary(seats: 3);
    }

    private function seatId(int $seatNumber): int
    {
        return Seat::where('library_id', $this->library->id)->where('seat_number', $seatNumber)->value('id');
    }

    public function test_creates_new_member_with_allocation(): void
    {
        $this->actingAs($this->owner)->postJson('/api/admission', [
            'name' => 'Rahul Sharma',
            'phone' => '9876543210',
            'allocations' => [
                ['batch_id' => $this->batch->id, 'seat_id' => $this->seatId(1)],
            ],
        ])->assertOk()->assertJsonPath('status', true);

        $this->assertSame(1, Member::where('library_id', $this->library->id)->count());
    }

    public function test_joining_on_the_31st_sets_renewal_date_to_month_end_not_overflowing(): void
    {
        $this->travelTo(Carbon::create(2026, 1, 31));

        $this->actingAs($this->owner)->postJson('/api/admission', [
            'name' => 'Rahul Sharma',
            'phone' => '9876543210',
            'allocations' => [
                ['batch_id' => $this->batch->id, 'seat_id' => $this->seatId(1)],
            ],
            'payment' => ['amount' => 1000, 'payment_mode' => 'cash'],
        ])->assertOk();

        $member = Member::where('phone', '9876543210')->first();
        // 2026 is not a leap year, so Feb has 28 days - must not overflow into March.
        $this->assertStringStartsWith('2026-02-28', $member->allocations()->first()->renewal_date);
    }

    public function test_creates_member_with_gender(): void
    {
        $this->actingAs($this->owner)->postJson('/api/admission', [
            'name' => 'Priya Verma',
            'phone' => '9876543211',
            'gender' => 'female',
            'allocations' => [
                ['batch_id' => $this->batch->id, 'seat_id' => $this->seatId(1)],
            ],
        ])->assertOk();

        $this->assertSame('female', Member::where('phone', '9876543211')->first()->gender);
    }

    public function test_rejects_invalid_gender(): void
    {
        $this->actingAs($this->owner)->postJson('/api/admission', [
            'name' => 'Rahul Sharma',
            'phone' => '9876543210',
            'gender' => 'not-a-gender',
            'allocations' => [
                ['batch_id' => $this->batch->id, 'seat_id' => $this->seatId(1)],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('gender');
    }

    /**
     * Readmitting the same phone number (e.g. adding an evening batch to an
     * existing morning-batch student) must not create a second Member row -
     * the admission controller used to always `Member::create(...)`.
     */
    public function test_reuses_existing_member_by_phone_instead_of_duplicating(): void
    {
        $evening = Batch::create([
            'library_id' => $this->library->id,
            'name' => 'Evening',
            'start_time' => '17:00',
            'end_time' => '21:00',
            'monthly_fee' => 1000,
        ]);

        $this->actingAs($this->owner)->postJson('/api/admission', [
            'name' => 'Rahul Sharma',
            'phone' => '9876543210',
            'allocations' => [['batch_id' => $this->batch->id, 'seat_id' => $this->seatId(1)]],
        ])->assertOk();

        $this->actingAs($this->owner)->postJson('/api/admission', [
            'name' => 'Rahul Sharma',
            'phone' => '9876543210',
            'allocations' => [['batch_id' => $evening->id, 'seat_id' => $this->seatId(2)]],
        ])->assertOk();

        $this->assertSame(1, Member::where('library_id', $this->library->id)->where('phone', '9876543210')->count());

        $member = Member::where('phone', '9876543210')->first();
        $this->assertSame(2, $member->allocations()->where('status', 'active')->count());
    }

    public function test_rejects_duplicate_active_allocation_in_same_batch_for_existing_member(): void
    {
        $this->actingAs($this->owner)->postJson('/api/admission', [
            'name' => 'Rahul Sharma',
            'phone' => '9876543210',
            'allocations' => [['batch_id' => $this->batch->id, 'seat_id' => $this->seatId(1)]],
        ])->assertOk();

        $this->actingAs($this->owner)->postJson('/api/admission', [
            'name' => 'Rahul Sharma',
            'phone' => '9876543210',
            'allocations' => [['batch_id' => $this->batch->id, 'seat_id' => $this->seatId(2)]],
        ])->assertStatus(422)->assertJsonPath('status', false);
    }

    public function test_rejects_seat_already_occupied_in_batch(): void
    {
        $this->actingAs($this->owner)->postJson('/api/admission', [
            'name' => 'First Student',
            'phone' => '9876543210',
            'allocations' => [['batch_id' => $this->batch->id, 'seat_id' => $this->seatId(1)]],
        ])->assertOk();

        $this->actingAs($this->owner)->postJson('/api/admission', [
            'name' => 'Second Student',
            'phone' => '9876500000',
            'allocations' => [['batch_id' => $this->batch->id, 'seat_id' => $this->seatId(1)]],
        ])->assertStatus(422)->assertJsonPath('status', false);
    }

    public function test_rejoining_member_is_marked_active_again(): void
    {
        $member = $this->admit('Rahul Sharma', 1, '2026-11-01');
        $member->update(['status' => 'inactive']);
        $member->allocations()->update(['status' => 'ended']);

        $this->actingAs($this->owner)->postJson('/api/admission', [
            'name' => 'Rahul Sharma',
            'phone' => $member->phone,
            'allocations' => [['batch_id' => $this->batch->id, 'seat_id' => $this->seatId(1)]],
        ])->assertOk();

        $this->assertSame('active', $member->fresh()->status);
    }

    public function test_admission_without_id_proof_still_works(): void
    {
        $this->actingAs($this->owner)->postJson('/api/admission', [
            'name' => 'Rahul Sharma',
            'phone' => '9876543210',
            'allocations' => [['batch_id' => $this->batch->id, 'seat_id' => $this->seatId(1)]],
        ])->assertOk();

        $member = Member::where('phone', '9876543210')->first();
        $this->assertNull($member->id_proof_type);
        $this->assertNull($member->id_proof_front_path);
        $this->assertNull($member->id_proof_back_path);
    }

    private function admitWith(?array $payment): Member
    {
        $this->travelTo(Carbon::create(2026, 10, 22));

        $this->actingAs($this->owner)->postJson('/api/admission', array_filter([
            'name' => 'Rahul Sharma',
            'phone' => '9876543210',
            'allocations' => [
                ['batch_id' => $this->batch->id, 'seat_id' => $this->seatId(1)],
            ],
            'payment' => $payment,
        ]))->assertOk();

        return Member::where('phone', '9876543210')->first();
    }

    public function test_full_payment_at_admission_is_recorded_and_covers_first_month(): void
    {
        $member = $this->admitWith(['amount' => 1000, 'payment_mode' => 'upi']);

        $payment = Payment::where('member_id', $member->id)->sole();
        $this->assertSame(1000.0, $payment->amount);
        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertSame('upi', $payment->payment_mode);
        $this->assertSame('2026-10-22', $payment->payment_date->toDateString());
        // Paid through one month - not extended a second time by the payment.
        $this->assertStringStartsWith('2026-11-22', $member->allocations()->first()->renewal_date);
    }

    public function test_partial_payment_at_admission_leaves_member_due_today(): void
    {
        $member = $this->admitWith(['amount' => 400, 'payment_mode' => 'cash']);

        $this->assertSame(PaymentStatus::Partial, Payment::where('member_id', $member->id)->sole()->status);
        $this->assertStringStartsWith('2026-10-22', $member->allocations()->first()->renewal_date);
    }

    public function test_pay_later_admission_records_no_payment_and_is_due_today(): void
    {
        $member = $this->admitWith(null);

        $this->assertSame(0, Payment::where('member_id', $member->id)->count());
        $this->assertStringStartsWith('2026-10-22', $member->allocations()->first()->renewal_date);
    }

    public function test_rejects_invalid_admission_payment(): void
    {
        $this->actingAs($this->owner)->postJson('/api/admission', [
            'name' => 'Rahul Sharma',
            'phone' => '9876543210',
            'allocations' => [
                ['batch_id' => $this->batch->id, 'seat_id' => $this->seatId(1)],
            ],
            'payment' => ['amount' => 0, 'payment_mode' => 'cheque'],
        ])->assertStatus(422)->assertJsonValidationErrors(['payment.amount', 'payment.payment_mode']);

        $this->assertSame(0, Member::count());
    }

    public function test_partial_admission_payment_is_deducted_from_what_is_owed(): void
    {
        $this->admitWith(['amount' => 400, 'payment_mode' => 'cash']);

        // Renewal is today, so the member shows under upcoming renewals for the remaining 600.
        $this->actingAs($this->owner)->getJson('/api/renewals/upcoming')
            ->assertJsonCount(1, 'data.renewals')
            ->assertJsonPath('data.renewals.0.amount', 600);

        // Once it lapses it's overdue - still for 600, not the full fee.
        $this->travelTo(Carbon::create(2026, 10, 23));
        $this->actingAs($this->owner)->getJson('/api/payments')
            ->assertJsonPath('data.0.status', 'PENDING')
            ->assertJsonPath('data.0.amount', 600);
    }
}
