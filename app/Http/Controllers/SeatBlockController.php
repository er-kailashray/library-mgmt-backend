<?php

namespace App\Http\Controllers;

use App\Http\Requests\BlockSeatRequest;
use App\Models\Seat;
use App\Models\SeatBlock;
use App\Services\Seats\SeatBlockService;
use Carbon\Carbon;
use Illuminate\Http\Request;

/** Taking a seat out of use for a while, and putting it back. */
class SeatBlockController extends Controller
{
    public function __construct(
        private readonly SeatBlockService $blocks,
    ) {}

    public function store(BlockSeatRequest $request, int $seatId)
    {
        $library = $this->library($request);
        $seat = Seat::where('library_id', $library->id)->find($seatId);

        if (! $seat) {
            return response()->json(['status' => false, 'message' => 'Seat not found'], 404);
        }

        $created = $this->blocks->block(
            $library,
            $seat,
            $request->input('batch_ids'),
            $request->filled('starts_on') ? Carbon::parse($request->starts_on) : now()->startOfDay(),
            $request->filled('ends_on') ? Carbon::parse($request->ends_on) : null,
            $request->input('reason'),
        );

        return response()->json([
            'status' => true,
            'message' => "Seat {$seat->seat_number} blocked",
            'data' => $created->map->toApi()->values(),
        ], 201);
    }

    public function destroy(Request $request, int $id)
    {
        $block = SeatBlock::where('library_id', $this->library($request)->id)->find($id);

        if (! $block) {
            return response()->json(['status' => false, 'message' => 'Block not found'], 404);
        }

        $this->blocks->unblock($block, now());

        return response()->json(['status' => true, 'message' => "Seat {$block->seat->seat_number} is back in use"]);
    }
}
