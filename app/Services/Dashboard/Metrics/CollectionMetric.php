<?php

namespace App\Services\Dashboard\Metrics;

use App\Models\Library;
use App\Repositories\Contracts\PaymentRepositoryInterface;
use App\Repositories\Contracts\SeatAllocationRepositoryInterface;
use App\Services\Dashboard\Contracts\DashboardMetric;
use App\Services\Dues\MemberDueService;
use Carbon\CarbonInterface;

class CollectionMetric implements DashboardMetric
{
    public function __construct(
        private readonly PaymentRepositoryInterface $payments,
        private readonly SeatAllocationRepositoryInterface $allocations,
        private readonly MemberDueService $dues,
    ) {}

    public function compute(Library $library, CarbonInterface $today): array
    {
        return [
            'monthly_collection' => $this->payments->sumBetween($library->id, $today->copy()->startOfMonth(), $today),
            'pending_collection' => $this->dues->overdueMembers($library->id, $today)->sum('amount'),
            'today_collection' => $this->payments->sumBetween($library->id, $today, $today),
            // Revenue the library should bring in per month from current allocations
            'expected_collection' => $this->allocations->sumActiveMonthlyFees($library->id),
        ];
    }
}
