<?php

namespace App\Services\Dashboard\Metrics;

use App\Models\Attendance;
use App\Models\Batch;
use App\Models\Library;
use App\Repositories\Contracts\SeatAllocationRepositoryInterface;
use App\Services\Dashboard\Contracts\DashboardMetric;
use App\Services\Seats\SeatBlockService;
use Carbon\CarbonInterface;

class LibraryOverviewMetric implements DashboardMetric
{
    public function __construct(
        private readonly SeatAllocationRepositoryInterface $allocations,
        private readonly SeatBlockService $blocks,
    ) {}

    public function compute(Library $library, CarbonInterface $today): array
    {
        $totalSeats = $library->seats()->count();
        // Who has checked in to each batch today, for Home's live batch card.
        $checkedInToday = Attendance::where('library_id', $library->id)
            ->whereDate('date', $today->toDateString())
            ->where('status', 'present')
            ->selectRaw('batch_id, count(*) as checked_in')
            ->groupBy('batch_id')
            ->pluck('checked_in', 'batch_id');

        $batches = $library->batches()
            ->orderBy('start_time')
            ->withCount(['allocations as occupied' => fn ($query) => $query->where('status', 'active')])
            ->get();
        $blocked = $this->blocks->blockedCounts($library->id, $batches, $today);

        return [
            'library_name' => $library->library_name,
            'library_address' => $library->address,
            'owner_name' => $library->user->name,
            'owner_photo' => $library->user->profile_photo_url,
            'total_seats' => $totalSeats,
            'occupied_seats' => $this->allocations->countOccupiedSeats($library->id),
            'total_members' => $library->members()->where('status', 'active')->count(),
            // Seats filled in each batch (a seat holds one member per batch), in time order,
            // so the owner can see which slots are busy and which are empty.
            'batch_occupancy' => $batches
                ->map(fn (Batch $batch) => [
                    'checked_in_today' => (int) $checkedInToday->get($batch->id, 0),
                    'id' => $batch->id,
                    'name' => $batch->name,
                    'start_time' => $batch->start_time,
                    'end_time' => $batch->end_time,
                    'occupied' => $batch->occupied,
                    // Seats blocked today are neither free nor filled, so they're left out of the total.
                    'total' => $totalSeats - ($blocked[$batch->id] ?? 0),
                    'blocked' => $blocked[$batch->id] ?? 0,
                ])
                ->all(),
        ];
    }
}
