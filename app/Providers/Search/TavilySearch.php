<?php

namespace App\Providers\Search;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TavilySearch implements WebSearchProvider
{
    public function __construct(private readonly string $apiKey) {}

    public function name(): string
    {
        return 'tavily';
    }

    public function search(string $query, int $limit = 10): array
    {
        return Cache::remember('tavily:'.md5($query.$limit), now()->addDays(7), function () use ($query, $limit) {
            $response = Http::timeout(30)->withToken($this->apiKey)->post('https://api.tavily.com/search', [
                'query' => $query, 'max_results' => min($limit, 20), 'search_depth' => 'basic',
            ]);
            if ($response->status() === 429 || $response->status() === 432) {
                throw new RuntimeException('Tavily free monthly searches are used up.');
            }
            if ($response->failed()) {
                throw new RuntimeException('Tavily search failed (HTTP '.$response->status().'). Check the key in settings.');
            }

            return collect($response->json('results', []))->map(fn ($r) => [
                'url' => (string) ($r['url'] ?? ''), 'title' => (string) ($r['title'] ?? ''), 'snippet' => mb_substr((string) ($r['content'] ?? ''), 0, 300),
            ])->filter(fn ($r) => $r['url'] !== '')->values()->all();
        });
    }
}
