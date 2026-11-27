<?php

namespace Tests\Feature;

use App\Models\CrawlRun;
use App\Models\SeoIssue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CrawlAuditTest extends TestCase
{
    use RefreshDatabase;

    private function fixtureSite(): array
    {
        return [
            $this->crawledPage('/', ['links' => [
                ['url' => 'https://site.test/about', 'anchor' => 'About'],
                ['url' => 'https://site.test/missing', 'anchor' => 'Broken'],
                ['url' => 'https://site.test/old', 'anchor' => 'Old'],
                ['url' => 'https://elsewhere.com/', 'anchor' => 'Out', 'rel' => 'nofollow'],
            ], 'images' => [['src' => 'https://site.test/a.png', 'alt' => null], ['src' => 'https://site.test/b.png', 'alt' => '']]]),
            $this->crawledPage('/about', ['title' => null, 'meta_description' => null, 'h1' => [], 'canonical' => null]),
            $this->crawledPage('/dup-1', ['title' => 'Same title for two pages', 'h1' => ['A', 'B']]),
            $this->crawledPage('/dup-2', ['title' => 'Same title for two pages', 'robots_meta' => 'noindex']),
            $this->crawledPage('/thin', ['word_count' => 40, 'canonical' => 'https://other-site.com/thin']),
            $this->crawledPage('/missing', ['status_code' => 404, 'title' => null]),
            $this->crawledPage('/old', ['final_url' => 'https://site.test/new', 'redirect_chain' => [['url' => 'https://site.test/old', 'status' => 301], ['url' => 'https://site.test/older', 'status' => 301]]]),
        ];
    }

    public function test_crawl_stores_pages_and_creates_evidence_backed_issues()
    {
        [$user, $website] = $this->userWithWebsite();
        $this->fakeCrawler($this->fixtureSite());

        $this->actingAs($user)->post(route('audit.crawl', $website))->assertRedirect();

        $run = CrawlRun::first();
        $this->assertSame('completed', $run->status);
        $this->assertSame(7, $run->pages_crawled);
        $this->assertSame(7, $website->pages()->count());
        Http::assertSent(fn ($r) => $r->url() === 'http://crawler.test/internal/v1/crawls'
            && $r->hasHeader('Authorization', 'Bearer test-token') && $r['allowed_host'] === 'site.test' && $r['max_pages'] === 300);

        $rules = SeoIssue::where('status', 'open')->pluck('rule_key')->countBy();
        foreach (['missing_title', 'missing_meta_description', 'missing_h1', 'missing_canonical', 'multiple_h1', 'noindex_page',
            'canonical_mismatch', 'thin_content_signal', 'http_error', 'broken_internal_link', 'redirect_chain', 'missing_image_alt'] as $rule) {
            $this->assertArrayHasKey($rule, $rules, "Expected rule {$rule}");
        }
        // noindex page is excluded from duplicate checks, so only one indexable page has the title: no duplicate.
        $this->assertArrayNotHasKey('duplicate_title', $rules);

        $broken = SeoIssue::where('rule_key', 'broken_internal_link')->first();
        $this->assertSame('https://site.test/missing', $broken->evidence_json['target_url']);
        $this->assertSame(404, $broken->evidence_json['status_code']);
        $this->assertSame(1, SeoIssue::where('rule_key', 'missing_image_alt')->first()->evidence_json['count'], 'empty alt is valid');

        $this->assertSame(404, $website->pages()->where('canonical_url', 'https://site.test/')->first()->links()->where('target_url', 'https://site.test/missing')->value('http_status'));
        $this->assertSame('external', $website->pages()->where('canonical_url', 'https://site.test/')->first()->links()->where('target_url', 'https://elsewhere.com/')->value('link_type'));
    }

    public function test_recrawl_resolves_fixed_issues_and_failed_crawl_does_not()
    {
        [$user, $website] = $this->userWithWebsite();
        $this->fakeCrawler($this->fixtureSite());
        $this->actingAs($user)->post(route('audit.crawl', $website));
        $missingTitle = SeoIssue::where('rule_key', 'missing_title')->whereHas('page', fn ($q) => $q->where('canonical_url', 'https://site.test/about'))->first();

        // A failed crawl must not resolve anything.
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $this->fakeCrawler([], 'failed');
        $this->actingAs($user)->post(route('audit.crawl', $website));
        $this->assertSame('failed', CrawlRun::latest('id')->first()->status);
        $this->assertSame('open', $missingTitle->fresh()->status);

        // Fixed page on successful recrawl -> resolved.
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $pages = $this->fixtureSite();
        $pages[1] = $this->crawledPage('/about');
        $this->fakeCrawler($pages);
        $this->actingAs($user)->post(route('audit.crawl', $website));

        $this->assertSame('resolved', $missingTitle->fresh()->status);
        $this->assertNotNull($missingTitle->fresh()->resolved_at);
        $this->assertSame('open', SeoIssue::where('rule_key', 'broken_internal_link')->value('status'));
    }

    public function test_crawl_requires_ownership_and_blocks_concurrent_runs()
    {
        [$user, $website] = $this->userWithWebsite(['ownership_confirmed' => false]);
        $this->actingAs($user)->post(route('audit.crawl', $website))->assertSessionHasErrors('crawl');

        $website->update(['ownership_confirmed' => true]);
        $website->crawlRuns()->create(['status' => 'running']);
        $this->actingAs($user)->post(route('audit.crawl', $website))->assertSessionHasErrors('crawl');
    }

    public function test_dismissing_issue_and_audit_pages_render()
    {
        [$user, $website] = $this->userWithWebsite();
        $this->fakeCrawler($this->fixtureSite());
        $this->actingAs($user)->post(route('audit.crawl', $website));
        $issue = SeoIssue::first();

        $this->actingAs($user)->patch(route('audit.issues.update', [$website, $issue]), ['status' => 'dismissed'])->assertRedirect();
        $this->assertSame('dismissed', $issue->fresh()->status);

        $this->actingAs($user)->post(route('audit.issues.explain', [$website, $issue]))->assertRedirect();
        $this->assertNotEmpty($issue->fresh()->ai_explanation);

        $this->actingAs($user)->get(route('audit.index', $website))->assertOk();
        $this->actingAs($user)->get(route('audit.pages', [$website, 'status' => '4xx']))->assertOk()
            ->assertInertia(fn ($p) => $p->where('pages.total', 1));
        $this->actingAs($user)->get(route('audit.page', [$website, $issue->page_id]))->assertOk();
    }

    public function test_flags_blocked_ai_search_assistants_from_robots_txt()
    {
        [$user, $website] = $this->userWithWebsite();
        Http::fake([
            'crawler.test/internal/v1/crawls' => Http::response(['crawl_id' => 'crawl_1', 'status' => 'accepted'], 202),
            'crawler.test/internal/v1/crawls/crawl_1/results*' => Http::response(['status' => 'completed', 'pages' => [$this->crawledPage('/')],
                'next_cursor' => 1, 'done' => true, 'errors' => [], 'sitemaps' => ['https://site.test/sitemap.xml'], 'pages_found' => 1]),
            'site.test/robots.txt' => Http::response("User-agent: GPTBot\nUser-agent: OAI-SearchBot\nDisallow: /\n", 200, ['Content-Type' => 'text/plain']),
        ]);
        $this->actingAs($user)->post(route('audit.crawl', $website));

        $issue = SeoIssue::where('rule_key', 'ai_search_blocked')->first();
        $this->assertNotNull($issue);
        $this->assertSame(['OAI-SearchBot'], $issue->evidence_json['blocked_search_bots']);
        $this->assertSame('low', $issue->severity);
    }
}
