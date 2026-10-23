<?php

namespace App\Services\Dashboard\Metrics;

use App\Models\Library;
use App\Repositories\Contracts\SeatAllocationRepositoryInterface;
use App\Services\Dashboard\Contracts\DashboardMetric;
use App\Services\Dues\MemberDue;
use App\Services\Dues\MemberDueService;
use Carbon\CarbonInterface;

class DuesMetric implements DashboardMetric
{
    public function __construct(
        private readonly MemberDueService $dues,
        private readonly SeatAllocationRepositoryInterface $allocations,
    ) {}

    public function compute(Library $library, CarbonInterface $today): array
    {
        $overdue = $this->dues->overdueMembers($library->id, $today);

        $criticalAfterDays = (int) config('library.dues.critical_after_days');
        $longOverdueCycles = (int) config('library.dues.long_overdue_cycles');

        // From tomorrow, matching MemberDueService::upcomingRenewals() - renewing today already counts as due.
        $upcomingRenewals = $this->allocations->countMembersRenewingBetween(
            $library->id,
            $today->copy()->addDay(),
            $today->copy()->addDays((int) config('library.dues.upcoming_renewal_window_days')),
        );

        return [
            'due_members_count' => $overdue->count(),
            'due_total' => (float) $overdue->sum(fn (MemberDue $due) => $due->amount),
            // The few most urgent, so Home can name them instead of only counting them.
            'top_dues' => $overdue
                ->sortBy([
                    fn (MemberDue $a, MemberDue $b) => $b->daysOverdue <=> $a->daysOverdue,
                    fn (MemberDue $a, MemberDue $b) => $b->amount <=> $a->amount,
                ])
                ->take((int) config('library.dashboard.top_dues_limit'))
                ->map(fn (MemberDue $due) => [
                    'member_id' => $due->memberId,
                    'name' => $due->memberName,
                    'amount' => $due->amount,
                    'days_overdue' => $due->daysOverdue,
                ])
                ->values()
                ->all(),
            'critical_dues_count' => $overdue->filter(fn (MemberDue $due) => $due->daysOverdue >= $criticalAfterDays)->count(),
            'this_month_dues_count' => $overdue->filter(fn (MemberDue $due) => $due->oldestRenewalDate->isSameMonth($today))->count(),
            'upcoming_renewals' => $upcomingRenewals,
            'overdue_summary' => [
                'two_months_plus' => $overdue->filter(fn (MemberDue $due) => $due->cyclesOverdue >= $longOverdueCycles)->count(),
                'one_month' => $overdue->filter(fn (MemberDue $due) => $due->cyclesOverdue < $longOverdueCycles)->count(),
                'due_this_week' => $upcomingRenewals,
            ],
        ];
    }
}
