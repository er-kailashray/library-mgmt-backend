<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Member;
use App\Models\Seat;
use App\Models\Batch;
use App\Models\SeatAllocation;

class MemberController extends Controller
{
    public function admission(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:100',
            'phone' => 'required|regex:/^[6-9]\d{9}$/',
            'allocations' => 'required|array|min:1',
            'allocations.*.batch_id' => 'required|exists:batches,id',
            'allocations.*.seat_id' => 'required|exists:seats,id',
        ]);

        $library = auth()->user()->libraries()->first();

        if (!$library) {
            return response()->json([
                'status' => false,
                'message' => 'Library not found'
            ], 404);
        }

        return DB::transaction(function () use ($request, $library) {

            // Validate each allocation
            foreach ($request->allocations as $allocation) {

                // Ensure batch belongs to same library
                $batch = Batch::where('id', $allocation['batch_id'])
                    ->where('library_id', $library->id)
                    ->first();

                if (!$batch) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Invalid batch selected'
                    ], 422);
                }

                // Ensure seat belongs to same library
                $seat = Seat::where('id', $allocation['seat_id'])
                    ->where('library_id', $library->id)
                    ->first();

                if (!$seat) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Invalid seat selected'
                    ], 422);
                }

                // Check if seat already occupied in that batch
                $occupied = SeatAllocation::where('library_id', $library->id)
                    ->where('batch_id', $batch->id)
                    ->where('seat_id', $seat->id)
                    ->where('status', 'active')
                    ->exists();

                if ($occupied) {
                    return response()->json([
                        'status' => false,
                        'message' => "Seat {$seat->seat_number} already occupied in batch {$batch->name}"
                    ], 422);
                }
            }

            // Create member
            $member = Member::create([
                'library_id' => $library->id,
                'name' => $request->name,
                'phone' => $request->phone,
                'status' => 'active',
            ]);

            // Create allocations
            foreach ($request->allocations as $allocation) {

                SeatAllocation::create([
                    'library_id' => $library->id,
                    'member_id' => $member->id,
                    'batch_id' => $allocation['batch_id'],
                    'seat_id' => $allocation['seat_id'],
                    'start_date' => now(),
                    'renewal_date' => now()->addMonth(),
                    'status' => 'active',
                ]);
            }

            return response()->json([
                'status' => true,
                'message' => 'Admission successful',
                'data' => $member->load('allocations')
            ]);
        });
    }
}
