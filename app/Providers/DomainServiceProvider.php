<?php

namespace App\Providers;

use App\Repositories\Contracts\PaymentRepositoryInterface;
use App\Repositories\Contracts\SeatAllocationRepositoryInterface;
use App\Repositories\Eloquent\PaymentRepository;
use App\Repositories\Eloquent\SeatAllocationRepository;
use App\Services\Dashboard\DashboardService;
use App\Services\Dashboard\Metrics\CollectionMetric;
use App\Services\Dashboard\Metrics\DuesMetric;
use App\Services\Dashboard\Metrics\LibraryOverviewMetric;
use App\Services\Dashboard\Metrics\RecentPaymentsMetric;
use App\Services\Dues\MemberDueService;
use Illuminate\Support\ServiceProvider;

class DomainServiceProvider extends ServiceProvider
{
    /**
     * Repository contract => implementation.
     */
    public array $bindings = [
        PaymentRepositoryInterface::class => PaymentRepository::class,
        SeatAllocationRepositoryInterface::class => SeatAllocationRepository::class,
    ];

    /**
     * Sections that make up GET /api/dashboard, in payload order.
     */
    private const DASHBOARD_METRICS = [
        LibraryOverviewMetric::class,
        CollectionMetric::class,
        DuesMetric::class,
        RecentPaymentsMetric::class,
    ];

    public function register(): void
    {
        // Scoped so dues are computed once per request and shared by every consumer
        $this->app->scoped(MemberDueService::class);

        $this->app->tag(self::DASHBOARD_METRICS, 'dashboard.metrics');

        $this->app->when(DashboardService::class)
            ->needs('$metrics')
            ->giveTagged('dashboard.metrics');
    }
}
