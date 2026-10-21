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
            ->get();

        // Index allocations for fast lookup
        $occupied = [];
        foreach ($allocations as $allocation) {
            $occupied[$allocation->seat_id][$allocation->batch_id] = true;
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
