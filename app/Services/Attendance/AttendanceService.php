<?php

namespace App\Services\Attendance;

use App\Models\Attendance;
use App\Models\Batch;
use App\Models\Library;
use App\Models\SeatAllocation;

class AttendanceService
{
    /**
     * Today's (or a given date's) roster, grouped by batch: every member with
     * an active allocation in that batch, and whether they've been marked
     * present/absent yet (null if not marked).
     *
     * @return array<int, array<string, mixed>>
     */
    public function roster(Library $library, string $date): array
    {
        $batches = Batch::where('library_id', $library->id)->orderBy('start_time')->get();

        $marked = Attendance::where('library_id', $library->id)
            ->where('date', $date)
            ->get()
            ->keyBy(fn (Attendance $a) => "{$a->member_id}:{$a->batch_id}");

        return $batches->map(function (Batch $batch) use ($library, $marked) {
            $allocations = SeatAllocation::where('library_id', $library->id)
                ->where('batch_id', $batch->id)
                ->where('status', 'active')
                ->with(['member', 'seat'])
                ->get();

            return [
                'batch_id' => $batch->id,
                'batch_name' => $batch->name,
                'members' => $allocations->map(fn (SeatAllocation $allocation) => [
                    'member_id' => $allocation->member_id,
                    'name' => $allocation->member->name,
                    'seat_number' => $allocation->seat->seat_number,
                    'status' => $marked->get("{$allocation->member_id}:{$batch->id}")?->status,
                ])->values(),
            ];
        })->values()->all();
    }

    public function mark(Library $library, int $memberId, int $batchId, string $date, string $status): void
    {
        Attendance::updateOrCreate(
            [
                'library_id' => $library->id,
                'member_id' => $memberId,
                'batch_id' => $batchId,
                'date' => $date,
            ],
            ['status' => $status]
        );
    }
}
