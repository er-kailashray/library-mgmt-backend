<?php

namespace App\Services\Dashboard\Metrics;

use App\Models\Library;
use App\Repositories\Contracts\SeatAllocationRepositoryInterface;
use App\Services\Dashboard\Contracts\DashboardMetric;
use Carbon\CarbonInterface;

class LibraryOverviewMetric implements DashboardMetric
{
    public function __construct(
        private readonly SeatAllocationRepositoryInterface $allocations,
    ) {}

    public function compute(Library $library, CarbonInterface $today): array
    {
        return [
            'library_name' => $library->library_name,
            'library_address' => $library->address,
            'owner_name' => $library->user->name,
            'owner_photo' => $library->user->profile_photo_url,
            'total_seats' => $library->seats()->count(),
            'occupied_seats' => $this->allocations->countOccupiedSeats($library->id),
            'total_members' => $library->members()->where('status', 'active')->count(),
        ];
    }
}
