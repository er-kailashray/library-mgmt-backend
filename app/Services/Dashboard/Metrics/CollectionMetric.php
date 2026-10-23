<?php

namespace App\Services\Dashboard\Metrics;

use App\Models\Library;
use App\Repositories\Contracts\PaymentRepositoryInterface;
use App\Repositories\Contracts\SeatAllocationRepositoryInterface;
use App\Services\Dashboard\Contracts\DashboardMetric;
use App\Services\Dues\MemberDueService;
use App\Services\Dues\MonthlyFeeService;
use Carbon\CarbonInterface;

class CollectionMetric implements DashboardMetric
{
    public function __construct(
        private readonly PaymentRepositoryInterface $payments,
        private readonly SeatAllocationRepositoryInterface $allocations,
        private readonly MemberDueService $dues,
        private readonly MonthlyFeeService $monthlyFees,
    ) {}

    public function compute(Library $library, CarbonInterface $today): array
    {
        return [
            'monthly_collection' => $this->payments->sumBetween($library->id, $today->copy()->startOfMonth(), $today),
            'pending_collection' => $this->dues->overdueMembers($library->id, $today)->sum('amount'),
            'today_collection' => $this->payments->sumBetween($library->id, $today, $today),
            // Revenue the library should bring in per month from current allocations
            'expected_collection' => $this->allocations->sumActiveMonthlyFees($library->id),
            // Context for the month so a low total early on (e.g. on the 1st) reads as normal.
            'collection_month' => $today->format('Y-m'),
            // This month's fees by status - what "collected" should mean (cash received above
            // also includes advance and arrears payments).
            'month_fees' => $this->monthlyFees->forCurrentMonth($library->id, $today),
            // Cash received this month, split by which month's fees it paid for.
            'month_cash' => $this->monthlyFees->cashSplitForCurrentMonth($library->id, $today),
            'days_in_month' => $today->daysInMonth,
            'day_of_month' => $today->day,
            // Last month up to the same day (capped at its length) - a fair "so far" comparison.
            'last_month_same_period' => $this->payments->sumBetween(
                $library->id,
                $lastMonth = $today->copy()->startOfMonth()->subMonthNoOverflow(),
                $lastMonth->copy()->day(min($today->day, $lastMonth->daysInMonth)),
            ),
        ];
    }
}
