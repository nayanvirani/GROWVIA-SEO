<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['seo.crawler.token' => 'test-token', 'seo.crawler.base_url' => 'http://crawler.test', 'seo.ai.provider' => 'mock', 'seo.seo_data.provider' => 'mock']);
    }

    protected function userWithWebsite(array $attributes = []): array
    {
        $user = User::factory()->create();
        $website = $user->currentWorkspace->websites()->create($attributes + [
            'name' => 'Growvia',
            'base_url' => 'https://site.test/',
            'normalized_host' => 'site.test',
            'ownership_confirmed' => true,
        ])->refresh();

        return [$user, $website];
    }

    /** Build a crawler-service page record. */
    protected function crawledPage(string $path, array $overrides = []): array
    {
        return array_merge([
            'url' => 'https://site.test'.$path,
            'final_url' => 'https://site.test'.$path,
            'status_code' => 200,
            'content_type' => 'text/html; charset=utf-8',
            'title' => 'A perfectly reasonable page title '.$path,
            'meta_description' => 'A useful description for '.$path.' that is long enough to pass the length heuristic check.',
            'robots_meta' => null,
            'canonical' => 'https://site.test'.$path,
            'h1' => ['Heading '.$path],
            'headings' => ['h2' => [], 'h3' => []],
            'links' => [],
            'images' => [],
            'word_count' => 600,
            'content_hash' => md5($path),
            'text_excerpt' => 'Text for '.$path,
            'depth' => substr_count(trim($path, '/'), '/') + ($path === '/' ? 0 : 1),
            'redirect_chain' => [],
            'error' => null,
        ], $overrides);
    }

    protected function fakeCrawler(array $pages, string $status = 'completed', array $sitemaps = ['https://site.test/sitemap.xml']): void
    {
        Http::fake([
            'crawler.test/internal/v1/crawls' => Http::response(['crawl_id' => 'crawl_1', 'status' => 'accepted'], 202),
            'crawler.test/internal/v1/crawls/crawl_1/results*' => Http::response([
                'status' => $status, 'pages' => $pages, 'next_cursor' => count($pages), 'done' => true,
                'errors' => [], 'sitemaps' => $sitemaps, 'pages_found' => count($pages),
            ]),
        ]);
    }
}
