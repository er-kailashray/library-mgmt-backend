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
use App\Services\Dues\MonthlyFeeService;
use App\Services\Payments\PaymentService;
use App\Services\Reminders\ReminderService;
use Carbon\Carbon;
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
                    ->where('status', 'active')
                    ->with(['batch', 'seat']);
            }])
            ->withMax('allocations', 'ended_on')
            ->get();
        // Same dues as Home and the member page, so the amounts on the list always agree.
        $overdue = $this->dues->overdueMembers($library->id, now());

        $data = $members->map(function ($member) use ($overdue) {
            $due = $overdue->get($member->id);
            $nextRenewal = $member->allocations->min('renewal_date');

            // Determine payment status
            $isDue = $member->allocations->contains(function ($allocation) {
                return $allocation->renewal_date <= now()->toDateString();
            });

            return [
                'id' => $member->id,
                'name' => $member->name,
                'phone' => $member->phone,
                'join_date' => $member->joinedOn(),
                'status' => $member->status,
                'payment_status' => $isDue ? 'Due' : 'Paid',
                'active_allocations_count' => $member->allocations->count(),
                // Where they sit now: one entry per batch, in the day's order.
                'seats' => $member->allocations
                    ->sortBy(fn ($allocation) => $allocation->batch?->start_time)
                    ->map(fn ($allocation) => [
                        'batch_id' => $allocation->batch_id,
                        'batch_name' => $allocation->batch?->name,
                        'start_time' => $allocation->batch?->start_time,
                        'end_time' => $allocation->batch?->end_time,
                        'seat_number' => $allocation->seat?->seat_number,
                    ])
                    ->values(),
                // What the list shows on the right: what they owe and for how long, else when they renew.
                'due_amount' => $due?->amount,
                'days_overdue' => $due?->daysOverdue,
                'next_renewal_date' => $nextRenewal ? substr((string) $nextRenewal, 0, 10) : null,
                // When a member who left last held a seat.
                'left_on' => $member->status === 'inactive' && $member->allocations_max_ended_on
                    ? substr((string) $member->allocations_max_ended_on, 0, 10)
                    : null,
                'outstanding_balance' => $member->outstanding_balance,
            ];
        });

        return response()->json([
            'status' => true,
            'data' => $data,
        ]);
    }

    /** How far back a joining date may go, for entering members from before the app. */
    private const JOIN_DATE_MAX_YEARS_BACK = 5;

    /** How far ahead a joining date may be, for a seat booked to start soon. */
    private const JOIN_DATE_MAX_DAYS_AHEAD = 31;

    public function admission(Request $request)
    {
        // Needed first: the seats and batches picked must belong to this library (and not be deleted).
        $library = auth()->user()->libraries()->first();

        if (! $library) {
            return response()->json([
                'status' => false,
                'message' => 'Library not found',
            ], 404);
        }

        $request->validate([
            'name' => 'required|string|max:100',
            'phone' => 'required|regex:/^[6-9]\d{9}$/',
            'gender' => ['nullable', Rule::in(['male', 'female', 'other'])],
            'address' => 'nullable|string|max:500',
            // When they started. Defaults to today; earlier dates are for entering existing members.
            'join_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.now()->subYears(self::JOIN_DATE_MAX_YEARS_BACK)->toDateString(), 'before_or_equal:'.now()->addDays(self::JOIN_DATE_MAX_DAYS_AHEAD)->toDateString()],
            // Existing members: the last day already paid for before using the app. Not recorded as money
            // received today - it only sets how far they're covered.
            'paid_until' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.now()->addYear()->toDateString()],
            'allocations' => 'required|array|min:1',
            'allocations.*.batch_id' => ['required', Rule::exists('batches', 'id')->where('library_id', $library->id)->whereNull('deleted_at')],
            'allocations.*.seat_id' => ['required', Rule::exists('seats', 'id')->where('library_id', $library->id)->whereNull('deleted_at')],
            // First payment taken at the desk; omit it for "pay later".
            'payment' => 'nullable|array',
            'payment.amount' => 'required_with:payment|numeric|gt:0',
            'payment.payment_mode' => ['nullable', Rule::in(array_keys(config('library.payment_modes')))],
            // Readmitting someone who left owing money: how much of it is collected now (0 = waive all).
            'previous_dues_paid' => 'nullable|numeric|min:0',
            'previous_dues_mode' => ['nullable', Rule::in(array_keys(config('library.payment_modes')))],
        ]);

        $joinDate = $request->filled('join_date') ? Carbon::parse($request->join_date)->startOfDay() : now()->startOfDay();
        $paidUntil = $request->filled('paid_until') ? Carbon::parse($request->paid_until)->startOfDay() : null;
        if ($paidUntil && $paidUntil->lt($joinDate)) {
            return response()->json([
                'status' => false,
                'message' => "\"Paid till\" can't be before the joining date.",
                'errors' => ['paid_until' => ["\"Paid till\" can't be before the joining date."]],
            ], 422);
        }

        return DB::transaction(function () use ($request, $library, $joinDate, $paidUntil) {

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
                            'seats' => PaymentService::seatSnapshot($this->lastSeats($member)),
                            'notes' => MonthlyFeeService::SETTLEMENT_NOTE.($waived > 0 ? ' (₹'.number_format($waived).' waived)' : ''),
                        ]);
                    }
                }

                $member->update([
                    'name' => $request->name,
                    'gender' => $request->gender ?? $member->gender,
                    // Keep the address on file unless a new one was entered.
                    'address' => $request->filled('address') ? $request->address : $member->address,
                    'status' => 'active',
                    'outstanding_balance' => 0,
                ]);
            } else {
                $member = Member::create([
                    'library_id' => $library->id,
                    'join_date' => $joinDate->toDateString(),
                    'name' => $request->name,
                    'phone' => $request->phone,
                    'gender' => $request->gender,
                    'address' => $request->filled('address') ? $request->address : null,
                    'status' => 'active',
                ]);
            }

            // renewal_date is the paid-through date. Cover starts on the joining date, or the day after
            // "paid till" for members who'd already paid before using the app. Each full monthly fee taken
            // now covers one month from there; with less (or nothing) they're due from that day, so they
            // show up in dues straight away.
            $coverFrom = $paidUntil ? $paidUntil->copy()->addDay() : $joinDate->copy();
            $paidAmount = (float) $request->input('payment.amount', 0);
            $monthsCovered = $monthlyFee > 0 ? (int) floor($paidAmount / $monthlyFee + 1e-9) : ($paidAmount > 0 ? 1 : 0);
            $renewalDate = $coverFrom->copy()->addMonthsNoOverflow($monthsCovered)->toDateString();

            // Create allocations
            $newAllocations = [];
            foreach ($request->allocations as $allocation) {

                $newAllocations[] = SeatAllocation::create([
                    'library_id' => $library->id,
                    'member_id' => $member->id,
                    'batch_id' => $allocation['batch_id'],
                    'seat_id' => $allocation['seat_id'],
                    'start_date' => $joinDate->toDateString(),
                    'renewal_date' => $renewalDate,
                    'status' => 'active',
                ]);
            }

            // After the seats exist, so the payment can keep a copy of them.
            if ($request->filled('payment')) {
                Payment::create([
                    'library_id' => $library->id,
                    'member_id' => $member->id,
                    'amount' => $paidAmount,
                    'status' => $monthsCovered >= 1 ? PaymentStatus::Paid : PaymentStatus::Partial,
                    // Money taken today, whatever the joining date.
                    'payment_date' => now()->toDateString(),
                    'period_start' => $coverFrom->toDateString(),
                    'period_end' => $coverFrom->copy()->addMonthsNoOverflow(max(1, $monthsCovered))->subDay()->toDateString(),
                    'seats' => PaymentService::seatSnapshot(collect($newAllocations)->each->load(['batch', 'seat'])),
                    'payment_mode' => $request->input('payment.payment_mode'),
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
                'address' => $member->address,
                'join_date' => $member->joinedOn(),
                'status' => $member->status,
                'payment_status' => $due ? 'Due' : 'Paid',
                'due_amount' => $due?->amount,
                // Owed when they left (inactive members only); settled on readmission.
                'outstanding_balance' => $member->outstanding_balance,
                'monthly_fee' => $monthlyFee,
                // Pre-fills "Record Payment / Renew"; null for members with no active seat.
                'suggested_payment' => $member->allocations->isEmpty() ? null : $this->payments->suggestedRenewalAmount($member, $monthlyFee, $due),
                // Paid up to (not including) this date; a new payment's period starts here.
                'paid_through' => $this->payments->paidThrough($member->allocations)?->toDateString(),
                // Earlier part-payments that count toward the next full fee.
                'partial_credit' => $this->payments->partialCredit($member),
                // What "Mark as left" can offer back; null when no paid days are left.
                'refund_options' => $member->allocations->isEmpty() ? null : $this->payments->refundOptions($member, $monthlyFee),
                'payments' => $this->payments->historyForMember($member),
                // Every seat they've had, newest first - including ones that ended or whose batch was deleted.
                'seat_history' => $member->allocations()
                    ->with(['batch', 'seat'])
                    ->orderByDesc('start_date')
                    ->orderByDesc('id')
                    ->get()
                    ->map(fn (SeatAllocation $allocation) => [
                        'batch_name' => $allocation->batch?->name,
                        'seat_number' => $allocation->seat?->seat_number,
                        'start_date' => substr((string) $allocation->start_date, 0, 10),
                        'ended_on' => $allocation->ended_on?->toDateString(),
                        'status' => $allocation->status,
                    ])
                    ->all(),
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
            'address' => 'nullable|string|max:500',
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

        $request->validate([
            'refund_amount' => 'nullable|numeric|gt:0',
            'refund_mode' => ['nullable', Rule::in(array_keys(config('library.payment_modes')))],
        ]);

        // Ended seats stop accruing dues, so freeze what's owed now; it's settled if they're readmitted.
        $owed = $this->dues->overdueMembers($member->library_id, now())->get($member->id)?->amount ?? 0.0;

        // Giving money back for paid days they won't use - never more than they paid ahead.
        $refund = (float) $request->input('refund_amount', 0);
        $options = null;
        if ($refund > 0) {
            $monthlyFee = (float) $member->allocations()->where('status', 'active')->with('batch')->get()->sum(fn ($allocation) => $allocation->batch->monthly_fee);
            $options = $this->payments->refundOptions($member, $monthlyFee);
            if (! $options) {
                return response()->json(['status' => false, 'message' => "There's no unused paid time to refund."], 422);
            }
            if ($refund > $options['full']) {
                return response()->json(['status' => false, 'message' => 'The refund can be at most ₹'.number_format($options['full']).'.'], 422);
            }
        }

        DB::transaction(function () use ($request, $member, $owed, $refund, $options) {
            if ($refund > 0) {
                $this->payments->recordRefund($this->library($request), $member, $refund, $request->input('refund_mode'), \Carbon\Carbon::parse($options['paid_through']));
            }
            $member->update(['status' => 'inactive', 'outstanding_balance' => $owed]);
            $member->allocations()->where('status', 'active')->update(['status' => 'ended', 'ended_on' => now()->toDateString()]);
        });

        return response()->json([
            'status' => true,
            'message' => 'Member marked as inactive and their seats were freed',
        ]);
    }

    /**
     * Back after a break, paying later: restarts the membership on a chosen date. They're due from
     * that date; the days in between are never charged.
     */
    public function restart(Request $request, int $id)
    {
        $member = Member::where('library_id', $this->library($request)->id)->where('status', 'active')->find($id);

        if (! $member) {
            return response()->json(['status' => false, 'message' => 'Member not found'], 404);
        }

        $request->validate(['start_date' => 'required|date_format:Y-m-d']);
        $this->payments->restart($member, \Carbon\Carbon::parse($request->start_date));

        return response()->json(['status' => true, 'message' => 'Membership restarted']);
    }

    /**
     * The seats a member had when they last left: their most recently ended set.
     *
     * @return \Illuminate\Support\Collection<int, SeatAllocation>
     */
    private function lastSeats(Member $member)
    {
        $ended = $member->allocations()->where('status', 'ended')->with(['batch', 'seat'])->get();
        $latest = $ended->max(fn (SeatAllocation $allocation) => $allocation->ended_on?->toDateString() ?? (string) $allocation->renewal_date);

        return $ended->filter(fn (SeatAllocation $allocation) => ($allocation->ended_on?->toDateString() ?? (string) $allocation->renewal_date) === $latest)->values();
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
