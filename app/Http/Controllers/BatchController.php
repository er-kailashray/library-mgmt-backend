<?php

namespace App\Http\Controllers;

use App\Models\Batch;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BatchController extends Controller
{
    public function index()
    {
        $library = $this->getLoggedInUserLibraries();

        if (! $library) {
            return response()->json(['status' => false, 'message' => 'Library not found!'], 400);
        }

        // allocations_count = members currently seated in the batch.
        $batches = $library->batches()
            ->withCount(['allocations' => fn ($query) => $query->where('status', 'active')])
            ->orderBy('start_time')
            ->get();

        return response()->json([
            'status' => true,
            'data' => $batches,
        ]);
    }

    public function store(Request $request)
    {
        $library = $this->getLoggedInUserLibraries();

        if (! $library) {
            return response()->json(['status' => false, 'message' => 'Library not found!'], 400);
        }

        if ($error = $this->validateBatch($request, $library)) {
            return $error;
        }

        $batch = Batch::create([
            'library_id' => $library->id,
            'name' => $request->name,
            'start_time' => $request->start_time,
            'end_time' => $request->end_time,
            'monthly_fee' => $request->monthly_fee,
        ]);

        return response()->json(['status' => true, 'message' => 'Batch created successfully']);
    }

    public function update(Request $request, $id)
    {
        $library = $this->getLoggedInUserLibraries();

        if (! $library) {
            return response()->json(['status' => false, 'message' => 'Library not found!'], 400);
        }

        $batch = $library->batches()->find($id);

        if (! $batch) {
            return response()->json(['status' => false, 'message' => 'Batch not found'], 404);
        }

        if ($error = $this->validateBatch($request, $library, $batch)) {
            return $error;
        }

        $batch->update($request->only(['name', 'start_time', 'end_time', 'monthly_fee']));

        return response()->json(['status' => true, 'message' => 'Batch updated successfully', 'data' => $batch->fresh()]);
    }

    public function destroy($id)
    {
        $library = $this->getLoggedInUserLibraries();

        if (! $library) {
            return response()->json(['status' => false, 'message' => 'Library not found!'], 400);
        }

        $batch = $library->batches()->find($id);

        if (! $batch) {
            return response()->json(['status' => false, 'message' => 'Batch not found'], 404);
        }

        // Soft delete: the batch, its past seats and attendance stay on record. Still never with members seated in it.
        $activeMembers = $batch->allocations()->where('status', 'active')->count();

        if ($activeMembers > 0) {
            return response()->json([
                'status' => false,
                'message' => sprintf(
                    '%d %s in this batch. Move them to another batch or end their membership before deleting it.',
                    $activeMembers,
                    $activeMembers === 1 ? 'member is' : 'members are',
                ),
            ], 422);
        }

        $batch->delete();

        return response()->json(['status' => true, 'message' => 'Batch deleted successfully']);
    }

    /**
     * Shared by create and edit. When editing, $ignore is the batch being changed, so its own
     * name and time slot don't count as taken. Returns a 422 response when times clash.
     */
    private function validateBatch(Request $request, $library, ?Batch $ignore = null)
    {
        $request->validate([
            // A deleted batch's name is free to use again.
            'name' => ['required', 'string', 'max:100', Rule::unique('batches')->where(function ($query) use ($library) {
                return $query->where('library_id', $library->id)->whereNull('deleted_at');
            })->ignore($ignore?->id)],
            // Batches run on 15-minute steps (e.g. 10:30); an end before the start means it runs past midnight.
            'start_time' => ['required', 'date_format:H:i', 'regex:/^\d{2}:(00|15|30|45)$/'],
            'end_time' => ['required', 'date_format:H:i', 'regex:/^\d{2}:(00|15|30|45)$/', 'different:start_time'],
            'monthly_fee' => 'required|numeric|min:0',
        ], [
            'start_time.regex' => 'Pick a start time on a 15-minute step, like 10:00 or 10:30.',
            'end_time.regex' => 'Pick an end time on a 15-minute step, like 10:00 or 10:30.',
            'end_time.different' => 'The end time must be different from the start time.',
        ]);

        // A time of day belongs to one batch only.
        $clash = $library->batches()
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->id))
            ->get()
            ->first(fn (Batch $batch) => self::timesOverlap(
                $request->start_time,
                $request->end_time,
                substr($batch->start_time, 0, 5),
                substr($batch->end_time, 0, 5),
            ));

        if ($clash) {
            return response()->json([
                'status' => false,
                'message' => sprintf(
                    'This time overlaps with %s (%s - %s).',
                    $clash->name,
                    self::formatTime($clash->start_time),
                    self::formatTime($clash->end_time),
                ),
            ], 422);
        }

        return null;
    }

    /**
     * Whether two daily time ranges share any minute. A range whose end is at or before its
     * start runs past midnight, so it is split into "start to midnight" and "midnight to end".
     */
    public static function timesOverlap(string $startA, string $endA, string $startB, string $endB): bool
    {
        foreach (self::segments($startA, $endA) as [$fromA, $toA]) {
            foreach (self::segments($startB, $endB) as [$fromB, $toB]) {
                if ($fromA < $toB && $fromB < $toA) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return list<array{int, int}> minute ranges within one day */
    private static function segments(string $start, string $end): array
    {
        $from = self::toMinutes($start);
        $to = self::toMinutes($end);

        return $from < $to ? [[$from, $to]] : [[$from, 24 * 60], [0, $to]];
    }

    private static function toMinutes(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time));

        return $hours * 60 + $minutes;
    }

    private static function formatTime(string $time): string
    {
        return date('g:i A', strtotime($time));
    }

    protected function getLoggedInUserLibraries()
    {
        return $library = auth()->user()->libraries()->first();
    }
}
