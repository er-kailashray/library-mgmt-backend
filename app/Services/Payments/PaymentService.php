<?php

namespace App\Services\Payments;

use App\Enums\PaymentStatus;
use App\Models\Library;
use App\Models\Payment;
use App\Repositories\Contracts\PaymentRepositoryInterface;
use App\Repositories\Contracts\SeatAllocationRepositoryInterface;
use App\Services\Dues\MemberDue;
use App\Services\Dues\MemberDueService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class PaymentService
{
    public function __construct(
        private readonly PaymentRepositoryInterface $payments,
        private readonly SeatAllocationRepositoryInterface $allocations,
        private readonly MemberDueService $dues,
    ) {}

    /**
     * Outstanding dues (PENDING) first, then recorded payments newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listForLibrary(Library $library, ?CarbonInterface $today = null): array
    {
        $today ??= now();

        $pending = $this->dues->overdueMembers($library->id, $today)
            ->map(fn (MemberDue $due) => [
                'id' => 'due-'.$due->memberId,
                'member_id' => $due->memberId,
                'member_name' => $due->memberName,
                'amount' => $due->amount,
                'date' => $due->oldestRenewalDate->toDateString(),
                'status' => PaymentStatus::Pending->label(),
                'method' => '',
                // Dues are only computed from active allocations, so these members are always active.
                'member_status' => 'active',
            ]);

        $recorded = $this->payments->allForLibrary($library->id)
            ->map(fn (Payment $payment) => $this->present($payment));

        return $pending->values()->concat($recorded)->all();
    }

    /**
     * Records a payment. A payment covering the member's full monthly fee is
     * PAID and pushes their renewal dates forward one cycle; anything less is
     * PARTIAL and leaves renewal dates untouched.
     */
    public function record(Library $library, array $data): Payment
    {
        return DB::transaction(function () use ($library, $data) {
            $monthlyFee = (float) $this->allocations->activeForMember($data['member_id'])
                ->sum(fn ($allocation) => $allocation->batch->monthly_fee);

            $status = $data['amount'] >= $monthlyFee ? PaymentStatus::Paid : PaymentStatus::Partial;

            $payment = $this->payments->create([
                'library_id' => $library->id,
                'member_id' => $data['member_id'],
                'amount' => $data['amount'],
                'status' => $status,
                'payment_date' => $data['payment_date'] ?? now()->toDateString(),
                'payment_mode' => $data['payment_mode'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            if ($status === PaymentStatus::Paid) {
                $this->allocations->extendRenewalDates($data['member_id'], 1);
            }

            return $payment->load('member');
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function present(Payment $payment): array
    {
        return [
            'id' => (string) $payment->id,
            'member_id' => $payment->member_id,
            'member_name' => $payment->member->name,
            'member_status' => $payment->member->status,
            'amount' => $payment->amount,
            'date' => $payment->payment_date->toDateString(),
            'status' => $payment->status->label(),
            'method' => config("library.payment_modes.{$payment->payment_mode}")
                ?? config('library.payment_mode_fallback_label'),
        ];
    }
}
