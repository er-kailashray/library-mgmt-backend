<?php

namespace App\Http\Controllers;

use App\Services\Dashboard\DashboardService;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardService $dashboard,
    ) {}

    public function dashboard(Request $request)
    {
        return response()->json([
            'status' => true,
            'data' => $this->dashboard->build($this->library($request)),
        ]);
    }
}
