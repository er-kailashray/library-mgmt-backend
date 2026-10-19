<?php

namespace App\Services\Dashboard\Contracts;

use App\Models\Library;
use Carbon\CarbonInterface;

/**
 * One self-contained section of the dashboard payload. Each metric returns
 * the top-level keys it owns; DashboardService merges them. To add a new
 * widget, implement this interface and register it in DomainServiceProvider.
 */
interface DashboardMetric
{
    /**
     * @return array<string, mixed>
     */
    public function compute(Library $library, CarbonInterface $today): array;
}
