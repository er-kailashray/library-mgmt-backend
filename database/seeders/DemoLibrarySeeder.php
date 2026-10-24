<?php

namespace Database\Seeders;

use App\Enums\PaymentStatus;
use App\Models\Attendance;
use App\Models\Batch;
use App\Models\Library;
use App\Models\Member;
use App\Models\Payment;
use App\Models\Seat;
use App\Models\SeatAllocation;
use App\Models\User;
use App\Services\Payments\PaymentService;
use App\Services\Subscriptions\SubscriptionService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A full demo library for testing every screen: ~45 members joined on different dates over the
 * last six months, in every state the app handles - paid up, renewing soon, due today, overdue
 * (one and several months), partial payments, paid ahead, two batches, brand new, left (with dues,
 * with a refund), back after a break, entered as an existing member - plus months of payments and
 * the last week of attendance.
 *
 *   php artisan db:seed --class=DemoLibrarySeeder
 *
 * Sign in to the app with 9999900001 / demo1234. Running it again replaces the demo library with a
 * fresh one, with dates relative to today.
 */
class DemoLibrarySeeder extends Seeder
{
    public const OWNER_PHONE = '9999900001';

    public const OWNER_PASSWORD = 'demo1234';

    private const SEATS = 60;

    private const MODES = ['cash', 'cash', 'upi', 'upi', 'upi', 'bank_transfer'];

    private const NAMES = [
        ['Rahul Sharma', 'male'], ['Priya Verma', 'female'], ['Amit Kumar', 'male'], ['Neha Singh', 'female'], ['Arjun Patel', 'male'],
        ['Sneha Gupta', 'female'], ['Rohit Yadav', 'male'], ['Pooja Mishra', 'female'], ['Vikas Chauhan', 'male'], ['Anjali Tiwari', 'female'],
        ['Suresh Rao', 'male'], ['Kavita Joshi', 'female'], ['Manish Pandey', 'male'], ['Ritu Saxena', 'female'], ['Deepak Jain', 'male'],
        ['Shreya Agarwal', 'female'], ['Nikhil Dubey', 'male'], ['Komal Bansal', 'female'], ['Aakash Mehta', 'male'], ['Simran Kaur', 'female'],
        ['Gaurav Thakur', 'male'], ['Megha Kapoor', 'female'], ['Sandeep Rawat', 'male'], ['Nisha Pal', 'female'], ['Harsh Vardhan', 'male'],
        ['Tanvi Desai', 'female'], ['Rakesh Meena', 'male'], ['Divya Nair', 'female'], ['Ankit Soni', 'male'], ['Isha Malhotra', 'female'],
        ['Vivek Raj', 'male'], ['Payal Shukla', 'female'], ['Kunal Bhatt', 'male'], ['Swati Kulkarni', 'female'], ['Yash Rathore', 'male'],
        ['Aditi Rana', 'female'], ['Mohit Garg', 'male'], ['Riya Sen', 'female'], ['Sahil Khan', 'male'], ['Bhavna Reddy', 'female'],
        ['Pranav Iyer', 'male'], ['Muskan Arora', 'female'], ['Tarun Bisht', 'male'], ['Sonal Vyas', 'female'], ['Abhishek Dutta', 'male'],
    ];

    private const AREAS = ['Vijay Nagar', 'Palasia', 'Bhawarkua', 'Rajendra Nagar', 'Sudama Nagar', 'MG Road', 'Annapurna', 'Bengali Square'];

    private Carbon $today;

    private Library $library;

    /** @var array<string, Batch> */
    private array $batches = [];

    /** @var array<string, int> next free seat number per batch */
    private array $nextSeat = [];

    private int $nameIndex = 0;

    public function run(): void
    {
        mt_srand(2026); // same demo every run (relative to today)
        $this->today = now()->startOfDay();

        DB::transaction(function () {
            // Deleting the owner removes their library and everything in it (foreign keys cascade).
            User::where('phone', self::OWNER_PHONE)->get()->each->delete();

            $owner = User::create(['name' => 'Ramesh Gupta', 'phone' => self::OWNER_PHONE, 'password' => self::OWNER_PASSWORD, 'role' => 'owner']);
            $owner->forceFill(['phone_verified_at' => now()])->save();

            $this->library = Library::create([
                'user_id' => $owner->id,
                'library_name' => 'Gyan Study Library',
                'total_seats' => self::SEATS,
                'amenities' => ['ac', 'wifi', 'water', 'washroom', 'silent_zone', 'charging', 'newspaper', 'cctv', 'power_backup', 'locker'],
                'address' => '2nd Floor, Shree Complex, Vijay Nagar, Indore 452010',
                'latitude' => 22.7533,
                'longitude' => 75.8937,
            ]);
            $this->library->forceFill(['created_at' => $this->today->copy()->subMonths(6)])->save();

            for ($n = 1; $n <= self::SEATS; $n++) {
                Seat::create(['library_id' => $this->library->id, 'seat_number' => $n]);
            }

            foreach ([
                'morning' => ['Morning', '06:00', '10:00', 600],
                'midday' => ['Midday', '10:00', '14:00', 600],
                'afternoon' => ['Afternoon', '14:00', '18:00', 600],
                'evening' => ['Evening', '18:00', '22:00', 700],
                'night' => ['Night', '22:00', '02:00', 500],
            ] as $key => [$name, $start, $end, $fee]) {
                $this->batches[$key] = Batch::create(['library_id' => $this->library->id, 'name' => $name, 'start_time' => $start, 'end_time' => $end, 'monthly_fee' => $fee]);
                $this->nextSeat[$key] = 1;
            }

            app(SubscriptionService::class)->startTrial($this->library, $this->today->copy()->subDays(20));

            $this->seedMembers();
            $this->seedAttendance();
        });

        $this->command?->info('Demo library ready: '.Member::where('library_id', $this->library->id)->count().' members. Sign in with '.self::OWNER_PHONE.' / '.self::OWNER_PASSWORD.'.');
    }

    private function seedMembers(): void
    {
        $batchKeys = array_keys($this->batches);
        $pick = fn () => $batchKeys[mt_rand(0, count($batchKeys) - 1)];

        // Paid up, renewing in 5-25 days (joined 1-5 months ago, paid every month).
        for ($i = 0; $i < 16; $i++) {
            $this->paying([$pick()], monthsAgo: mt_rand(0, 4), daysAgo: mt_rand(5, 25), monthsBehind: 0);
        }
        // Paid up in two batches (same seat both times).
        $this->paying(['morning', 'afternoon'], monthsAgo: 2, daysAgo: 12, monthsBehind: 0);
        $this->paying(['midday', 'evening'], monthsAgo: 1, daysAgo: 8, monthsBehind: 0);
        // Renewing in the next 1-3 days (shows under Renewals).
        foreach ([27, 28, 29, 28] as $d) {
            $this->paying([$pick()], monthsAgo: mt_rand(0, 3), daysAgo: $d, monthsBehind: 0);
        }
        // Due today.
        $this->paying(['morning'], monthsAgo: 2, daysAgo: 0, monthsBehind: 0, dueToday: true);
        $this->paying(['evening'], monthsAgo: 1, daysAgo: 0, monthsBehind: 0, dueToday: true);
        // One month overdue (5-20 days late).
        foreach ([6, 11, 15, 19, 8] as $d) {
            $this->paying([$pick()], monthsAgo: mt_rand(1, 4), daysAgo: $d, monthsBehind: 1);
        }
        // Two and three months overdue.
        $this->paying(['afternoon'], monthsAgo: 4, daysAgo: 10, monthsBehind: 2);
        $this->paying(['night'], monthsAgo: 5, daysAgo: 4, monthsBehind: 3);
        // Overdue, with part of it paid.
        $this->paying(['midday'], monthsAgo: 2, daysAgo: 7, monthsBehind: 1, partial: 300);
        $this->paying(['evening'], monthsAgo: 3, daysAgo: 14, monthsBehind: 1, partial: 400);
        // Paid ahead (renewed early for 2-3 extra months).
        $this->paying(['morning'], monthsAgo: 1, daysAgo: 10, monthsBehind: -2);
        $this->paying(['afternoon'], monthsAgo: 0, daysAgo: 20, monthsBehind: -3);
        // Joined this week: paid, partly paid, and "pay later".
        $this->paying(['evening'], monthsAgo: 0, daysAgo: 1, monthsBehind: 0);
        $this->paying(['night'], monthsAgo: 0, daysAgo: 3, monthsBehind: 0, partial: 250, firstFullPayment: false);
        $this->paying(['midday'], monthsAgo: 0, daysAgo: 0, monthsBehind: 1);
        // An existing member entered with "paid till" (no payments before the app), paying since.
        $this->existingMember('morning');
        // Back after a break: restarted on a later date, the gap not charged.
        $this->backAfterBreak('afternoon');
        // Left the library: owing money, with a refund, and settled.
        $this->leftMember('evening', owes: 700);
        $this->leftMember('morning', refund: 450);
        $this->leftMember('night');
    }

    /**
     * A member who joined on a past date and paid month by month.
     *
     * $monthsBehind: 0 = paid up, 1+ = that many months unpaid, negative = paid that many months ahead.
     */
    private function paying(array $batchKeys, int $monthsAgo, int $daysAgo, int $monthsBehind, bool $dueToday = false, int $partial = 0, bool $firstFullPayment = true): void
    {
        // Joined so that their next renewal falls $daysAgo days before today, a month from now.
        $join = $this->today->copy()->subMonthsNoOverflow($monthsAgo)->subDays($daysAgo);
        if ($dueToday) {
            $join = $this->today->copy()->subMonthsNoOverflow($monthsAgo + 1);
        }
        $monthsSinceJoin = $dueToday ? $monthsAgo + 1 : $monthsAgo + 1;
        $monthsPaid = $firstFullPayment ? max(0, $monthsSinceJoin - $monthsBehind) : 0;

        [$member, $allocations] = $this->admit($join, $batchKeys);
        $fee = $this->feeOf($allocations);

        for ($i = 0; $i < $monthsPaid; $i++) {
            $start = $join->copy()->addMonthsNoOverflow($i);
            // Paid around when each month started (a few days late now and then), never in the future.
            $paidOn = $start->copy()->addDays(mt_rand(0, 3) === 0 ? mt_rand(1, 4) : 0);
            $this->payment($member, $allocations, $fee, $paidOn->min($this->today), $start, 1, PaymentStatus::Paid);
        }
        $renewal = $join->copy()->addMonthsNoOverflow($monthsPaid);

        if ($partial > 0) {
            $paidOn = $renewal->copy()->max($join)->min($this->today);
            $this->payment($member, $allocations, $partial, $paidOn, $renewal, 1, PaymentStatus::Partial);
        }

        $this->setRenewal($allocations, $renewal);
    }

    /** Joined five months ago and paid before the app existed ("paid till"); paid in the app since. */
    private function existingMember(string $batchKey): void
    {
        $join = $this->today->copy()->subMonthsNoOverflow(5)->subDays(3);
        [$member, $allocations] = $this->admit($join, [$batchKey]);
        $paidTill = $this->today->copy()->subMonthsNoOverflow(1)->subDays(3); // covered up to here before the app
        $this->payment($member, $allocations, $this->feeOf($allocations), $paidTill->copy()->addDay(), $paidTill->copy()->addDay(), 1, PaymentStatus::Paid);
        $this->setRenewal($allocations, $paidTill->copy()->addDay()->addMonthNoOverflow());
    }

    /** Paid two months, stopped coming, came back after a 12-day gap and restarted from that day. */
    private function backAfterBreak(string $batchKey): void
    {
        $join = $this->today->copy()->subMonthsNoOverflow(3)->subDays(10);
        [$member, $allocations] = $this->admit($join, [$batchKey]);
        $fee = $this->feeOf($allocations);
        for ($i = 0; $i < 2; $i++) {
            $start = $join->copy()->addMonthsNoOverflow($i);
            $this->payment($member, $allocations, $fee, $start, $start, 1, PaymentStatus::Paid);
        }
        $restart = $join->copy()->addMonthsNoOverflow(2)->addDays(12);
        $this->payment($member, $allocations, $fee, $restart->min($this->today), $restart, 1, PaymentStatus::Paid);
        $this->setRenewal($allocations, $restart->copy()->addMonthNoOverflow());
    }

    /** Joined, paid, then left: either owing money, with money given back, or settled. */
    private function leftMember(string $batchKey, float $owes = 0, float $refund = 0): void
    {
        $join = $this->today->copy()->subMonthsNoOverflow(3)->subDays(mt_rand(1, 20));
        [$member, $allocations] = $this->admit($join, [$batchKey]);
        $fee = $this->feeOf($allocations);
        $monthsPaid = $owes > 0 ? 1 : 2;
        for ($i = 0; $i < $monthsPaid; $i++) {
            $start = $join->copy()->addMonthsNoOverflow($i);
            $this->payment($member, $allocations, $fee, $start, $start, 1, PaymentStatus::Paid);
        }
        $paidThrough = $join->copy()->addMonthsNoOverflow($monthsPaid);
        $leftOn = $refund > 0 ? $paidThrough->copy()->subDays(20) : $paidThrough->copy()->addDays($owes > 0 ? 25 : 0);
        $leftOn = $leftOn->min($this->today);

        if ($refund > 0) {
            $this->payment($member, $allocations, -$refund, $leftOn, $leftOn, 0, PaymentStatus::Refunded, $paidThrough->copy()->subDay());
        }

        foreach ($allocations as $allocation) {
            $allocation->update(['renewal_date' => $paidThrough->toDateString(), 'status' => 'ended', 'ended_on' => $leftOn->toDateString()]);
        }
        $member->update(['status' => 'inactive', 'outstanding_balance' => $owes]);
    }

    /**
     * @param  list<string>  $batchKeys
     * @return array{0: Member, 1: Collection<int, SeatAllocation>}
     */
    private function admit(Carbon $join, array $batchKeys): array
    {
        [$name, $gender] = self::NAMES[$this->nameIndex % count(self::NAMES)];
        $index = $this->nameIndex++;

        $member = Member::create([
            'library_id' => $this->library->id,
            'name' => $name,
            'phone' => '98'.str_pad((string) (26100000 + $index * 137), 8, '0', STR_PAD_LEFT),
            'gender' => $gender,
            'address' => mt_rand(0, 2) === 0 ? null : mt_rand(10, 220).', '.self::AREAS[$index % count(self::AREAS)].', Indore',
            'join_date' => $join->toDateString(),
            'status' => 'active',
        ]);
        $member->forceFill(['created_at' => $join->copy()->setTime(mt_rand(8, 19), mt_rand(0, 59))])->save();

        // Two batches share one seat number (the seat is theirs in both).
        $seatNumber = max(array_map(fn ($key) => $this->nextSeat[$key], $batchKeys));
        $allocations = collect($batchKeys)->map(function (string $key) use ($member, $join, $seatNumber) {
            $this->nextSeat[$key] = $seatNumber + 1;

            return SeatAllocation::create([
                'library_id' => $this->library->id,
                'member_id' => $member->id,
                'batch_id' => $this->batches[$key]->id,
                'seat_id' => Seat::where('library_id', $this->library->id)->where('seat_number', $seatNumber)->value('id'),
                'start_date' => $join->toDateString(),
                'renewal_date' => $join->toDateString(),
                'status' => 'active',
            ])->load(['batch', 'seat']);
        });

        return [$member, $allocations];
    }

    private function payment(Member $member, Collection $allocations, float $amount, Carbon $paidOn, Carbon $from, int $months, PaymentStatus $status, ?Carbon $to = null): void
    {
        $payment = Payment::create([
            'library_id' => $this->library->id,
            'member_id' => $member->id,
            'amount' => $amount,
            'status' => $status,
            'payment_date' => $paidOn->toDateString(),
            'period_start' => $from->toDateString(),
            'period_end' => ($to ?? $from->copy()->addMonthsNoOverflow(max(1, $months))->subDay())->toDateString(),
            'seats' => PaymentService::seatSnapshot($allocations),
            'payment_mode' => self::MODES[mt_rand(0, count(self::MODES) - 1)],
            'notes' => $status === PaymentStatus::Refunded ? 'Refund on leaving' : null,
        ]);
        // Entered at a believable time of day on the day it was paid.
        $at = $paidOn->copy()->setTime(mt_rand(7, 20), mt_rand(0, 59));
        $payment->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
    }

    /** @param Collection<int, SeatAllocation> $allocations */
    private function setRenewal(Collection $allocations, Carbon $renewal): void
    {
        $allocations->each(fn (SeatAllocation $allocation) => $allocation->update(['renewal_date' => $renewal->toDateString()]));
    }

    /** @param Collection<int, SeatAllocation> $allocations */
    private function feeOf(Collection $allocations): float
    {
        return (float) $allocations->sum(fn (SeatAllocation $allocation) => $allocation->batch->monthly_fee);
    }

    /** The last 7 days: most members check in a little after their batch starts; finished batches leave the rest absent. */
    private function seedAttendance(): void
    {
        $now = now();
        $allocations = SeatAllocation::where('library_id', $this->library->id)->where('status', 'active')->with('batch')->get();

        for ($back = 6; $back >= 0; $back--) {
            $day = $this->today->copy()->subDays($back);
            foreach ($allocations as $allocation) {
                if (Carbon::parse($allocation->start_date)->gt($day)) {
                    continue;
                }
                $startsAt = Carbon::parse($day->toDateString().' '.substr($allocation->batch->start_time, 0, 5));
                if ($startsAt->gt($now)) {
                    continue; // hasn't started yet
                }
                // About 85% turn up on past days; today, a bit fewer have arrived so far.
                if (mt_rand(1, 100) > ($back === 0 ? 65 : 85)) {
                    continue;
                }
                $arrived = $startsAt->copy()->addMinutes(mt_rand(0, 45));
                if ($arrived->gt($now)) {
                    continue;
                }
                Attendance::create([
                    'library_id' => $this->library->id,
                    'member_id' => $allocation->member_id,
                    'batch_id' => $allocation->batch_id,
                    'date' => $day->toDateString(),
                    'status' => 'present',
                    'check_in_at' => $arrived->format('H:i'),
                ]);
            }
        }
    }
}
