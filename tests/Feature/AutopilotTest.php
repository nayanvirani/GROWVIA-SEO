<?php

namespace Tests\Feature;

use App\Domain\Automation\Automations;
use App\Domain\Backlink\BacklinkDiscovery;
use App\Domain\Keyword\KeywordResearchService;
use App\Jobs\RunAutomation;
use App\Models\BacklinkProspect;
use App\Models\Keyword;
use App\Models\WebsitePage;
use App\Support\Url;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AutopilotTest extends TestCase
{
    use RefreshDatabase;

    private function sitePages($website): void
    {
        foreach ([
            ['/', 'Growvia – Shopify upsell app for growing stores', 'Shopify upsell app'],
            ['/features/free-gift', 'Free gift offers – Shopify upsell app | Growvia', 'Free gift offers'],
            ['/features/bundles', 'Product bundles – Shopify upsell app | Growvia', 'Product bundles'],
        ] as [$path, $title, $h1]) {
            WebsitePage::create(['website_id' => $website->id, 'canonical_url' => 'https://site.test'.$path, 'url_hash' => Url::hash('https://site.test'.$path),
                'status_code' => 200, 'content_type' => 'text/html', 'title' => $title, 'h1' => $h1, 'headings_json' => ['h1' => [$h1], 'h2' => ['Free gift with purchase']],
                'depth' => $path === '/' ? 0 : 1]);
        }
    }

    public function test_keyword_research_runs_without_any_input_and_plans_content()
    {
        config(['seo.seo_data.provider' => 'autocomplete']);
        [$user, $website] = $this->userWithWebsite(['website_type' => 'Shopify app']);
        $website->update(['settings_json' => ['description' => 'Growvia helps Shopify stores sell more with upsells, free gifts and bundles.']]);
        $this->sitePages($website);
        Http::fake([
            'suggestqueries.google.com/*' => fn ($r) => Http::response([$r['q'], [$r['q'].' app', 'best '.$r['q'], $r['q'].' free gift', 'shopify bundle upsell ideas', 'cheap flights to paris']]),
            'duckduckgo.com/ac/*' => fn ($r) => Http::response([$r['q'], [$r['q'].' app', $r['q'].' for shopify']]),
        ]);

        $result = app(KeywordResearchService::class)->autoResearch($website->fresh());

        $this->assertContains('shopify upsell app', $result['seeds'], 'seed found from page titles');
        $this->assertGreaterThan(5, $result['keywords']);
        $both = Keyword::where('keyword', 'shopify upsell app app')->first();
        $this->assertSame(['google', 'duckduckgo'], $both->sources_json);
        $this->assertNull($both->volume, 'free sources: no invented volume');
        $offTopic = Keyword::where('keyword', 'cheap flights to paris')->first();
        $this->assertLessThan(30, $offTopic->opportunity_score, 'off-topic ideas are pushed down');
        $this->assertGreaterThan($offTopic->opportunity_score, $both->opportunity_score);
        $this->assertArrayHasKey('relevance', $both->opportunity_json);

        $giftKeyword = Keyword::where('keyword', 'like', '%free gift offers%')->whereHas('pageTargets')->with('pageTargets.page')->first();
        $this->assertNotNull($giftKeyword, 'gift keywords are auto-mapped');
        $this->assertSame('https://site.test/features/free-gift', $giftKeyword->pageTargets->first()->page->canonical_url);
        $briefs = $website->contentBriefs()->where('source', 'autopilot')->get();
        $this->assertGreaterThan(0, $briefs->count());
        $this->assertLessThanOrEqual(3, $briefs->count());
        $this->assertTrue($briefs->every(fn ($b) => $b->status === 'idea'), 'offline AI: briefs only, no drafts');
        $this->assertTrue($briefs->every(fn ($b) => Keyword::find($b->primary_keyword_id)->is_gap));

        // Running again does not duplicate briefs for the same keyword.
        app(KeywordResearchService::class)->planContent($website);
        $this->assertSame($briefs->count(), $website->contentBriefs()->count());
    }

    public function test_backlink_discovery_finds_scores_and_drafts_without_sending_anything()
    {
        [$user, $website] = $this->userWithWebsite();
        $kw = Keyword::create(['website_id' => $website->id, 'keyword' => 'shopify upsell app', 'provider' => 'mock', 'opportunity_score' => 80]);

        $result = fn (string $url, string $title) => '<tr><td><a rel="nofollow" href="//duckduckgo.com/l/?uddg='.urlencode($url)."&amp;rut=x\" class='result-link'>{$title}</a></td></tr><tr><td class='result-snippet'>Snippet about {$title}</td></tr>";
        $serp = '<table>'.$result('https://shopify-blog.example.com/blog/best-shopify-upsell-apps', 'Best Shopify upsell apps in 2026')
            .$result('https://rival.example.com/', 'Rival – upsell app')
            .$result('https://site.test/', 'Our own site')
            .$result('https://www.youtube.com/watch?v=1', 'Video: best upsell apps')
            .$result('https://lists.example.org/top-tools', 'Top 10 upsell tools')
            .'</table>';
        Http::fake([
            'lite.duckduckgo.com/*' => Http::response($serp),
            'shopify-blog.example.com/*' => Http::response('<html><title>Best Shopify upsell apps in '.now()->year.'</title><body>
                <a href="https://rival.example.com/">Rival</a> <a href="https://site.test/features" rel="nofollow">Growvia</a> <a href="/about">About</a></body></html>', 200, ['Content-Type' => 'text/html']),
            'lists.example.org/*' => Http::response('<html><title>Top 10 upsell tools</title><body><a href="https://rival.example.com/">Rival</a></body></html>', 200, ['Content-Type' => 'text/html']),
        ]);

        $out = app(BacklinkDiscovery::class)->discover($website);

        $this->assertSame(1, $out['already_linking_to_you']);
        $this->assertContains('rival.example.com', $out['competitors']);
        $this->assertTrue($website->competitors()->where('domain', 'rival.example.com')->value('active'), 'seen in several searches');
        $this->assertSame(0, BacklinkProspect::where('source_domain', 'youtube.com')->count(), 'platforms are skipped');
        $this->assertSame(0, BacklinkProspect::where('source_domain', 'site.test')->count(), 'own site is skipped');

        $linking = BacklinkProspect::where('source_domain', 'shopify-blog.example.com')->first();
        $this->assertSame('placed', $linking->status);
        $this->assertSame('existing_link', $linking->prospect_type);
        $this->assertSame('nofollow', $website->backlinks()->first()->rel_attributes);

        $list = BacklinkProspect::where('source_domain', 'lists.example.org')->first();
        $this->assertSame(['rival.example.com'], $list->evidence_json['competitors_linked']);
        $this->assertStringContainsString('competitor', $list->score_breakdown_json['niche_relevance']['evidence']);
        $this->assertSame(1, $list->outreachDrafts()->count(), 'outreach drafted for you to send');
        $this->assertNull($list->outreachDrafts()->first()->sent_at);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'mail'));
    }

    public function test_discovery_is_skipped_politely_when_search_is_off_or_blocked()
    {
        [$user, $website] = $this->userWithWebsite();
        Keyword::create(['website_id' => $website->id, 'keyword' => 'shopify upsell app', 'provider' => 'mock', 'opportunity_score' => 80]);

        Http::fake(['lite.duckduckgo.com/*' => Http::response('blocked', 202)]);
        $out = app(BacklinkDiscovery::class)->discover($website);
        $this->assertNotEmpty($out['errors']);
        $this->assertStringContainsString('Tavily', $out['errors'][0]);

        $this->actingAs($user)->patch(route('providers.update', 'search'), ['preset' => 'none']);
        $this->assertArrayHasKey('skipped', app(BacklinkDiscovery::class)->discover($website));
    }

    public function test_crawl_completion_chains_research_and_discovery_once_a_week()
    {
        [$user, $website] = $this->userWithWebsite();
        Bus::fake([RunAutomation::class]);

        app(Automations::class)->followUp($website);
        Bus::assertChained([
            fn (RunAutomation $j) => $j->key === 'keyword_research',
            fn (RunAutomation $j) => $j->key === 'recommendations',
            fn (RunAutomation $j) => $j->key === 'backlink_discovery',
            fn (RunAutomation $j) => $j->key === 'brand_mentions',
            fn (RunAutomation $j) => $j->key === 'listing_check',
        ]);

        foreach (['keyword_research', 'backlink_discovery', 'brand_mentions', 'listing_check'] as $key) {
            $website->automationRuns()->create(['workspace_id' => $website->workspace_id, 'automation_key' => $key, 'status' => 'succeeded', 'started_at' => now()]);
        }
        Bus::fake([RunAutomation::class]);
        app(Automations::class)->followUp($website->fresh());
        Bus::assertChained([fn (RunAutomation $j) => $j->key === 'recommendations'], 'only the cheap per-crawl step runs again within a week');

        $website->update(['settings_json' => ['autopilot' => false]]);
        Bus::fake([RunAutomation::class]);
        app(Automations::class)->followUp($website->fresh(), force: false);
        Bus::assertNothingDispatched();
    }

    public function test_autopilot_button_queues_crawl_and_the_overview_shows_a_todo_list()
    {
        [$user, $website] = $this->userWithWebsite(['settings_json' => ['autopilot' => false]]);
        $this->fakeCrawler([$this->crawledPage('/')]);

        $this->actingAs($user)->post(route('automation.run', [$website, 'autopilot']))->assertRedirect();
        $this->assertSame('succeeded', $website->automationRuns()->where('automation_key', 'autopilot')->value('status'));
        $this->assertSame(1, $website->crawlRuns()->count());

        $this->actingAs($user)->get(route('websites.show', $website))->assertInertia(fn ($p) => $p
            ->where('overview.todo.0.text', 'Connect a free AI provider')
            ->where('overview.autopilot.ai_real', false));
    }

    public function test_overview_asks_for_a_search_key_when_duckduckgo_keeps_blocking()
    {
        [$user, $website] = $this->userWithWebsite();
        foreach (['backlink_discovery', 'brand_mentions'] as $key) {
            $website->automationRuns()->create(['workspace_id' => $website->workspace_id, 'automation_key' => $key, 'status' => 'succeeded',
                'started_at' => now(), 'result_json' => ['errors' => ['DuckDuckGo is temporarily limiting searches (HTTP 202).']]]);
        }
        $this->actingAs($user)->get(route('websites.show', $website))
            ->assertInertia(fn ($p) => $p->where('overview.todo', fn ($t) => collect($t)->contains(fn ($i) => str_contains($i['text'], 'Tavily'))));

        $this->actingAs($user)->patch(route('providers.update', 'search'), ['preset' => 'tavily', 'api_key' => 'tvly-x']);
        $this->actingAs($user)->get(route('websites.show', $website))
            ->assertInertia(fn ($p) => $p->where('overview.todo', fn ($t) => ! collect($t)->contains(fn ($i) => str_contains($i['text'], 'Tavily'))));
    }
}
