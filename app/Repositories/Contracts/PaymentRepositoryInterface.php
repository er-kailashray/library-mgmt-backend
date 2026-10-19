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

    public function create(array $attributes): Payment;
}
