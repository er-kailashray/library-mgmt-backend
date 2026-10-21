<?php

namespace App\Http\Controllers;

use App\Models\Library;
use App\Models\LibraryPhoto;
use App\Models\Seat;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class LibraryController extends Controller
{
    private const AMENITIES = ['ac', 'wifi', 'water', 'power_backup', 'parking', 'cctv', 'washroom', 'locker'];

    private const MAX_PHOTOS = 10;

    public function show(Request $request)
    {
        return response()->json([
            'status' => true,
            'data' => $this->present($this->library($request)),
        ]);
    }

    public function update(Request $request)
    {
        $library = $this->library($request);

        $validated = $request->validate([
            'library_name' => 'required|string|max:150',
            'total_seats' => 'required|integer|min:1|max:500',
        ]);

        $currentMax = (int) ($library->seats()->max('seat_number') ?? 0);
        $newCount = $validated['total_seats'];

        if ($newCount > $currentMax) {
            for ($i = $currentMax + 1; $i <= $newCount; $i++) {
                Seat::create(['library_id' => $library->id, 'seat_number' => $i]);
            }
        } elseif ($newCount < $currentMax) {
            $seatsToRemove = $library->seats()->where('seat_number', '>', $newCount)->get();
            $occupied = $seatsToRemove->filter(fn (Seat $seat) => $seat->allocations()->where('status', 'active')->exists());

            if ($occupied->isNotEmpty()) {
                $numbers = $occupied->pluck('seat_number')->sort()->implode(', ');

                return response()->json([
                    'status' => false,
                    'message' => "Can't reduce seats - seat(s) {$numbers} are currently occupied.",
                ], 422);
            }

            Seat::whereIn('id', $seatsToRemove->pluck('id'))->delete();
        }

        $library->update(['library_name' => $validated['library_name']]);

        return response()->json([
            'status' => true,
            'message' => 'Library updated',
            'data' => $this->present($library),
        ]);
    }

    public function updateAmenities(Request $request)
    {
        $library = $this->library($request);

        $validated = $request->validate([
            'amenities' => 'present|array',
            'amenities.*' => Rule::in(self::AMENITIES),
        ]);

        $library->update(['amenities' => $validated['amenities']]);

        return response()->json([
            'status' => true,
            'message' => 'Amenities updated',
            'data' => $this->present($library),
        ]);
    }

    public function updateLocation(Request $request)
    {
        $library = $this->library($request);

        $validated = $request->validate([
            'address' => 'required|string|max:500',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
        ]);

        $library->update($validated);

        return response()->json([
            'status' => true,
            'message' => 'Location updated',
            'data' => $this->present($library),
        ]);
    }

    public function uploadPhoto(Request $request)
    {
        $library = $this->library($request);

        $request->validate([
            'photo' => ['required', 'image', 'mimes:jpg,jpeg,png', 'max:5120'],
        ]);

        if ($library->photos()->count() >= self::MAX_PHOTOS) {
            return response()->json([
                'status' => false,
                'message' => 'You can add up to '.self::MAX_PHOTOS.' photos.',
            ], 422);
        }

        $path = $request->file('photo')->store("library-photos/{$library->id}", 'public');
        $photo = $library->photos()->create(['path' => $path]);

        return response()->json([
            'status' => true,
            'message' => 'Photo added',
            'data' => $this->presentPhoto($photo),
        ]);
    }

    public function deletePhoto(Request $request, int $id)
    {
        $library = $this->library($request);
        $photo = $library->photos()->find($id);

        if (! $photo) {
            return response()->json(['status' => false, 'message' => 'Photo not found'], 404);
        }

        Storage::disk('public')->delete($photo->path);
        $photo->delete();

        return response()->json(['status' => true, 'message' => 'Photo removed']);
    }

    private function present(Library $library): array
    {
        return [
            'id' => $library->id,
            'library_name' => $library->library_name,
            'total_seats' => $library->seats()->count(),
            'amenities' => $library->amenities ?? [],
            'address' => $library->address,
            'latitude' => $library->latitude,
            'longitude' => $library->longitude,
            'photos' => $library->photos()->get()->map(fn (LibraryPhoto $p) => $this->presentPhoto($p))->all(),
        ];
    }

    private function presentPhoto(LibraryPhoto $photo): array
    {
        return [
            'id' => $photo->id,
            'url' => asset('storage/'.$photo->path),
        ];
    }
}
