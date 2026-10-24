<?php

namespace App\Services\Dues;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\SeatAllocation;
use App\Repositories\Contracts\PaymentRepositoryInterface;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Where this month's fees stand. Every active seat booking (member x batch) owes one fee per
 * calendar month, due on its renewal day. That fee is either already paid (the booking is paid
 * through past this month's due day - however far ahead), due and unpaid (due day reached), or
 * due later this month. Months missed before this one are reported separately as arrears.
 *
 * Unlike cash received, this doesn't inflate when someone pays ahead or clears old dues.
 */
class MonthlyFeeService
{
    /** Notes prefix the admission flow puts on a readmission's settlement of old dues. */
    public const SETTLEMENT_NOTE = 'Previous dues settled';

    public function __construct(
        private readonly PaymentRepositoryInterface $payments,
    ) {}

    /**
     * @return array{expected: float, collected: float, due: float, due_members: int, upcoming: float, upcoming_members: int, arrears: float, arrears_members: int}
     */
    public function forCurrentMonth(int $libraryId, CarbonInterface $today): array
    {
        $today = Carbon::instance($today)->startOfDay();

        $byMember = SeatAllocation::where('library_id', $libraryId)
            ->where('status', 'active')
            ->with('batch')
            ->get()
            ->groupBy('member_id');

        $credits = $this->payments->unsettledPartialTotals($byMember->keys()->all());

        $totals = ['expected' => 0.0, 'collected' => 0.0, 'due' => 0.0, 'upcoming' => 0.0, 'arrears' => 0.0];
        $counts = ['due' => 0, 'upcoming' => 0, 'arrears' => 0];

        foreach ($byMember as $memberId => $allocations) {
            $owed = ['arrears' => 0.0, 'due' => 0.0, 'upcoming' => 0.0];

            foreach ($allocations as $allocation) {
                $fee = (float) $allocation->batch->monthly_fee;
                $paidThrough = Carbon::parse($allocation->renewal_date)->startOfDay();
                // This month's due day: the renewal day-of-month, clamped to this month's length.
                $dueDay = $today->copy()->startOfMonth()->day(min($paidThrough->day, $today->daysInMonth));

                $totals['expected'] += $fee;

                if ($paidThrough->gt($dueDay)) {
                    $totals['collected'] += $fee;

                    continue;
                }

                $owed[$dueDay->lte($today) ? 'due' : 'upcoming'] += $fee;
                $owed['arrears'] += $paidThrough->lt($dueDay) ? (int) $paidThrough->diffInMonths($dueDay) * $fee : 0.0;
            }

            // Partial payments settle the oldest debt first, then this month's fee.
            $credit = $credits->get($memberId, 0.0);
            foreach (['arrears', 'due', 'upcoming'] as $bucket) {
                $applied = min($credit, $owed[$bucket]);
                $owed[$bucket] -= $applied;
                $credit -= $applied;
                if ($bucket !== 'arrears') {
                    $totals['collected'] += $applied;
                }
            }

            foreach ($owed as $bucket => $amount) {
                $totals[$bucket] += $amount;
                $counts[$bucket] += $amount > 0 ? 1 : 0;
            }
        }

        return [
            'expected' => $totals['expected'],
            'collected' => $totals['collected'],
            'due' => $totals['due'],
            'due_members' => $counts['due'],
            'upcoming' => $totals['upcoming'],
            'upcoming_members' => $counts['upcoming'],
            'arrears' => $totals['arrears'],
            'arrears_members' => $counts['arrears'],
        ];
    }

    /**
     * Splits the cash received this month by which month's fees it paid for. Payments always
     * settle the oldest unpaid month first, so the months a member's payments covered are the ones
     * just before their current paid-through date: walking back from it, each full monthly fee of
     * this month's cash covered one earlier due date. A partial payment counts toward the due date
     * it's chipping at. Readmission settlements are old dues by definition.
     *
     * @return array{received: float, for_this_month: float, for_earlier_months: float, in_advance: float}
     */
    public function cashSplitForCurrentMonth(int $libraryId, CarbonInterface $today): array
    {
        $today = Carbon::instance($today)->startOfDay();
        $monthStart = $today->copy()->startOfMonth();
        $monthEnd = $today->copy()->endOfMonth()->startOfDay();

        $payments = Payment::where('library_id', $libraryId)
            ->whereDate('payment_date', '>=', $monthStart->toDateString())
            ->whereDate('payment_date', '<=', $today->toDateString())
            ->get();

        $split = ['for_this_month' => 0.0, 'for_earlier_months' => 0.0, 'in_advance' => 0.0];
        $classify = function (CarbonInterface $dueDate, float $amount) use (&$split, $monthStart, $monthEnd) {
            $bucket = $dueDate->lt($monthStart) ? 'for_earlier_months' : ($dueDate->gt($monthEnd) ? 'in_advance' : 'for_this_month');
            $split[$bucket] += $amount;
        };

        // Members who've since left still tell us what their payment covered, via the seats they
        // had: use each member's most recent bookings (active ones if any, otherwise the last ended set).
        $allocationsByMember = SeatAllocation::where('library_id', $libraryId)
            ->whereIn('member_id', $payments->pluck('member_id')->unique()->values())
            ->with('batch')
            ->get()
            ->groupBy('member_id')
            ->map(function ($allocations) {
                $active = $allocations->where('status', 'active');
                if ($active->isNotEmpty()) {
                    return $active;
                }
                $latest = $allocations->max('renewal_date');

                return $allocations->where('renewal_date', $latest);
            });
        $credits = $this->payments->unsettledPartialTotals($payments->pluck('member_id')->unique()->values()->all());

        foreach ($payments->groupBy('member_id') as $memberId => $memberPayments) {
            // A refund gives back days it names itself, so it comes off the bucket those days fall in.
            [$refunds, $memberPayments] = $memberPayments->partition(fn (Payment $payment) => $payment->status === PaymentStatus::Refunded);
            foreach ($refunds as $refund) {
                $classify(Carbon::instance($refund->period_start ?? $refund->payment_date)->startOfDay(), (float) $refund->amount);
            }

            [$settlements, $regular] = $memberPayments->partition(fn (Payment $payment) => str_starts_with((string) $payment->notes, self::SETTLEMENT_NOTE));
            $split['for_earlier_months'] += (float) $settlements->sum('amount');

            $cash = (float) $regular->sum('amount');
            $allocations = $allocationsByMember->get($memberId);

            // No seat ever recorded for them: nothing to tie the money to a month, so treat it as old dues.
            if (! $allocations || $cash <= 0) {
                $split['for_earlier_months'] += $cash;

                continue;
            }

            $monthlyFee = (float) $allocations->sum(fn ($allocation) => $allocation->batch->monthly_fee);
            $paidThrough = Carbon::parse($allocations->min('renewal_date'))->startOfDay();

            // The part still sitting as a partial payment is toward the paid-through due date itself.
            $partial = min($cash, $credits->get($memberId, 0.0));
            $fullCycles = $monthlyFee > 0 ? (int) floor(($cash - $partial) / $monthlyFee + 1e-9) : 0;
            $leftover = $cash - $partial - $fullCycles * $monthlyFee;

            for ($i = 1; $i <= $fullCycles; $i++) {
                $classify($paidThrough->copy()->subMonthsNoOverflow($i), $monthlyFee);
            }
            $classify($paidThrough, $partial + $leftover);
        }

        return ['received' => (float) $payments->sum('amount')] + $split;
    }
}
