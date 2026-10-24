<?php

namespace App\Repositories\Contracts;

use App\Models\SeatAllocation;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

interface SeatAllocationRepositoryInterface
{
    /**
     * Number of distinct seats held by at least one active allocation.
     */
    public function countOccupiedSeats(int $libraryId): int;

    /**
     * Sum of batch monthly fees across all active allocations.
     */
    public function sumActiveMonthlyFees(int $libraryId): float;

    /**
     * Active allocations whose renewal_date is before $today, with member and batch loaded.
     *
     * @return Collection<int, SeatAllocation>
     */
    public function overdue(int $libraryId, CarbonInterface $today): Collection;

    /**
     * Distinct members with an active allocation renewing within [$from, $to].
     */
    public function countMembersRenewingBetween(int $libraryId, CarbonInterface $from, CarbonInterface $to): int;

    /**
     * Active allocations renewing within [$from, $to], with member and batch loaded.
     *
     * @return Collection<int, SeatAllocation>
     */
    public function renewingBetween(int $libraryId, CarbonInterface $from, CarbonInterface $to): Collection;

    /**
     * Active allocations for one member, with batch loaded.
     *
     * @return Collection<int, SeatAllocation>
     */
    public function activeForMember(int $memberId): Collection;

    /**
     * Push every active allocation's renewal_date forward for one member.
     */
    public function extendRenewalDates(int $memberId, int $months): void;

    /**
     * Set every active allocation's renewal_date for one member, e.g. restarting after a break.
     */
    public function setRenewalDates(int $memberId, CarbonInterface $date): void;
}
