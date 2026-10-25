<?php

namespace App\Http\Controllers;

use App\Services\Reports\ReportService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReportController extends Controller
{
    private const DEFAULT_MONTHS = 6;

    public function __construct(private readonly ReportService $reports) {}

    public function index(Request $request)
    {
        $validated = $request->validate([
            'months' => ['nullable', 'integer', Rule::in(ReportService::MONTH_OPTIONS)],
        ]);

        return response()->json([
            'status' => true,
            'data' => $this->reports->build($this->library($request), (int) ($validated['months'] ?? self::DEFAULT_MONTHS), now()),
        ]);
    }
}
