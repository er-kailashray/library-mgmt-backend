<?php

namespace App\Repositories\Contracts;

use App\Models\Payment;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

interface PaymentRepositoryInterface
{
    /**
     * Total amount received with payment_date within [$from, $to].
     */
    public function sumBetween(int $libraryId, CarbonInterface $from, CarbonInterface $to): float;

    /**
     * Number of payments with payment_date within [$from, $to].
     */
    public function countBetween(int $libraryId, CarbonInterface $from, CarbonInterface $to): int;

    /**
     * Most recently recorded payments, with member and the member's seats loaded.
     *
     * @return Collection<int, Payment>
     */
    public function recent(int $libraryId, int $limit): Collection;

    /**
     * All payments for a library, newest first, with member loaded.
     *
     * @return Collection<int, Payment>
     */
    public function allForLibrary(int $libraryId): Collection;

    /**
     * Partial payments each member has made since their last full ("paid") payment - money already
     * received toward the cycle that's still open, so it should come off what they owe.
     *
     * @param  array<int, int>  $memberIds
     * @return Collection<int, float> keyed by member id; members with no such payments are omitted
     */
    public function unsettledPartialTotals(array $memberIds): Collection;

    public function create(array $attributes): Payment;
}
