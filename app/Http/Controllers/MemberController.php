<?php

namespace App\Http\Controllers;

use App\Models\Batch;
use App\Models\Library;
use App\Models\Member;
use App\Models\Seat;
use App\Models\SeatAllocation;
use App\Services\Dues\MemberDueService;
use App\Services\Reminders\ReminderService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class MemberController extends Controller
{
    public function __construct(
        private readonly ReminderService $reminders,
        private readonly MemberDueService $dues,
    ) {}

    public function index()
    {
        $library = auth()->user()->libraries()->first();

        if (! $library) {
            return response()->json([
                'status' => false,
                'message' => 'Library not found',
            ], 404);
        }

        $members = Member::where('library_id', $library->id)
            ->where('status', 'active')
            ->with(['allocations' => function ($query) use ($library) {
                $query->where('library_id', $library->id)
                    ->where('status', 'active');
            }])
            ->get();

        $data = $members->map(function ($member) {
            // Determine payment status
            $isDue = $member->allocations->contains(function ($allocation) {
                return $allocation->renewal_date < now()->toDateString();
            });

            return [
                'id' => $member->id,
                'name' => $member->name,
                'phone' => $member->phone,
                'payment_status' => $isDue ? 'Due' : 'Paid',
                'active_allocations_count' => $member->allocations->count(),
            ];
        });

        return response()->json([
            'status' => true,
            'data' => $data,
        ]);
    }

    public function admission(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:100',
            'phone' => 'required|regex:/^[6-9]\d{9}$/',
            'gender' => ['nullable', Rule::in(['male', 'female', 'other'])],
            'allocations' => 'required|array|min:1',
            'allocations.*.batch_id' => 'required|exists:batches,id',
            'allocations.*.seat_id' => 'required|exists:seats,id',
        ]);

        $library = auth()->user()->libraries()->first();

        if (! $library) {
            return response()->json([
                'status' => false,
                'message' => 'Library not found',
            ], 404);
        }

        return DB::transaction(function () use ($request, $library) {

            // Validate each allocation
            foreach ($request->allocations as $allocation) {

                // Ensure batch belongs to same library
                $batch = Batch::where('id', $allocation['batch_id'])
                    ->where('library_id', $library->id)
                    ->first();

                if (! $batch) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Invalid batch selected',
                    ], 422);
                }

                // Ensure seat belongs to same library
                $seat = Seat::where('id', $allocation['seat_id'])
                    ->where('library_id', $library->id)
                    ->first();

                if (! $seat) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Invalid seat selected',
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
                        'message' => "Seat {$seat->seat_number} already occupied in batch {$batch->name}",
                    ], 422);
                }
            }

            // Re-use an existing member (by phone, within this library) instead of
            // creating a duplicate when someone adds another batch to a student who
            // already has one, or rejoins after being marked inactive.
            $member = Member::where('library_id', $library->id)
                ->where('phone', $request->phone)
                ->first();

            if ($member) {
                $requestedBatchIds = collect($request->allocations)->pluck('batch_id');
                $alreadyAllocatedBatch = $member->allocations()
                    ->where('status', 'active')
                    ->whereIn('batch_id', $requestedBatchIds)
                    ->with('batch')
                    ->first();

                if ($alreadyAllocatedBatch) {
                    return response()->json([
                        'status' => false,
                        'message' => "{$member->name} already has an active allocation in {$alreadyAllocatedBatch->batch->name}",
                    ], 422);
                }

                $member->update(['name' => $request->name, 'gender' => $request->gender ?? $member->gender, 'status' => 'active']);
            } else {
                $member = Member::create([
                    'library_id' => $library->id,
                    'name' => $request->name,
                    'phone' => $request->phone,
                    'gender' => $request->gender,
                    'status' => 'active',
                ]);
            }

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
                'data' => $member->load('allocations'),
            ]);
        });
    }

    public function show(Request $request, int $id)
    {
        $library = $this->library($request);

        $member = Member::where('library_id', $library->id)
            ->with(['allocations' => function ($query) {
                $query->where('status', 'active')->with(['batch', 'seat']);
            }])
            ->find($id);

        if (! $member) {
            return response()->json(['status' => false, 'message' => 'Member not found'], 404);
        }

        $due = $this->dues->overdueMembers($library->id, now())->get($member->id);

        return response()->json([
            'status' => true,
            'data' => [
                'id' => $member->id,
                'name' => $member->name,
                'phone' => $member->phone,
                'gender' => $member->gender,
                'status' => $member->status,
                'payment_status' => $due ? 'Due' : 'Paid',
                'due_amount' => $due?->amount,
                'id_proof_type' => $member->id_proof_type,
                'has_id_proof_front' => (bool) $member->id_proof_front_path,
                'has_id_proof_back' => (bool) $member->id_proof_back_path,
                'allocations' => $member->allocations->map(function ($allocation) {
                    return [
                        'batch_id' => $allocation->batch_id,
                        'batch_name' => $allocation->batch->name,
                        'seat_id' => $allocation->seat_id,
                        'seat_number' => $allocation->seat->seat_number,
                        'renewal_date' => $allocation->renewal_date,
                    ];
                }),
            ],
        ]);
    }

    public function update(Request $request, int $id)
    {
        $library = $this->library($request);
        $member = Member::where('library_id', $library->id)->find($id);

        if (! $member) {
            return response()->json(['status' => false, 'message' => 'Member not found'], 404);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'phone' => [
                'required',
                'regex:/^[6-9]\d{9}$/',
                Rule::unique('members', 'phone')->where('library_id', $library->id)->ignore($member->id),
            ],
            'gender' => ['nullable', Rule::in(['male', 'female', 'other'])],
        ]);

        // Phone is the key admission() uses to recognize a rejoining member and reactivate
        // their existing record instead of creating a duplicate - it must stay stable once
        // they've left, or that match breaks (for them, and for whoever gets that number next).
        if ($member->status === 'inactive' && $validated['phone'] !== $member->phone) {
            return response()->json([
                'status' => false,
                'message' => "Phone number can't be changed for a member who has left - it's used to recognize them if they rejoin.",
            ], 422);
        }

        $member->update($validated);

        return response()->json([
            'status' => true,
            'message' => 'Member updated',
            'data' => ['id' => $member->id, 'name' => $member->name, 'phone' => $member->phone, 'gender' => $member->gender],
        ]);
    }

    public function updateIdProof(Request $request, int $id)
    {
        $library = $this->library($request);
        $member = Member::where('library_id', $library->id)->find($id);

        if (! $member) {
            return response()->json(['status' => false, 'message' => 'Member not found'], 404);
        }

        $validated = $request->validate([
            'id_proof_type' => ['required', Rule::in(['aadhaar', 'pan', 'voter_id_dl', 'student_id', 'other'])],
            'side' => ['required', Rule::in(['front', 'back'])],
            'id_proof' => ['required', 'image', 'mimes:jpg,jpeg,png', 'max:5120'],
        ]);

        $this->storeIdProof($library, $member, $request->file('id_proof'), $validated['id_proof_type'], $validated['side']);

        return response()->json(['status' => true, 'message' => 'ID proof saved']);
    }

    private function storeIdProof(Library $library, Member $member, UploadedFile $file, string $type, string $side): void
    {
        $column = $side === 'front' ? 'id_proof_front_path' : 'id_proof_back_path';

        if ($member->$column) {
            Storage::disk('local')->delete($member->$column);
        }

        $path = $file->store("id-proofs/{$library->id}", 'local');
        $member->update(['id_proof_type' => $type, $column => $path]);
    }

    public function deactivate(Request $request, int $id)
    {
        $member = Member::where('library_id', $this->library($request)->id)->find($id);

        if (! $member) {
            return response()->json(['status' => false, 'message' => 'Member not found'], 404);
        }

        DB::transaction(function () use ($member) {
            $member->update(['status' => 'inactive']);
            $member->allocations()->where('status', 'active')->update(['status' => 'ended']);
        });

        return response()->json([
            'status' => true,
            'message' => 'Member marked as inactive and their seats were freed',
        ]);
    }

    public function idProof(Request $request, int $id, string $side)
    {
        if (! in_array($side, ['front', 'back'], true)) {
            return response()->json(['status' => false, 'message' => 'Invalid side'], 404);
        }

        $member = Member::where('library_id', $this->library($request)->id)->find($id);
        $column = $side === 'front' ? 'id_proof_front_path' : 'id_proof_back_path';

        if (! $member || ! $member->$column) {
            return response()->json(['status' => false, 'message' => 'No ID proof on file'], 404);
        }

        if (! Storage::disk('local')->exists($member->$column)) {
            return response()->json(['status' => false, 'message' => 'ID proof file is missing'], 404);
        }

        return Storage::disk('local')->response($member->$column);
    }

    public function remind(Request $request, int $id)
    {
        $library = $this->library($request);
        $member = Member::where('library_id', $library->id)->find($id);

        if (! $member) {
            return response()->json(['status' => false, 'message' => 'Member not found'], 404);
        }

        try {
            $this->reminders->remindMember($library, $member, now());
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'status' => false,
                'message' => 'Failed to send the reminder. Check the SMS provider configuration.',
            ], 502);
        }

        return response()->json(['status' => true, 'message' => 'Reminder sent']);
    }
}
