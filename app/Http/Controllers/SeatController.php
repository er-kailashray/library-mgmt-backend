<?php

namespace App\Http\Controllers;

use App\Models\Batch;
use App\Models\Seat;
use App\Models\SeatAllocation;
use Illuminate\Http\Request;

class SeatController extends Controller
{
    public function index(Request $request)
    {
        $library = auth()->user()->libraries()->first();

        if (! $library) {
            return response()->json([
                'status' => false,
                'message' => 'Library not found',
            ], 404);
        }

        // Get batches (filtered or all)
        $batchQuery = Batch::where('library_id', $library->id);

        if ($request->has('batch_ids')) {
            $batchQuery->whereIn('id', $request->batch_ids);
        }

        $batches = $batchQuery->get();

        // Get seats
        $seats = Seat::where('library_id', $library->id)->orderBy('seat_number')->get();

        // Get active allocations for selected batches
        $allocations = SeatAllocation::where('library_id', $library->id)
            ->whereIn('batch_id', $batches->pluck('id'))
            ->where('status', 'active')
            ->with('member:id,name')
            ->get();

        // Index allocations for fast lookup: who holds each seat in each batch.
        $occupied = [];
        $today = now()->toDateString();
        foreach ($allocations as $allocation) {
            $renewal = substr((string) $allocation->renewal_date, 0, 10);
            $occupied[$allocation->seat_id][$allocation->batch_id] = [
                'member_id' => $allocation->member_id,
                'name' => $allocation->member?->name,
                'renewal_date' => $renewal,
                // Same rule as the member list: due once the renewal date is reached.
                'is_due' => $renewal <= $today,
            ];
        }

        // Build matrix
        $matrix = [];

        foreach ($seats as $seat) {

            $seatData = [
                'seat_id' => $seat->id,
                'seat_number' => $seat->seat_number,
                'batches' => [],
            ];

            foreach ($batches as $batch) {
                $seatData['batches'][] = [
                    'batch_id' => $batch->id,
                    'batch_name' => $batch->name,
                    'available' => ! isset($occupied[$seat->id][$batch->id]),
                    'occupant' => $occupied[$seat->id][$batch->id] ?? null,
                ];
            }

            $matrix[] = $seatData;
        }

        return response()->json([
            'status' => true,
            'data' => [
                'batches' => $batches,
                'seats' => $matrix,
            ],
        ]);
    }
}
