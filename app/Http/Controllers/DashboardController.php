<?php

namespace App\Http\Controllers;

use App\Repositories\Contracts\PaymentRepositoryInterface;
use App\Services\Dashboard\DashboardService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardService $dashboard,
    ) {}

    /**
     * Total collected in one calendar month (YYYY-MM), for browsing past months from Home.
     */
    public function collections(Request $request, PaymentRepositoryInterface $payments)
    {
        $validated = $request->validate([
            'month' => ['required', 'date_format:Y-m', 'before_or_equal:'.now()->format('Y-m')],
        ]);

        $start = Carbon::createFromFormat('Y-m-d', $validated['month'].'-01')->startOfDay();
        $end = $start->copy()->endOfMonth();
        $libraryId = $this->library($request)->id;

        return response()->json([
            'status' => true,
            'data' => [
                'month' => $validated['month'],
                'collected' => $payments->sumBetween($libraryId, $start, $end),
                'payments_count' => $payments->countBetween($libraryId, $start, $end),
            ],
        ]);
    }

    public function dashboard(Request $request)
    {
        return response()->json([
            'status' => true,
            'data' => $this->dashboard->build($this->library($request)),
        ]);
    }
}
