<?php

namespace App\Services\Reports;

use App\Models\Attendance;
use App\Models\Batch;
use App\Models\Library;
use App\Models\Member;
use App\Models\Payment;
use App\Models\SeatAllocation;
use App\Services\Dues\MemberDue;
use App\Services\Dues\MemberDueService;
use App\Services\Seats\SeatBlockService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * GET /reports: revenue by month, dues and collection rate, member movement and seat occupancy,
 * and attendance. Totals use the same rules as the dashboard (refunds are negative payments that
 * net off; dues come from MemberDueService), so Reports and Home never disagree.
 */
class ReportService
{
    /** Allowed values for the months range. */
    public const MONTH_OPTIONS = [3, 6, 12];

    /** Attendance averages look back this many days, today included. */
    private const ATTENDANCE_WINDOW_DAYS = 30;

    /** Enrolled members not seen for at least this many days are listed as missing. */
    private const ABSENT_AFTER_DAYS = 7;

    private const ABSENT_LIST_LIMIT = 10;

    /** How long dues have been outstanding: [key, label, min days, max days or null]. */
    private const DUE_AGES = [
        ['week', 'Up to 7 days', 0, 7],
        ['month', '8–30 days', 8, 30],
        ['two_months', '31–60 days', 31, 60],
        ['older', 'Over 60 days', 61, null],
    ];

    public function __construct(
        private readonly MemberDueService $dues,
        private readonly SeatBlockService $blocks,
    ) {}

    /** @return array<string, mixed> */
    public function build(Library $library, int $months, CarbonInterface $now): array
    {
        $today = Carbon::instance($now)->startOfDay();
        // Oldest first, ending with the current month.
        $monthStarts = collect(range($months - 1, 0))
            ->map(fn (int $back) => $today->copy()->startOfMonth()->subMonthsNoOverflow($back));

        $revenue = $this->revenue($library, $monthStarts, $today);
        $batches = $library->batches()
            ->orderBy('start_time')
            ->withCount(['allocations as occupied' => fn ($query) => $query->where('status', 'active')])
            ->get();

        return [
            'months' => $months,
            'from' => $monthStarts->first()->toDateString(),
            'to' => $today->toDateString(),
            'revenue' => $revenue,
            'dues' => $this->dues($library, $now, $revenue['this_month']),
            'members' => $this->members($library, $monthStarts, $today, $batches),
            'attendance' => $this->attendance($library, $today, $batches),
        ];
    }

    /** @param Collection<int, Carbon> $monthStarts */
    private function revenue(Library $library, Collection $monthStarts, Carbon $today): array
    {
        $payments = Payment::where('library_id', $library->id)
            ->whereDate('payment_date', '>=', $monthStarts->first()->toDateString())
            ->whereDate('payment_date', '<=', $today->toDateString())
            ->get(['amount', 'payment_mode', 'payment_date']);
        $byMonth = $payments->groupBy(fn (Payment $payment) => substr((string) $payment->payment_date, 0, 7));

        $rows = $monthStarts->map(function (Carbon $start) use ($byMonth) {
            $list = $byMonth->get($start->format('Y-m'), collect());
            $received = $list->filter(fn (Payment $payment) => $payment->amount >= 0);

            return [
                'month' => $start->format('Y-m'),
                'label' => $start->format('M'),
                // Net of refunds, like Home's "collected this month".
                'collected' => round((float) $list->sum('amount'), 2),
                'refunded' => round(abs((float) $list->filter(fn (Payment $payment) => $payment->amount < 0)->sum('amount')), 2),
                'payments_count' => $received->count(),
            ];
        })->values();

        $thisMonth = (float) $rows->last()['collected'];
        $lastMonth = (float) ($rows->count() > 1 ? $rows[$rows->count() - 2]['collected'] : 0);

        $modes = config('library.payment_modes');
        $byMode = $payments
            ->filter(fn (Payment $payment) => $payment->amount >= 0)
            ->groupBy(fn (Payment $payment) => $payment->payment_mode ?: 'other')
            ->map(fn (Collection $list, string $mode) => [
                'mode' => $mode,
                'label' => $modes[$mode] ?? config('library.payment_mode_fallback_label'),
                'amount' => round((float) $list->sum('amount'), 2),
                'count' => $list->count(),
            ])
            ->sortByDesc('amount')
            ->values();

        return [
            'by_month' => $rows,
            'total' => round((float) $rows->sum('collected'), 2),
            'this_month' => $thisMonth,
            'last_month' => $lastMonth,
            // Null when there is nothing to compare with.
            'change_pct' => $lastMonth > 0 ? round(($thisMonth - $lastMonth) / $lastMonth * 100, 1) : null,
            'by_mode' => $byMode,
        ];
    }

    private function dues(Library $library, CarbonInterface $now, float $collectedThisMonth): array
    {
        $overdue = $this->dues->overdueMembers($library->id, $now);
        $outstanding = round((float) $overdue->sum(fn (MemberDue $due) => $due->amount), 2);
        $billed = $collectedThisMonth + $outstanding;

        return [
            'total_outstanding' => $outstanding,
            'overdue_members' => $overdue->count(),
            'by_age' => collect(self::DUE_AGES)->map(function (array $age) use ($overdue) {
                [$key, $label, $min, $max] = $age;
                $inRange = $overdue->filter(fn (MemberDue $due) => $due->daysOverdue >= $min && ($max === null || $due->daysOverdue <= $max));

                return [
                    'key' => $key,
                    'label' => $label,
                    'members' => $inRange->count(),
                    'amount' => round((float) $inRange->sum(fn (MemberDue $due) => $due->amount), 2),
                ];
            })->values(),
            // Of what was collected this month plus what is still owed, the share already in hand.
            'collection_rate' => $billed > 0 ? (int) round($collectedThisMonth / $billed * 100) : null,
        ];
    }

    /**
     * @param  Collection<int, Carbon>  $monthStarts
     * @param  Collection<int, Batch>  $batches
     */
    private function members(Library $library, Collection $monthStarts, Carbon $today, Collection $batches): array
    {
        $members = Member::where('library_id', $library->id)
            ->withMin('allocations', 'start_date')
            ->withMax('allocations', 'ended_on')
            ->get(['id', 'join_date', 'status', 'created_at']);

        // Same fallback order as Member::joinedOn().
        $joinedIn = $members->countBy(fn (Member $member) => substr((string) ($member->join_date ?? $member->allocations_min_start_date ?? $member->created_at), 0, 7));
        // Someone who has left: inactive, in the month their last seat ended.
        $leftIn = $members
            ->filter(fn (Member $member) => $member->status === 'inactive' && $member->allocations_max_ended_on)
            ->countBy(fn (Member $member) => substr((string) $member->allocations_max_ended_on, 0, 7));

        $allocations = SeatAllocation::where('library_id', $library->id)->get(['member_id', 'start_date', 'ended_on', 'status']);

        $months = $monthStarts->map(function (Carbon $start) use ($joinedIn, $leftIn, $allocations, $today) {
            $end = $start->copy()->endOfMonth()->min($today)->toDateString();
            $active = $allocations
                ->filter(fn (SeatAllocation $allocation) => $this->day($allocation->start_date) <= $end
                    && ($allocation->status === 'active' || ($allocation->ended_on && $this->day($allocation->ended_on) > $end)))
                ->pluck('member_id')
                ->unique()
                ->count();

            return [
                'month' => $start->format('Y-m'),
                'label' => $start->format('M'),
                'joined' => (int) $joinedIn->get($start->format('Y-m'), 0),
                'left' => (int) $leftIn->get($start->format('Y-m'), 0),
                'active' => $active,
            ];
        })->values();

        $totalSeats = $library->seats()->count();
        // Same as Home: seats blocked today are left out of a batch's total.
        $blocked = $this->blocks->blockedCounts($library->id, $batches, $today);

        return [
            'active' => $members->where('status', 'active')->count(),
            'by_month' => $months,
            'occupancy' => $batches->map(function (Batch $batch) use ($totalSeats, $blocked) {
                $total = $totalSeats - ($blocked[$batch->id] ?? 0);

                return [
                    'batch_id' => $batch->id,
                    'name' => $batch->name,
                    'start_time' => $batch->start_time,
                    'end_time' => $batch->end_time,
                    'occupied' => (int) $batch->occupied,
                    'total' => $total,
                    'rate' => $total > 0 ? (int) round($batch->occupied / $total * 100) : 0,
                ];
            })->values(),
        ];
    }

    /** @param Collection<int, Batch> $batches */
    private function attendance(Library $library, Carbon $today, Collection $batches): array
    {
        $from = $today->copy()->subDays(self::ATTENDANCE_WINDOW_DAYS - 1)->toDateString();
        $rows = Attendance::where('library_id', $library->id)
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $today->toDateString())
            ->get(['member_id', 'batch_id', 'date', 'status']);

        $perBatch = $batches->map(function (Batch $batch) use ($rows) {
            $mine = $rows->where('batch_id', $batch->id);
            // Averages are over the days attendance was actually taken, not every calendar day.
            $markedDays = $mine->map(fn (Attendance $row) => $this->day($row->date))->unique()->count();
            $average = $markedDays > 0 ? round($mine->where('status', 'present')->count() / $markedDays, 1) : null;

            return [
                'batch_id' => $batch->id,
                'name' => $batch->name,
                'enrolled' => (int) $batch->occupied,
                'marked_days' => $markedDays,
                'average_present' => $average,
                'rate' => $average !== null && $batch->occupied > 0 ? (int) min(100, round($average / $batch->occupied * 100)) : null,
            ];
        })->values();

        return [
            'window_days' => self::ATTENDANCE_WINDOW_DAYS,
            // Without any attendance taken, "not seen lately" would list everyone.
            'tracked' => $rows->isNotEmpty(),
            'by_batch' => $perBatch,
            'not_seen' => $rows->isNotEmpty() ? $this->notSeen($library, $today) : ['count' => 0, 'members' => []],
        ];
    }

    /** Enrolled members whose last check-in (or, never seen, their start) is ABSENT_AFTER_DAYS or more ago. */
    private function notSeen(Library $library, Carbon $today): array
    {
        $enrolled = SeatAllocation::where('library_id', $library->id)
            ->where('status', 'active')
            ->whereDate('start_date', '<=', $today->toDateString())
            ->with('member:id,name,status')
            ->get(['member_id', 'start_date'])
            ->groupBy('member_id')
            ->filter(fn (Collection $allocations) => $allocations->first()->member?->status === 'active');

        $lastSeen = Attendance::where('library_id', $library->id)
            ->where('status', 'present')
            ->whereIn('member_id', $enrolled->keys())
            ->groupBy('member_id')
            ->selectRaw('member_id, max(date) as last_seen')
            ->pluck('last_seen', 'member_id');

        $missing = $enrolled
            ->map(function (Collection $allocations, int $memberId) use ($lastSeen, $today) {
                $seen = $lastSeen->get($memberId) ? $this->day($lastSeen->get($memberId)) : null;
                $since = $seen ?? $allocations->min(fn (SeatAllocation $allocation) => $this->day($allocation->start_date));

                return [
                    'member_id' => $memberId,
                    'name' => $allocations->first()->member->name,
                    'last_seen' => $seen,
                    'days' => (int) Carbon::parse($since)->diffInDays($today),
                ];
            })
            ->filter(fn (array $row) => $row['days'] >= self::ABSENT_AFTER_DAYS)
            ->sortByDesc('days')
            ->values();

        return [
            'after_days' => self::ABSENT_AFTER_DAYS,
            'count' => $missing->count(),
            'members' => $missing->take(self::ABSENT_LIST_LIMIT)->values(),
        ];
    }

    /** "YYYY-MM-DD" from a date column, whether it arrives as a string or a Carbon. */
    private function day(mixed $value): string
    {
        return substr((string) $value, 0, 10);
    }
}
