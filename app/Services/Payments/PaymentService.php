<?php

namespace App\Services\Payments;

use App\Enums\PaymentStatus;
use App\Models\Library;
use App\Models\Member;
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
     * Records a payment. Together with any earlier partial payments, each full monthly fee it
     * covers pushes renewal dates forward one cycle (so paying two overdue months clears both)
     * and makes it PAID; anything short of one fee is PARTIAL and leaves renewal dates untouched.
     */
    public function record(Library $library, array $data): Payment
    {
        return DB::transaction(function () use ($library, $data) {
            $monthlyFee = (float) $this->allocations->activeForMember($data['member_id'])
                ->sum(fn ($allocation) => $allocation->batch->monthly_fee);

            // Earlier partial payments count toward this cycle, so 400 + 600 against a 1000 fee settles it.
            $alreadyPaid = $this->payments->unsettledPartialTotals([$data['member_id']])->get($data['member_id'], 0.0);
            $monthsCovered = $monthlyFee > 0 ? (int) floor(($data['amount'] + $alreadyPaid) / $monthlyFee) : 1;
            $status = $monthsCovered >= 1 ? PaymentStatus::Paid : PaymentStatus::Partial;

            $payment = $this->payments->create([
                'library_id' => $library->id,
                'member_id' => $data['member_id'],
                'amount' => $data['amount'],
                'status' => $status,
                'payment_date' => $data['payment_date'] ?? now()->toDateString(),
                'payment_mode' => $data['payment_mode'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            if ($monthsCovered >= 1) {
                $this->allocations->extendRenewalDates($data['member_id'], $monthsCovered);
            }

            return $payment->load('member');
        });
    }

    /**
     * One member's payments, newest first, in the same shape as the library-wide list.
     *
     * @return array<int, array<string, mixed>>
     */
    public function historyForMember(Member $member): array
    {
        return $member->payments()
            ->orderByDesc('payment_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Payment $payment) => $this->present($payment->setRelation('member', $member)))
            ->all();
    }

    /**
     * What to collect to renew a member right now: their overdue amount if they're behind,
     * otherwise next month's fee less anything already paid toward it.
     */
    public function suggestedRenewalAmount(Member $member, float $monthlyFee, ?MemberDue $due): float
    {
        if ($due) {
            return $due->amount;
        }

        $alreadyPaid = $this->payments->unsettledPartialTotals([$member->id])->get($member->id, 0.0);

        return max(0.0, $monthlyFee - $alreadyPaid);
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
