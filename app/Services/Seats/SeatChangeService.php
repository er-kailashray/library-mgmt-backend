<?php

namespace App\Services\Seats;

use App\Models\Member;
use App\Models\Seat;
use App\Models\SeatAllocation;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Moves a member to another seat in the same batch. Nothing about what they pay changes: the old
 * seat ends today and the new one carries on with the same renewal date, so dues, paid-through and
 * fees stay exactly as they were, and seat history shows the move.
 */
class SeatChangeService
{
    public function __construct(
        private readonly SeatBlockService $blocks,
    ) {}

    public function change(Member $member, int $batchId, int $seatId): SeatAllocation
    {
        return DB::transaction(function () use ($member, $batchId, $seatId) {
            $current = $member->allocations()
                ->where('status', 'active')
                ->where('batch_id', $batchId)
                ->with(['batch', 'seat'])
                ->lockForUpdate()
                ->first();

            if (! $current) {
                throw ValidationException::withMessages(['batch_id' => "{$member->name} doesn't have a seat in this batch."]);
            }

            if ($current->seat_id === $seatId) {
                throw ValidationException::withMessages(['seat_id' => "{$member->name} is already in seat {$current->seat->seat_number}."]);
            }

            $seat = Seat::where('library_id', $member->library_id)->findOrFail($seatId);

            // Locked so two owners' phones can't both move someone into the same seat at once.
            $taken = SeatAllocation::where('library_id', $member->library_id)
                ->where('batch_id', $batchId)
                ->where('seat_id', $seatId)
                ->where('status', 'active')
                ->lockForUpdate()
                ->exists();

            if ($taken) {
                throw ValidationException::withMessages(['seat_id' => "Seat {$seat->seat_number} is already taken in {$current->batch->name}."]);
            }

            $today = now()->startOfDay();
            if ($this->blocks->isBlocked($member->library_id, $seat->id, $batchId, $today)) {
                throw ValidationException::withMessages(['seat_id' => "Seat {$seat->seat_number} is blocked right now."]);
            }

            // Someone admitted with a start date still ahead keeps it; otherwise the new seat starts today.
            $startDate = Carbon::parse($current->start_date)->max($today)->toDateString();

            $current->update(['status' => 'ended', 'ended_on' => $today->toDateString()]);

            return SeatAllocation::create([
                'library_id' => $member->library_id,
                'member_id' => $member->id,
                'batch_id' => $batchId,
                'seat_id' => $seat->id,
                'start_date' => $startDate,
                'renewal_date' => $current->renewal_date,
                'status' => 'active',
            ])->load(['batch', 'seat']);
        });
    }
}
