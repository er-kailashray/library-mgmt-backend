<?php

namespace App\Services\Dues;

use Carbon\CarbonInterface;

/**
 * One member's nearest not-yet-due renewal, and what it'll cost - as opposed
 * to MemberDue, which is already overdue.
 */
final class UpcomingRenewal
{
    public function __construct(
        public readonly int $memberId,
        public readonly string $memberName,
        public readonly string $memberPhone,
        public readonly float $amount,
        public readonly CarbonInterface $renewalDate,
        public readonly int $daysUntilRenewal,
    ) {}
}
