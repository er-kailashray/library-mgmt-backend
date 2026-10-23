<?php

namespace App\Repositories\Eloquent;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Repositories\Contracts\PaymentRepositoryInterface;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class PaymentRepository implements PaymentRepositoryInterface
{
    public function sumBetween(int $libraryId, CarbonInterface $from, CarbonInterface $to): float
    {
        return (float) Payment::where('library_id', $libraryId)
            ->whereDate('payment_date', '>=', $from->toDateString())
            ->whereDate('payment_date', '<=', $to->toDateString())
            ->sum('amount');
    }

    public function countBetween(int $libraryId, CarbonInterface $from, CarbonInterface $to): int
    {
        return Payment::where('library_id', $libraryId)
            ->whereDate('payment_date', '>=', $from->toDateString())
            ->whereDate('payment_date', '<=', $to->toDateString())
            ->count();
    }

    public function recent(int $libraryId, int $limit): Collection
    {
        return Payment::where('library_id', $libraryId)
            ->with(['member.allocations' => fn ($query) => $query->orderByRaw("status = 'active' desc")->with('seat')])
            ->latest()
            ->limit($limit)
            ->get();
    }

    public function allForLibrary(int $libraryId): Collection
    {
        return Payment::where('library_id', $libraryId)
            ->with('member')
            ->orderByDesc('payment_date')
            ->orderByDesc('id')
            ->get();
    }

    public function unsettledPartialTotals(array $memberIds): Collection
    {
        if ($memberIds === []) {
            return collect();
        }

        // Ordered by id (recording order), not payment_date, since payments can be back-dated.
        return Payment::whereIn('member_id', $memberIds)
            ->where('status', PaymentStatus::Partial)
            ->whereRaw(
                'id > coalesce((select max(settled.id) from payments as settled where settled.member_id = payments.member_id and settled.status = ?), 0)',
                [PaymentStatus::Paid->value]
            )
            ->groupBy('member_id')
            ->selectRaw('member_id, sum(amount) as total')
            ->pluck('total', 'member_id')
            ->map(fn ($total) => (float) $total);
    }

    public function create(array $attributes): Payment
    {
        return Payment::create($attributes);
    }
}
