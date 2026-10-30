<?php

namespace App\Services\Attendance;

use App\Models\Attendance;
use App\Models\Batch;
use App\Models\Library;
use App\Models\SeatAllocation;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class AttendanceService
{
    /** Attendance can be marked or corrected for today and the 6 days before it. */
    public const EDITABLE_DAYS = 7;

    /**
     * A date's roster, grouped by batch, for every batch: the members seated in it, whether
     * they came and when, and anyone from another batch who came to this one that day.
     *
     * A member who never checked in counts as absent once the batch is over. If they came to a
     * different batch that day instead, that's shown rather than absent.
     *
     * @return array<int, array<string, mixed>>
     */
    public function roster(Library $library, string $date, ?CarbonInterface $now = null): array
    {
        $now = Carbon::instance($now ?? now());
        $day = Carbon::parse($date)->startOfDay();

        $batches = Batch::where('library_id', $library->id)->orderBy('start_time')->get();
        $records = Attendance::where('library_id', $library->id)->whereDate('date', $day->toDateString())->with('member')->get();
        $byKey = $records->keyBy(fn (Attendance $a) => "{$a->member_id}:{$a->batch_id}");
        $byMember = $records->groupBy('member_id');
        $allocations = SeatAllocation::where('library_id', $library->id)
            ->where('status', 'active')
            ->with(['member', 'seat'])
            ->get()
            ->groupBy('batch_id');

        return $batches->map(function (Batch $batch) use ($day, $now, $byKey, $byMember, $allocations, $batches) {
            [$startsAt, $endsAt] = $this->batchWindow($batch, $day);
            $ended = $now->gte($endsAt);
            $seated = $allocations->get($batch->id, collect());
            $seatedIds = $seated->pluck('member_id')->all();

            $members = $seated->map(function (SeatAllocation $allocation) use ($batch, $byKey, $byMember, $batches, $ended) {
                $record = $byKey->get("{$allocation->member_id}:{$batch->id}");
                $elsewhere = $record ? null : $this->cameToAnotherBatch($byMember->get($allocation->member_id, collect()), $batch, $batches);

                return [
                    'member_id' => $allocation->member_id,
                    'name' => $allocation->member->name,
                    'seat_number' => $allocation->seat?->seat_number,
                    'status' => $record?->status ?? ($ended && ! $elsewhere ? 'absent' : null),
                    // Absent because the batch ended without a check-in, not because someone marked it.
                    'auto_absent' => ! $record && $ended && ! $elsewhere,
                    'check_in_at' => $this->time($record?->check_in_at),
                    'elsewhere' => $elsewhere,
                ];
            })->sortBy('seat_number')->values();

            // Members from other batches who came to this one that day.
            $visitors = $byKey
                ->filter(fn (Attendance $a) => $a->batch_id === $batch->id && $a->status === 'present' && ! in_array($a->member_id, $seatedIds, true))
                ->map(fn (Attendance $a) => [
                    'member_id' => $a->member_id,
                    'name' => $a->member?->name,
                    'check_in_at' => $this->time($a->check_in_at),
                ])
                ->sortBy('check_in_at')
                ->values();

            return [
                'batch_id' => $batch->id,
                'batch_name' => $batch->name,
                'start_time' => substr($batch->start_time, 0, 5),
                'end_time' => substr($batch->end_time, 0, 5),
                'is_running' => $now->gte($startsAt) && $now->lt($endsAt),
                'has_ended' => $ended,
                'present_count' => $members->where('status', 'present')->count(),
                'members' => $members,
                'visitors' => $visitors,
            ];
        })->values()->all();
    }

    /**
     * Records that a member came (with the time) or was absent. Marking again on the same day
     * and batch replaces the earlier mark.
     */
    public function mark(Library $library, int $memberId, int $batchId, string $date, string $status, ?string $checkInAt = null): Attendance
    {
        return Attendance::updateOrCreate(
            [
                'library_id' => $library->id,
                'member_id' => $memberId,
                'batch_id' => $batchId,
                'date' => $date,
            ],
            ['status' => $status, 'check_in_at' => $status === 'present' ? $checkInAt : null]
        );
    }

    /** Undoes a mark: they're back to "not checked in" (or auto-absent if the batch is over). */
    public function clear(Library $library, int $memberId, int $batchId, string $date): void
    {
        Attendance::where('library_id', $library->id)
            ->where('member_id', $memberId)
            ->where('batch_id', $batchId)
            ->whereDate('date', $date)
            ->delete();
    }

    /** Whether a batch's session for this day is on right now (an overnight one runs into the next morning). */
    public function isRunning(Batch $batch, string $date, ?CarbonInterface $now = null): bool
    {
        $now = Carbon::instance($now ?? now());
        [$startsAt, $endsAt] = $this->batchWindow($batch, Carbon::parse($date)->startOfDay());

        return $now->gte($startsAt) && $now->lt($endsAt);
    }

    /**
     * The session on right now, and the day it belongs to: after midnight, a 10 PM - 2 AM batch is
     * still the previous day's. Today's sessions are checked first; null when nothing is running.
     *
     * @return array{date: string, batch_id: int}|null
     */
    public function liveSession(Library $library, ?CarbonInterface $now = null): ?array
    {
        $now = Carbon::instance($now ?? now());
        $batches = Batch::where('library_id', $library->id)->orderBy('start_time')->get();

        foreach ([$now->copy()->startOfDay(), $now->copy()->startOfDay()->subDay()] as $day) {
            foreach ($batches as $batch) {
                if ($this->isRunning($batch, $day->toDateString(), $now)) {
                    return ['date' => $day->toDateString(), 'batch_id' => $batch->id];
                }
            }
        }

        return null;
    }

    /** The first day that can still be marked or corrected. */
    public static function earliestEditableDate(?CarbonInterface $now = null): Carbon
    {
        return Carbon::instance($now ?? now())->startOfDay()->subDays(self::EDITABLE_DAYS - 1);
    }

    /**
     * When a batch runs on a given day; an overnight batch (e.g. 10 PM - 2 AM) ends the next morning.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function batchWindow(Batch $batch, CarbonInterface $day): array
    {
        $startsAt = Carbon::parse($day->toDateString().' '.substr($batch->start_time, 0, 5));
        $endsAt = Carbon::parse($day->toDateString().' '.substr($batch->end_time, 0, 5));
        if ($endsAt->lte($startsAt)) {
            $endsAt->addDay();
        }

        return [$startsAt, $endsAt];
    }

    /**
     * @param  Collection<int, Attendance>  $memberRecords
     * @param  Collection<int, Batch>  $batches
     * @return array{batch_name: string, check_in_at: ?string}|null
     */
    private function cameToAnotherBatch(Collection $memberRecords, Batch $batch, Collection $batches): ?array
    {
        $visit = $memberRecords->first(fn (Attendance $a) => $a->batch_id !== $batch->id && $a->status === 'present');

        return $visit ? [
            'batch_name' => $batches->firstWhere('id', $visit->batch_id)?->name ?? 'another batch',
            'check_in_at' => $this->time($visit->check_in_at),
        ] : null;
    }

    private function time(?string $value): ?string
    {
        return $value ? substr($value, 0, 5) : null;
    }
}
