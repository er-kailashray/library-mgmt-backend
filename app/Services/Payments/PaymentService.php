<?php

namespace App\Services\Payments;

use App\Enums\PaymentStatus;
use App\Models\Library;
use App\Models\Member;
use App\Models\Payment;
use App\Repositories\Contracts\PaymentRepositoryInterface;
use App\Repositories\Contracts\SeatAllocationRepositoryInterface;
use App\Services\Dues\MemberDue;
use App\Services\Dues\MemberDueService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentService
{
    public function __construct(
        private readonly PaymentRepositoryInterface $payments,
        private readonly SeatAllocationRepositoryInterface $allocations,
        private readonly MemberDueService $dues,
    ) {}

    /**
     * Outstanding dues (PENDING) first, then recorded payments newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listForLibrary(Library $library, ?CarbonInterface $today = null): array
    {
        $today ??= now();

        $pending = $this->dues->overdueMembers($library->id, $today)
            ->map(fn (MemberDue $due) => [
                'id' => 'due-'.$due->memberId,
                'member_id' => $due->memberId,
                'member_name' => $due->memberName,
                'amount' => $due->amount,
                'date' => $due->oldestRenewalDate->toDateString(),
                'status' => PaymentStatus::Pending->label(),
                'method' => '',
                // Dues are only computed from active allocations, so these members are always active.
                'member_status' => 'active',
            ]);

        $recorded = $this->payments->allForLibrary($library->id)
            ->map(fn (Payment $payment) => $this->present($payment));

        return $pending->values()->concat($recorded)->all();
    }

    /** How far ahead a restart date may be set. */
    public const RESTART_MAX_DAYS_AHEAD = 31;

    /**
     * Records a payment. Together with any earlier partial payments, each full monthly fee it
     * covers pushes renewal dates forward one cycle (so paying two overdue months clears both)
     * and makes it PAID; anything short of one fee is PARTIAL and leaves renewal dates untouched.
     *
     * The payment remembers the days it pays for (period_start..period_end), starting at the
     * paid-through date. With `start_date`, a member back from a break restarts on that date
     * instead, and the unpaid days in between are never charged.
     */
    public function record(Library $library, array $data): Payment
    {
        return DB::transaction(function () use ($library, $data) {
            $allocations = $this->allocations->activeForMember($data['member_id']);
            $monthlyFee = (float) $allocations->sum(fn ($allocation) => $allocation->batch->monthly_fee);
            $periodStart = $this->paidThrough($allocations) ?? now()->startOfDay();

            if (! empty($data['start_date'])) {
                $periodStart = $this->restartOn($data['member_id'], Carbon::parse($data['start_date'])->startOfDay(), $allocations);
            }

            // Earlier partial payments count toward this cycle, so 400 + 600 against a 1000 fee settles it.
            $alreadyPaid = $this->payments->unsettledPartialTotals([$data['member_id']])->get($data['member_id'], 0.0);
            $monthsCovered = $monthlyFee > 0 ? (int) floor(($data['amount'] + $alreadyPaid) / $monthlyFee) : 1;
            $status = $monthsCovered >= 1 ? PaymentStatus::Paid : PaymentStatus::Partial;

            $payment = $this->payments->create([
                'library_id' => $library->id,
                'member_id' => $data['member_id'],
                'amount' => $data['amount'],
                'status' => $status,
                'payment_date' => $data['payment_date'] ?? now()->toDateString(),
                // A partial payment is toward the next cycle, so it shows that cycle.
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodStart->copy()->addMonthsNoOverflow(max(1, $monthsCovered))->subDay()->toDateString(),
                'seats' => self::seatSnapshot($allocations),
                'payment_mode' => $data['payment_mode'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            if ($monthsCovered >= 1) {
                $this->allocations->extendRenewalDates($data['member_id'], $monthsCovered);
            }

            return $payment->load('member');
        });
    }

    /** Part-payments made since the last full one; they count toward the next full fee. */
    public function partialCredit(Member $member): float
    {
        return (float) $this->payments->unsettledPartialTotals([$member->id])->get($member->id, 0.0);
    }

    /**
     * Restarts a member after a break without taking money: they're due from $date and the days
     * between their old paid-through date and $date are never charged.
     */
    public function restart(Member $member, CarbonInterface $date): void
    {
        DB::transaction(fn () => $this->restartOn($member->id, $date, $this->allocations->activeForMember($member->id)));
    }

    /**
     * What a member leaving today could get back, or null when they haven't paid for any days
     * still to come. "full" is what they paid for periods not yet over (the current month's fee
     * for payments from before periods were recorded); "partial" is the unused days' share.
     *
     * @return array{full: float, partial: float, unused_days: int, paid_through: string}|null
     */
    public function refundOptions(Member $member, float $monthlyFee, ?CarbonInterface $today = null): ?array
    {
        $today = Carbon::instance($today ?? now())->startOfDay();
        $paidThrough = $this->paidThrough($this->allocations->activeForMember($member->id));

        if (! $paidThrough || $paidThrough->lte($today) || $monthlyFee <= 0) {
            return null;
        }

        $unusedDays = (int) $today->diffInDays($paidThrough);
        $cycleDays = max(1, (int) $paidThrough->copy()->subMonthNoOverflow()->diffInDays($paidThrough));

        $paidAhead = (float) $member->payments()
            ->where('status', PaymentStatus::Paid)
            ->whereDate('period_end', '>=', $today->toDateString())
            ->sum('amount');
        $full = round($paidAhead > 0 ? $paidAhead : $monthlyFee);
        $partial = min($full, round($monthlyFee / $cycleDays * $unusedDays));

        return [
            'full' => (float) $full,
            'partial' => (float) $partial,
            'unused_days' => $unusedDays,
            'paid_through' => $paidThrough->toDateString(),
        ];
    }

    /**
     * Records money given back to a member who's leaving, for the unused days up to their
     * paid-through date. Stored as a negative amount so collections net it off.
     */
    public function recordRefund(Library $library, Member $member, float $amount, ?string $mode, CarbonInterface $paidThrough): Payment
    {
        return $this->payments->create([
            'seats' => self::seatSnapshot($this->allocations->activeForMember($member->id)),
            'library_id' => $library->id,
            'member_id' => $member->id,
            'amount' => -abs($amount),
            'status' => PaymentStatus::Refunded,
            'payment_date' => now()->toDateString(),
            'period_start' => now()->toDateString(),
            'period_end' => $paidThrough->copy()->subDay()->toDateString(),
            'payment_mode' => $mode,
            'notes' => 'Refund on leaving',
        ]);
    }

    /**
     * A copy of the seats a payment was for, kept on the payment so it stays right even if the
     * batch is renamed, re-priced or deleted, or the member later moves seats.
     *
     * @param  iterable<\App\Models\SeatAllocation>  $allocations
     * @return list<array{batch_id: int, batch_name: string, seat_id: int, seat_number: int, monthly_fee: float}>
     */
    public static function seatSnapshot(iterable $allocations): array
    {
        $seats = [];
        foreach ($allocations as $allocation) {
            $seats[] = [
                'batch_id' => $allocation->batch_id,
                'batch_name' => $allocation->batch?->name,
                'seat_id' => $allocation->seat_id,
                'seat_number' => (int) $allocation->seat?->seat_number,
                'monthly_fee' => (float) $allocation->batch?->monthly_fee,
            ];
        }

        return $seats;
    }

    /**
     * The earliest renewal date across a member's active seats: paid up to (not including) this day.
     *
     * @param  \Illuminate\Support\Collection<int, \App\Models\SeatAllocation>  $allocations
     */
    public function paidThrough($allocations): ?Carbon
    {
        $date = $allocations->min('renewal_date');

        return $date ? Carbon::parse($date)->startOfDay() : null;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, \App\Models\SeatAllocation>  $allocations
     */
    private function restartOn(int $memberId, CarbonInterface $date, $allocations): Carbon
    {
        $date = Carbon::instance($date)->startOfDay();
        $paidThrough = $this->paidThrough($allocations);

        if (! $paidThrough) {
            throw ValidationException::withMessages(['start_date' => 'This member has no active seat to restart.']);
        }
        // Moving earlier would hand back days they've paid for; that's a refund, not a restart.
        if ($date->lt($paidThrough)) {
            throw ValidationException::withMessages(['start_date' => 'Pick a date on or after '.$paidThrough->format('j M Y').', when their paid time ends.']);
        }
        if ($date->gt(now()->startOfDay()->addDays(self::RESTART_MAX_DAYS_AHEAD))) {
            throw ValidationException::withMessages(['start_date' => 'The restart date can be at most '.self::RESTART_MAX_DAYS_AHEAD.' days from today.']);
        }

        $this->allocations->setRenewalDates($memberId, $date);

        return $date;
    }

    /**
     * One member's payments, newest first, in the same shape as the library-wide list.
     *
     * @return array<int, array<string, mixed>>
     */
    public function historyForMember(Member $member): array
    {
        return $member->payments()
            ->orderByDesc('payment_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Payment $payment) => $this->present($payment->setRelation('member', $member)))
            ->all();
    }

    /**
     * What to collect to renew a member right now: their overdue amount if they're behind,
     * otherwise next month's fee less anything already paid toward it.
     */
    public function suggestedRenewalAmount(Member $member, float $monthlyFee, ?MemberDue $due): float
    {
        if ($due) {
            return $due->amount;
        }

        $alreadyPaid = $this->payments->unsettledPartialTotals([$member->id])->get($member->id, 0.0);

        return max(0.0, $monthlyFee - $alreadyPaid);
    }

    /**
     * @return array<string, mixed>
     */
    public function present(Payment $payment): array
    {
        return [
            'id' => (string) $payment->id,
            'member_id' => $payment->member_id,
            'member_name' => $payment->member->name,
            'member_status' => $payment->member->status,
            'amount' => $payment->amount,
            'date' => $payment->payment_date->toDateString(),
            // When it was entered in the app (the time of day the money was taken, for payments made today).
            'recorded_at' => $payment->created_at?->toIso8601String(),
            // The days it pays for (or gives back); null for payments recorded before this was tracked.
            'period_start' => $payment->period_start?->toDateString(),
            'period_end' => $payment->period_end?->toDateString(),
            // The seats it was for, as they were then; null when it couldn't be worked out.
            'seats' => $payment->seats ? array_map(fn (array $seat) => [
                'batch_name' => $seat['batch_name'] ?? null,
                'seat_number' => $seat['seat_number'] ?? null,
            ], $payment->seats) : null,
            'status' => $payment->status->label(),
            'method' => config("library.payment_modes.{$payment->payment_mode}")
                ?? config('library.payment_mode_fallback_label'),
        ];
    }
}
