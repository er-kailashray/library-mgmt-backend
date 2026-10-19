<?php

namespace App\Services\Dashboard\Metrics;

use App\Models\Library;
use App\Models\Payment;
use App\Repositories\Contracts\PaymentRepositoryInterface;
use App\Services\Dashboard\Contracts\DashboardMetric;
use Carbon\CarbonInterface;

class RecentPaymentsMetric implements DashboardMetric
{
    public function __construct(
        private readonly PaymentRepositoryInterface $payments,
    ) {}

    public function compute(Library $library, CarbonInterface $today): array
    {
        $limit = (int) config('library.dashboard.recent_payments_limit');

        return [
            'recent_payments' => $this->payments->recent($library->id, $limit)
                ->map(fn (Payment $payment) => [
                    'name' => $payment->member->name,
                    // Active seat first (see PaymentRepository::recent); null if never allocated
                    'seat' => $payment->member->allocations->first()?->seat?->seat_number,
                    'amount' => $payment->amount,
                    'time_ago' => $payment->created_at->diffForHumans(),
                ])
                ->values()
                ->all(),
        ];
    }
}
