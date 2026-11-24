<?php

namespace App\Providers\Analytics;

use App\Models\Website;

interface SearchPerformanceProvider
{
    /**
     * @return list<array{date: string, page_url: ?string, query: ?string, clicks: int, impressions: int, ctr: float, position: float}>
     */
    public function queryPageMetrics(Website $website, string $property, string $start, string $end): array;

    public function name(): string;
}
