<?php

namespace App\Services\Dashboard;

use App\Models\Library;
use App\Services\Dashboard\Contracts\DashboardMetric;
use Carbon\CarbonInterface;

class DashboardService
{
    /**
     * @param  iterable<DashboardMetric>  $metrics
     */
    public function __construct(
        private readonly iterable $metrics,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Library $library, ?CarbonInterface $today = null): array
    {
        $today ??= now();
        $data = [];

        foreach ($this->metrics as $metric) {
            $data = array_merge($data, $metric->compute($library, $today));
        }

        return $data;
    }
}
