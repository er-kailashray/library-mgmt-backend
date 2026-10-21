<?php

namespace App\Services\Dues;

use App\Models\SeatAllocation;
use App\Repositories\Contracts\PaymentRepositoryInterface;
use App\Repositories\Contracts\SeatAllocationRepositoryInterface;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Single source of truth for "who owes what". An active allocation whose
 * renewal_date has been reached owes one batch fee per missed billing cycle, less
 * any partial payments the member has already made toward it.
 */
class MemberDueService
{
    /** @var array<string, Collection<int, MemberDue>> */
    private array $cache = [];

    /** @var array<string, Collection<int, UpcomingRenewal>> */
    private array $upcomingCache = [];

    public function __construct(
        private readonly SeatAllocationRepositoryInterface $allocations,
        private readonly PaymentRepositoryInterface $payments,
    ) {}

    /**
     * @return Collection<int, MemberDue> keyed by member id
     */
    public function overdueMembers(int $libraryId, CarbonInterface $today): Collection
    {
        $key = $libraryId.'|'.$today->toDateString();

        if (! isset($this->cache[$key])) {
            $byMember = $this->allocations->overdue($libraryId, $today)->groupBy('member_id');
            $credits = $this->payments->unsettledPartialTotals($byMember->keys()->all());

            $this->cache[$key] = $byMember->map(
                fn (Collection $memberAllocations, int $memberId) => $this->summarise($memberAllocations, $today, $credits->get($memberId, 0.0))
            );
        }

        return $this->cache[$key];
    }

    /**
     * Members whose nearest active allocation renews within the next $windowDays
     * (and hasn't lapsed yet - that's overdueMembers()'s job).
     *
     * @return Collection<int, UpcomingRenewal> keyed by member id
     */
    public function upcomingRenewals(int $libraryId, CarbonInterface $today, int $windowDays): Collection
    {
        $key = $libraryId.'|'.$today->toDateString().'|'.$windowDays;

        if (! isset($this->upcomingCache[$key])) {
            $byMember = $this->allocations
                // From tomorrow: anyone renewing today is already due, so overdueMembers() covers them.
                ->renewingBetween($libraryId, $today->copy()->addDay(), $today->copy()->addDays($windowDays))
                ->groupBy('member_id');
            $credits = $this->payments->unsettledPartialTotals($byMember->keys()->all());

            $this->upcomingCache[$key] = $byMember->map(
                fn (Collection $memberAllocations, int $memberId) => $this->summariseUpcoming($memberAllocations, $today, $credits->get($memberId, 0.0))
            );
        }

        return $this->upcomingCache[$key];
    }

    /**
     * @param  Collection<int, SeatAllocation>  $memberAllocations
     */
    private function summarise(Collection $memberAllocations, CarbonInterface $today, float $partialCredit): MemberDue
    {
        $amount = 0.0;
        $maxCycles = 0;
        $oldest = null;

        foreach ($memberAllocations as $allocation) {
            $renewal = Carbon::parse($allocation->renewal_date)->startOfDay();
            $cycles = $this->missedCycles($renewal, $today);

            $amount += $cycles * (float) $allocation->batch->monthly_fee;
            $maxCycles = max($maxCycles, $cycles);
            $oldest = $oldest === null || $renewal->lt($oldest) ? $renewal : $oldest;
        }

        $member = $memberAllocations->first()->member;

        return new MemberDue(
            memberId: $member->id,
            memberName: $member->name,
            amount: max(0.0, $amount - $partialCredit),
            oldestRenewalDate: $oldest,
            daysOverdue: (int) $oldest->diffInDays($today->copy()->startOfDay()),
            cyclesOverdue: $maxCycles,
        );
    }

    /**
     * Renewal on Sep 15 checked on Oct 20 = 2 missed cycles (Sep 15 and Oct 15).
     */
    private function missedCycles(CarbonInterface $renewal, CarbonInterface $today): int
    {
        return (int) $renewal->diffInMonths($today->copy()->startOfDay()) + 1;
    }

    /**
     * @param  Collection<int, SeatAllocation>  $memberAllocations
     */
    private function summariseUpcoming(Collection $memberAllocations, CarbonInterface $today, float $partialCredit): UpcomingRenewal
    {
        $amount = 0.0;
        $nearest = null;

        foreach ($memberAllocations as $allocation) {
            $renewal = Carbon::parse($allocation->renewal_date)->startOfDay();
            $amount += (float) $allocation->batch->monthly_fee;
            $nearest = $nearest === null || $renewal->lt($nearest) ? $renewal : $nearest;
        }

        $member = $memberAllocations->first()->member;

        return new UpcomingRenewal(
            memberId: $member->id,
            memberName: $member->name,
            memberPhone: $member->phone,
            amount: max(0.0, $amount - $partialCredit),
            renewalDate: $nearest,
            daysUntilRenewal: (int) $today->copy()->startOfDay()->diffInDays($nearest),
        );
    }
}
