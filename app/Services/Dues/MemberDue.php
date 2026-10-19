<?php

namespace App\Services\Dues;

use Carbon\CarbonInterface;

/**
 * What one member currently owes across all of their overdue allocations.
 */
final class MemberDue
{
    public function __construct(
        public readonly int $memberId,
        public readonly string $memberName,
        public readonly float $amount,
        public readonly CarbonInterface $oldestRenewalDate,
        public readonly int $daysOverdue,
        public readonly int $cyclesOverdue,
    ) {}
}
