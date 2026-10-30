<?php

namespace App\Services\Seats;

use App\Models\Batch;
use App\Models\Library;
use App\Models\Seat;
use App\Models\SeatAllocation;
use App\Models\SeatBlock;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Taking a seat out of use for a while, and the one place that knows which seats are blocked.
 * A blocked seat is neither free nor filled: admission and seat changes skip it, and occupancy
 * leaves it out of the seats a batch has.
 */
class SeatBlockService
{
    /**
     * @param  list<int>|null  $batchIds  null or empty = every batch, including ones added later
     */
    public function block(Library $library, Seat $seat, ?array $batchIds, CarbonInterface $startsOn, ?CarbonInterface $endsOn, ?string $reason): Collection
    {
        $batchIds = $batchIds ? array_values(array_unique(array_map('intval', $batchIds))) : null;

        return DB::transaction(function () use ($library, $seat, $batchIds, $startsOn, $endsOn, $reason) {
            // Someone sits there: move them first. Checked for every batch the block would cover.
            $occupied = SeatAllocation::where('library_id', $library->id)
                ->where('seat_id', $seat->id)
                ->where('status', 'active')
                ->when($batchIds, fn ($q) => $q->whereIn('batch_id', $batchIds))
                ->with(['batch', 'member:id,name'])
                ->lockForUpdate()
                ->first();

            if ($occupied) {
                throw ValidationException::withMessages([
                    'batch_ids' => "{$occupied->member?->name} sits in seat {$seat->seat_number} in {$occupied->batch?->name}. Change their seat first.",
                ]);
            }

            $overlapping = SeatBlock::where('library_id', $library->id)
                ->where('seat_id', $seat->id)
                ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $startsOn->toDateString()))
                ->when($endsOn, fn ($q) => $q->whereDate('starts_on', '<=', $endsOn->toDateString()))
                ->get()
                ->first(fn (SeatBlock $block) => $batchIds === null || $block->batch_id === null || in_array($block->batch_id, $batchIds, true));

            if ($overlapping) {
                throw ValidationException::withMessages([
                    'starts_on' => "Seat {$seat->seat_number} is already blocked for some of that time. Unblock it first to change the dates.",
                ]);
            }

            $rows = $batchIds ?? [null];

            return collect($rows)->map(fn (?int $batchId) => SeatBlock::create([
                'library_id' => $library->id,
                'seat_id' => $seat->id,
                'batch_id' => $batchId,
                'starts_on' => $startsOn->toDateString(),
                'ends_on' => $endsOn?->toDateString(),
                'reason' => $reason !== null && trim($reason) !== '' ? trim($reason) : null,
            ]));
        });
    }

    /**
     * Back in use from today. A block that already ran keeps its past days on record (it ends
     * yesterday); one that hadn't started yet is simply removed.
     */
    public function unblock(SeatBlock $block, CarbonInterface $today): void
    {
        $today = $today->copy()->startOfDay();

        // Already over: nothing to change (moving its end to yesterday would lengthen it).
        if ($block->ends_on && $block->ends_on->lt($today)) {
            return;
        }

        if ($block->starts_on->gte($today)) {
            $block->delete();

            return;
        }

        $block->update(['ends_on' => $today->copy()->subDay()->toDateString()]);
    }

    /** @return Collection<int, SeatBlock> blocks in force on this day */
    public function activeOn(int $libraryId, CarbonInterface $day): Collection
    {
        return SeatBlock::where('library_id', $libraryId)->activeOn($day)->get();
    }

    public function isBlocked(int $libraryId, int $seatId, int $batchId, CarbonInterface $day): bool
    {
        return SeatBlock::where('library_id', $libraryId)
            ->where('seat_id', $seatId)
            ->where(fn ($q) => $q->whereNull('batch_id')->orWhere('batch_id', $batchId))
            ->activeOn($day)
            ->exists();
    }

    /**
     * Seats out of use in each batch today, by batch id. A seat blocked both for all batches and
     * for one batch counts once.
     *
     * @param  Collection<int, Batch>  $batches
     * @return array<int, int>
     */
    public function blockedCounts(int $libraryId, Collection $batches, CarbonInterface $day): array
    {
        $active = $this->activeOn($libraryId, $day);

        return $batches->mapWithKeys(fn (Batch $batch) => [
            $batch->id => $active->filter(fn (SeatBlock $block) => $block->covers($batch->id))->pluck('seat_id')->unique()->count(),
        ])->all();
    }
}
