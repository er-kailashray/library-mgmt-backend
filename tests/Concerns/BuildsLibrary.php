<?php

namespace Tests\Concerns;

use App\Models\Batch;
use App\Models\Library;
use App\Models\Member;
use App\Models\Payment;
use App\Models\Seat;
use App\Models\SeatAllocation;
use App\Models\User;

trait BuildsLibrary
{
    protected User $owner;

    protected Library $library;

    protected Batch $batch;

    protected function createLibrary(int $seats = 10, float $monthlyFee = 1000): void
    {
        $this->owner = User::factory()->create(['name' => 'Asha Owner']);
        $this->library = Library::create([
            'user_id' => $this->owner->id,
            'library_name' => 'Gyan Library',
            'total_seats' => $seats,
        ]);

        for ($i = 1; $i <= $seats; $i++) {
            Seat::create(['library_id' => $this->library->id, 'seat_number' => $i]);
        }

        $this->batch = Batch::create([
            'library_id' => $this->library->id,
            'name' => 'Morning',
            'start_time' => '08:00',
            'end_time' => '12:00',
            'monthly_fee' => $monthlyFee,
        ]);
    }

    protected function admit(string $name, int $seatNumber, string $renewalDate, ?Batch $batch = null): Member
    {
        $member = Member::create([
            'library_id' => $this->library->id,
            'name' => $name,
            'phone' => '98765'.str_pad((string) $seatNumber, 5, '0', STR_PAD_LEFT),
            'status' => 'active',
        ]);

        SeatAllocation::create([
            'library_id' => $this->library->id,
            'member_id' => $member->id,
            'batch_id' => ($batch ?? $this->batch)->id,
            'seat_id' => Seat::where('library_id', $this->library->id)->where('seat_number', $seatNumber)->value('id'),
            'start_date' => now()->subMonth()->toDateString(),
            'renewal_date' => $renewalDate,
            'status' => 'active',
        ]);

        return $member;
    }

    protected function recordPayment(Member $member, float $amount, string $date, string $status = 'paid'): Payment
    {
        return Payment::create([
            'library_id' => $this->library->id,
            'member_id' => $member->id,
            'amount' => $amount,
            'status' => $status,
            'payment_date' => $date,
            'payment_mode' => 'upi',
        ]);
    }
}
