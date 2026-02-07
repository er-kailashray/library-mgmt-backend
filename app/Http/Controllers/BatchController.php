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

        if (!$library) {
            return response()->json(['status' => false, 'message' => 'Library not found!'], 400);
        }

        $batches = $library->batches()->orderBy('start_time')->get();

        return response()->json([
            'status' => true,
            'data' => $batches
        ]);
    }

    public function store(Request $request)
    {
        $library = $this->getLoggedInUserLibraries();

        if (!$library) {
            return response()->json(['status' => false, 'message' => 'Library not found!'], 400);
        }

        $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('batches')->where(function ($query) use ($library) {
                return $query->where('library_id', $library->id);
            })],
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i',
            'monthly_fee' => 'required|numeric|min:0',
        ]);

        $overlap = Batch::where('library_id', $library->id)
            ->where(function ($query) use ($request) {
                $query->where('start_time', '<', $request->end_time)
                    ->where('end_time', '>', $request->start_time);
            })
            ->exists();

        if ($overlap) {
            return response()->json([
                'status' => false,
                'message' => 'Batch time overlaps with existing batch'
            ], 422);
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

    protected function getLoggedInUserLibraries()
    {
        return $library = auth()->user()->libraries()->first();
    }
}
