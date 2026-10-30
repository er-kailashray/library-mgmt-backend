<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAttendanceRequest;
use App\Models\Batch;
use App\Models\Member;
use App\Models\SeatAllocation;
use App\Services\Attendance\AttendanceService;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    public function __construct(
        private readonly AttendanceService $attendance,
    ) {}

    public function index(Request $request)
    {
        $date = $request->query('date', now()->toDateString());

        return response()->json([
            'status' => true,
            'data' => [
                'date' => $date,
                // Marks can only be added or changed from this day up to today.
                'editable_from' => AttendanceService::earliestEditableDate()->toDateString(),
                // The session on right now and the day it belongs to (after midnight an overnight
                // batch is still yesterday's), so the app can open on it.
                'live_session' => $this->attendance->liveSession($this->library($request)),
                'batches' => $this->attendance->roster($this->library($request), $date),
            ],
        ]);
    }

    public function store(StoreAttendanceRequest $request)
    {
        $library = $this->library($request);
        $data = $request->validated();
        $date = $data['date'] ?? now()->toDateString();

        if (! Member::where('library_id', $library->id)->where('id', $data['member_id'])->where('status', 'active')->exists()) {
            return response()->json(['status' => false, 'message' => 'Only current members can be marked.'], 422);
        }

        $isEnrolled = SeatAllocation::where('library_id', $library->id)
            ->where('member_id', $data['member_id'])
            ->where('batch_id', $data['batch_id'])
            ->where('status', 'active')
            ->exists();

        // Coming to a different batch for the day (agreed with the owner) is fine; being marked
        // absent from a batch they don't belong to isn't.
        if (! $isEnrolled && $data['status'] !== 'present') {
            return response()->json([
                'status' => false,
                'message' => 'Member is not enrolled in this batch',
            ], 422);
        }

        // While the batch is on, "now" is when they came - today, or an overnight batch that began
        // yesterday and is still running after midnight. For anything earlier the time has to be given.
        $checkInAt = $data['check_in_at'] ?? null;
        if ($data['status'] === 'present' && ! $checkInAt) {
            $isLive = $date === now()->toDateString() || $this->attendance->isRunning(Batch::findOrFail($data['batch_id']), $date);
            if (! $isLive) {
                return response()->json([
                    'status' => false,
                    'message' => 'Pick the time they came in.',
                    'errors' => ['check_in_at' => ['Pick the time they came in.']],
                ], 422);
            }
            $checkInAt = now()->format('H:i');
        }

        $record = $this->attendance->mark($library, $data['member_id'], $data['batch_id'], $date, $data['status'], $checkInAt);

        return response()->json([
            'status' => true,
            'message' => 'Attendance updated',
            'data' => ['status' => $record->status, 'check_in_at' => $record->check_in_at ? substr($record->check_in_at, 0, 5) : null],
        ]);
    }

    /** Undo a mark for one member, batch and day. */
    public function clear(StoreAttendanceRequest $request)
    {
        $data = $request->validated();
        $this->attendance->clear($this->library($request), $data['member_id'], $data['batch_id'], $data['date'] ?? now()->toDateString());

        return response()->json(['status' => true, 'message' => 'Attendance cleared']);
    }
}
