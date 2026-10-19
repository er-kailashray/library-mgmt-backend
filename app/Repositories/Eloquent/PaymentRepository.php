<?php

namespace App\Repositories\Eloquent;

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

    public function create(array $attributes): Payment
    {
        return Payment::create($attributes);
    }
}
