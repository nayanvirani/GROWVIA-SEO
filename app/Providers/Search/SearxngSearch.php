<?php

namespace App\Providers\Search;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SearxngSearch implements WebSearchProvider
{
    public function __construct(private readonly string $baseUrl) {}

    public function name(): string
    {
        return 'searxng';
    }

    public function search(string $query, int $limit = 10): array
    {
        return Cache::remember('searx:'.md5($this->baseUrl.$query), now()->addDays(7), function () use ($query, $limit) {
            $response = Http::timeout(30)->get(rtrim($this->baseUrl, '/').'/search', ['q' => $query, 'format' => 'json']);
            if ($response->failed()) {
                throw new RuntimeException('SearXNG search failed (HTTP '.$response->status().'). Is the JSON format enabled?');
            }

            return collect($response->json('results', []))->take($limit)->map(fn ($r) => [
                'url' => (string) ($r['url'] ?? ''), 'title' => (string) ($r['title'] ?? ''), 'snippet' => (string) ($r['content'] ?? ''),
            ])->filter(fn ($r) => $r['url'] !== '')->values()->all();
        });
    }
}
