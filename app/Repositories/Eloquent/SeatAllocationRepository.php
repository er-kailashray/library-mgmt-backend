<?php

namespace App\Repositories\Eloquent;

use App\Models\SeatAllocation;
use App\Repositories\Contracts\SeatAllocationRepositoryInterface;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class SeatAllocationRepository implements SeatAllocationRepositoryInterface
{
    public function countOccupiedSeats(int $libraryId): int
    {
        return $this->active($libraryId)->distinct()->count('seat_id');
    }

    public function sumActiveMonthlyFees(int $libraryId): float
    {
        return (float) $this->active($libraryId)
            ->join('batches', 'batches.id', '=', 'seat_allocations.batch_id')
            ->sum('batches.monthly_fee');
    }

    public function overdue(int $libraryId, CarbonInterface $today): Collection
    {
        return $this->active($libraryId)
            ->where('renewal_date', '<', $today->toDateString())
            ->with(['member', 'batch'])
            ->get();
    }

    public function countMembersRenewingBetween(int $libraryId, CarbonInterface $from, CarbonInterface $to): int
    {
        return $this->active($libraryId)
            ->whereBetween('renewal_date', [$from->toDateString(), $to->toDateString()])
            ->distinct()
            ->count('member_id');
    }

    public function renewingBetween(int $libraryId, CarbonInterface $from, CarbonInterface $to): Collection
    {
        return $this->active($libraryId)
            ->whereBetween('renewal_date', [$from->toDateString(), $to->toDateString()])
            ->with(['member', 'batch'])
            ->get();
    }

    public function activeForMember(int $memberId): Collection
    {
        return SeatAllocation::where('member_id', $memberId)
            ->where('status', 'active')
            ->with('batch')
            ->get();
    }

    public function extendRenewalDates(int $memberId, int $months): void
    {
        $this->activeForMember($memberId)->each(function (SeatAllocation $allocation) use ($months) {
            $allocation->update([
                'renewal_date' => Carbon::parse($allocation->renewal_date)->addMonthsNoOverflow($months)->toDateString(),
            ]);
        });
    }

    private function active(int $libraryId): Builder
    {
        return SeatAllocation::where('seat_allocations.library_id', $libraryId)
            ->where('seat_allocations.status', 'active');
    }
}
