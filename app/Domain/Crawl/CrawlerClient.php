<?php

namespace App\Domain\Crawl;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Authenticated client for the internal Python crawler service (section 15.1). */
class CrawlerClient
{
    public function start(array $payload): string
    {
        $response = $this->http()->post('/internal/v1/crawls', $payload);
        if ($response->failed() || ! $response->json('crawl_id')) {
            throw new RuntimeException('Crawler rejected the request: '.($response->json('detail') ?? 'HTTP '.$response->status()));
        }

        return (string) $response->json('crawl_id');
    }

    /** @return array{status: string, pages: array, next_cursor: int, done: bool, errors: array, sitemaps: array, pages_found: int} */
    public function results(string $crawlId, int $cursor, int $limit = 50): array
    {
        $response = $this->http()->get("/internal/v1/crawls/{$crawlId}/results", ['cursor' => $cursor, 'limit' => $limit]);
        if ($response->status() === 404) {
            throw new RuntimeException('Crawl not found on crawler service (it may have restarted).');
        }
        if ($response->failed()) {
            throw new RuntimeException('Crawler results request failed: HTTP '.$response->status());
        }

        return $response->json();
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(config('seo.crawler.base_url'))
            ->withToken((string) config('seo.crawler.token'))
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(30);
    }
}
