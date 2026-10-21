<?php

namespace App\Http\Controllers;

use App\Enums\PaymentStatus;
use App\Models\Batch;
use App\Models\Library;
use App\Models\Member;
use App\Models\Payment;
use App\Models\Seat;
use App\Models\SeatAllocation;
use App\Services\Dues\MemberDueService;
use App\Services\Payments\PaymentService;
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
        private readonly PaymentService $payments,
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

        // Everyone, including members who left (so they can be found and readmitted); active first.
        $members = Member::where('library_id', $library->id)
            ->orderByRaw("status = 'active' desc")
            ->orderBy('id')
            ->with(['allocations' => function ($query) use ($library) {
                $query->where('library_id', $library->id)
                    ->where('status', 'active');
            }])
            ->get();

        $data = $members->map(function ($member) {
            // Determine payment status
            $isDue = $member->allocations->contains(function ($allocation) {
                return $allocation->renewal_date <= now()->toDateString();
            });

            return [
                'id' => $member->id,
                'name' => $member->name,
                'phone' => $member->phone,
                'status' => $member->status,
                'payment_status' => $isDue ? 'Due' : 'Paid',
                'active_allocations_count' => $member->allocations->count(),
                'outstanding_balance' => $member->outstanding_balance,
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
            // First payment taken at the desk; omit it for "pay later".
            'payment' => 'nullable|array',
            'payment.amount' => 'required_with:payment|numeric|gt:0',
            'payment.payment_mode' => ['nullable', Rule::in(array_keys(config('library.payment_modes')))],
            // Readmitting someone who left owing money: how much of it is collected now (0 = waive all).
            'previous_dues_paid' => 'nullable|numeric|min:0',
            'previous_dues_mode' => ['nullable', Rule::in(array_keys(config('library.payment_modes')))],
        ]);

        $library = auth()->user()->libraries()->first();

        if (! $library) {
            return response()->json([
                'status' => false,
                'message' => 'Library not found',
            ], 404);
        }

        return DB::transaction(function () use ($request, $library) {

            $monthlyFee = 0.0;

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

                $monthlyFee += (float) $batch->monthly_fee;

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

                if ($member->outstanding_balance > 0) {
                    $outstanding = $member->outstanding_balance;
                    $collected = $request->input('previous_dues_paid');

                    if ($collected === null) {
                        return response()->json([
                            'status' => false,
                            'message' => "{$member->name} left with ₹".number_format($outstanding).' pending - choose to collect it or waive it.',
                        ], 422);
                    }

                    if ((float) $collected > $outstanding) {
                        return response()->json([
                            'status' => false,
                            'message' => 'Previous dues collected can\'t be more than ₹'.number_format($outstanding).'.',
                        ], 422);
                    }

                    // Recorded before the admission payment so it doesn't count toward the new cycle.
                    if ((float) $collected > 0) {
                        $waived = $outstanding - (float) $collected;
                        Payment::create([
                            'library_id' => $library->id,
                            'member_id' => $member->id,
                            'amount' => (float) $collected,
                            'status' => PaymentStatus::Paid,
                            'payment_date' => now()->toDateString(),
                            'payment_mode' => $request->input('previous_dues_mode'),
                            'notes' => 'Previous dues settled'.($waived > 0 ? ' (₹'.number_format($waived).' waived)' : ''),
                        ]);
                    }
                }

                $member->update([
                    'name' => $request->name,
                    'gender' => $request->gender ?? $member->gender,
                    'status' => 'active',
                    'outstanding_balance' => 0,
                ]);
            } else {
                $member = Member::create([
                    'library_id' => $library->id,
                    'name' => $request->name,
                    'phone' => $request->phone,
                    'gender' => $request->gender,
                    'status' => 'active',
                ]);
            }

            // renewal_date is the paid-through date. A full first payment covers one month; otherwise
            // the member is due from today, so unpaid / part-paid admissions show up in dues straight away.
            $paidAmount = (float) $request->input('payment.amount', 0);
            $fullyPaid = $paidAmount >= $monthlyFee;
            $renewalDate = ($fullyPaid ? now()->addMonthNoOverflow() : now())->toDateString();

            if ($request->filled('payment')) {
                Payment::create([
                    'library_id' => $library->id,
                    'member_id' => $member->id,
                    'amount' => $paidAmount,
                    'status' => $fullyPaid ? PaymentStatus::Paid : PaymentStatus::Partial,
                    'payment_date' => now()->toDateString(),
                    'payment_mode' => $request->input('payment.payment_mode'),
                ]);
            }

            // Create allocations
            foreach ($request->allocations as $allocation) {

                SeatAllocation::create([
                    'library_id' => $library->id,
                    'member_id' => $member->id,
                    'batch_id' => $allocation['batch_id'],
                    'seat_id' => $allocation['seat_id'],
                    'start_date' => now()->toDateString(),
                    'renewal_date' => $renewalDate,
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
        $monthlyFee = (float) $member->allocations->sum(fn ($allocation) => $allocation->batch->monthly_fee);

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
                // Owed when they left (inactive members only); settled on readmission.
                'outstanding_balance' => $member->outstanding_balance,
                'monthly_fee' => $monthlyFee,
                // Pre-fills "Record Payment / Renew"; null for members with no active seat.
                'suggested_payment' => $member->allocations->isEmpty() ? null : $this->payments->suggestedRenewalAmount($member, $monthlyFee, $due),
                'payments' => $this->payments->historyForMember($member),
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

    /**
     * Removes one side of a member's ID proof, or the whole thing (both sides and the type)
     * when no side is given.
     */
    public function deleteIdProof(Request $request, int $id, ?string $side = null)
    {
        if ($side !== null && ! in_array($side, ['front', 'back'], true)) {
            return response()->json(['status' => false, 'message' => 'Invalid side'], 404);
        }

        $member = Member::where('library_id', $this->library($request)->id)->find($id);

        if (! $member) {
            return response()->json(['status' => false, 'message' => 'Member not found'], 404);
        }

        $columns = match ($side) {
            'front' => ['id_proof_front_path'],
            'back' => ['id_proof_back_path'],
            default => ['id_proof_front_path', 'id_proof_back_path'],
        };

        $changes = [];
        foreach ($columns as $column) {
            if ($member->$column) {
                Storage::disk('local')->delete($member->$column);
            }
            $changes[$column] = null;
        }

        if ($side === null) {
            $changes['id_proof_type'] = null;
        }

        $member->update($changes);

        return response()->json(['status' => true, 'message' => 'ID proof removed']);
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

        // Ended seats stop accruing dues, so freeze what's owed now; it's settled if they're readmitted.
        $owed = $this->dues->overdueMembers($member->library_id, now())->get($member->id)?->amount ?? 0.0;

        DB::transaction(function () use ($member, $owed) {
            $member->update(['status' => 'inactive', 'outstanding_balance' => $owed]);
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
