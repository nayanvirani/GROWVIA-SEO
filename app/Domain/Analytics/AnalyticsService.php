<?php

namespace App\Domain\Analytics;

use App\Models\AnalyticsDaily;
use App\Models\Integration;
use App\Models\Website;
use App\Providers\Analytics\GoogleSearchConsoleProvider;
use App\Providers\Analytics\MockSearchPerformanceProvider;
use App\Providers\Analytics\SearchPerformanceProvider;
use App\Support\ActivityLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AnalyticsService
{
    public function providerFor(Website $website): array
    {
        $integration = Integration::where('workspace_id', $website->workspace_id)
            ->where('provider', 'search_console')->where('status', 'connected')
            ->where(fn ($q) => $q->where('website_id', $website->id)->orWhereNull('website_id'))
            ->first();

        if ($integration && config('seo.google.client_id')) {
            return [new GoogleSearchConsoleProvider($integration, config('seo.google')), $integration->metadata_json['property'] ?? $website->base_url];
        }

        return [new MockSearchPerformanceProvider, $website->base_url];
    }

    /** Sync a date window; Search Console data typically lags ~2-3 days. */
    public function sync(Website $website, int $days = 28, ?SearchPerformanceProvider $provider = null): int
    {
        [$defaultProvider, $property] = $this->providerFor($website);
        $provider ??= $defaultProvider;
        $end = now()->subDays(3)->toDateString();
        $start = now()->subDays(3 + $days - 1)->toDateString();

        $rows = $provider->queryPageMetrics($website, $property, $start, $end);
        ActivityLogger::usage($website, $provider->name(), 'analytics.sync', count($rows));

        DB::transaction(function () use ($website, $provider, $rows, $start, $end) {
            AnalyticsDaily::forWebsite($website)->where('source', $provider->name())->whereBetween('date', [$start, $end])->delete();
            foreach (array_chunk($rows, 500) as $chunk) {
                AnalyticsDaily::insert(array_map(fn ($r) => [
                    'id' => (string) Str::ulid(), 'workspace_id' => $website->workspace_id, 'website_id' => $website->id,
                    'date' => $r['date'], 'page_url' => Str::limit((string) $r['page_url'], 2040, ''), 'query' => Str::limit((string) $r['query'], 250, ''),
                    'clicks' => $r['clicks'], 'impressions' => $r['impressions'], 'ctr' => $r['ctr'], 'position' => $r['position'],
                    'source' => $provider->name(), 'created_at' => now(), 'updated_at' => now(),
                ], $chunk));
            }
        });

        return count($rows);
    }

    public function report(Website $website, string $start, string $end): array
    {
        $base = fn () => AnalyticsDaily::forWebsite($website)->whereBetween('date', [$start, $end]);
        $weighted = 'COALESCE(SUM(position * impressions) / NULLIF(SUM(impressions), 0), 0)';

        $totals = $base()->selectRaw("SUM(clicks) clicks, SUM(impressions) impressions, {$weighted} position")->first();
        $trend = $base()->selectRaw('date, SUM(clicks) clicks, SUM(impressions) impressions')->groupBy('date')->orderBy('date')->get()
            ->map(fn ($r) => ['date' => $r->date->toDateString(), 'clicks' => (int) $r->clicks, 'impressions' => (int) $r->impressions]);

        $agg = fn (string $dim) => $base()->selectRaw("{$dim} as label, SUM(clicks) clicks, SUM(impressions) impressions, {$weighted} position")
            ->whereNotNull($dim)->groupBy($dim)->orderByDesc('clicks')->limit(100)->get()
            ->map(fn ($r) => [
                'label' => $r->label, 'clicks' => (int) $r->clicks, 'impressions' => (int) $r->impressions,
                'ctr' => $r->impressions ? round($r->clicks / $r->impressions, 4) : 0, 'position' => round((float) $r->position, 1),
            ]);

        $queries = $agg('query');
        $pages = $agg('page_url');
        $medianCtr = $queries->pluck('ctr')->sort()->values()->get((int) floor($queries->count() / 2), 0);
        $opportunities = $queries->filter(fn ($q) => $q['impressions'] >= 100 && $q['ctr'] < $medianCtr * 0.5)->values();

        return [
            'totals' => [
                'clicks' => (int) $totals->clicks, 'impressions' => (int) $totals->impressions,
                'ctr' => $totals->impressions ? round($totals->clicks / $totals->impressions, 4) : 0,
                'position' => round((float) $totals->position, 1),
            ],
            'trend' => $trend,
            'queries' => $queries,
            'pages' => $pages,
            'opportunities' => $opportunities,
            'declines' => $this->declines($website, $start, $end),
            'source' => AnalyticsDaily::forWebsite($website)->latest('date')->value('source'),
        ];
    }

    /** Pages whose clicks fell by 30%+ between the two halves of the range. */
    private function declines(Website $website, string $start, string $end): array
    {
        $mid = now()->parse($start)->addDays((int) floor(now()->parse($start)->diffInDays($end) / 2))->toDateString();
        $sum = fn ($from, $to) => AnalyticsDaily::forWebsite($website)->whereBetween('date', [$from, $to])
            ->whereNotNull('page_url')->groupBy('page_url')->selectRaw('page_url, SUM(clicks) clicks')->pluck('clicks', 'page_url');
        $before = $sum($start, $mid);
        $after = $sum(now()->parse($mid)->addDay()->toDateString(), $end);

        return $before->map(fn ($c, $url) => ['page_url' => $url, 'before' => (int) $c, 'after' => (int) ($after[$url] ?? 0)])
            ->filter(fn ($r) => $r['before'] >= 10 && $r['after'] < $r['before'] * 0.7)->values()->all();
    }
}
