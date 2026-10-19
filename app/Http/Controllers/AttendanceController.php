<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAttendanceRequest;
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
                'batches' => $this->attendance->roster($this->library($request), $date),
            ],
        ]);
    }

    public function store(StoreAttendanceRequest $request)
    {
        $library = $this->library($request);
        $data = $request->validated();

        $isEnrolled = SeatAllocation::where('library_id', $library->id)
            ->where('member_id', $data['member_id'])
            ->where('batch_id', $data['batch_id'])
            ->where('status', 'active')
            ->exists();

        if (! $isEnrolled) {
            return response()->json([
                'status' => false,
                'message' => 'Member is not enrolled in this batch',
            ], 422);
        }

        $this->attendance->mark(
            $library,
            $data['member_id'],
            $data['batch_id'],
            $data['date'] ?? now()->toDateString(),
            $data['status'],
        );

        return response()->json(['status' => true, 'message' => 'Attendance updated']);
    }
}
