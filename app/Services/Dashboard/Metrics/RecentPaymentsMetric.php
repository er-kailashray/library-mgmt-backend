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
                    'member_id' => $payment->member_id,
                    'name' => $payment->member->name,
                    // Same details as the Payments list: status, mode, when, and the days it covers.
                    'status' => $payment->status->label(),
                    'method' => config("library.payment_modes.{$payment->payment_mode}") ?? config('library.payment_mode_fallback_label'),
                    'date' => $payment->payment_date->toDateString(),
                    'recorded_at' => $payment->created_at?->toIso8601String(),
                    'period_start' => $payment->period_start?->toDateString(),
                    'period_end' => $payment->period_end?->toDateString(),
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
