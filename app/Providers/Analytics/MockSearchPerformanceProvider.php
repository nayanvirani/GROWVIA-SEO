<?php

namespace App\Providers\Analytics;

use App\Models\Website;
use Carbon\CarbonPeriod;

/** Deterministic Search Console-shaped fixture data for local development. */
class MockSearchPerformanceProvider implements SearchPerformanceProvider
{
    public function name(): string
    {
        return 'mock';
    }

    public function queryPageMetrics(Website $website, string $property, string $start, string $end): array
    {
        $pages = $website->pages()->where('status_code', 200)->limit(8)->pluck('canonical_url')->all()
            ?: [rtrim($website->base_url, '/').'/'];
        $queries = $website->keywords()->limit(10)->pluck('keyword')->all()
            ?: ['shopify upsell app', 'shopify free gift app', 'shopify conversion app'];

        $rows = [];
        foreach (CarbonPeriod::create($start, $end) as $i => $day) {
            foreach ($queries as $qi => $query) {
                $page = $pages[$qi % count($pages)];
                $h = crc32($query.$page);
                $trend = $qi % 4 === 3 ? max(0.3, 1 - $i * 0.02) : 1 + $i * 0.01;
                $impr = (int) round((($h % 120) + 20) * $trend);
                $ctr = (($h >> 4) % 80) / 1000 + ($qi === 1 ? 0.002 : 0.01);
                $clicks = (int) floor($impr * $ctr);
                $rows[] = [
                    'date' => $day->toDateString(),
                    'page_url' => $page,
                    'query' => $query,
                    'clicks' => $clicks,
                    'impressions' => $impr,
                    'ctr' => $impr ? round($clicks / $impr, 4) : 0,
                    'position' => round(3 + (($h >> 6) % 250) / 10, 2),
                ];
            }
        }

        return $rows;
    }
}
