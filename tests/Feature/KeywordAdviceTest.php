<?php

namespace Tests\Feature;

use App\Models\Keyword;
use App\Models\PageKeywordTarget;
use App\Models\WebsitePage;
use App\Support\Url;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KeywordAdviceTest extends TestCase
{
    use RefreshDatabase;

    private function kw($website, string $keyword, int $score, string $intent, ?WebsitePage $page = null, int $relevance = 30): Keyword
    {
        $k = Keyword::create(['website_id' => $website->id, 'keyword' => $keyword, 'provider' => 'autocomplete', 'opportunity_score' => $score,
            'intent' => $intent, 'is_gap' => $page === null, 'opportunity_json' => ['relevance' => ['points' => $relevance, 'why' => '']]]);
        if ($page) {
            PageKeywordTarget::create(['website_id' => $website->id, 'page_id' => $page->id, 'keyword_id' => $k->id]);
        }

        return $k;
    }

    private function page($website, string $path, string $title): WebsitePage
    {
        return WebsitePage::create(['website_id' => $website->id, 'canonical_url' => 'https://site.test'.$path, 'url_hash' => Url::hash('https://site.test'.$path),
            'status_code' => 200, 'title' => $title, 'headings_json' => ['h1' => [$title], 'h2' => []], 'depth' => 1]);
    }

    public function test_every_keyword_gets_a_plain_action_with_where_and_what()
    {
        [$user, $website] = $this->userWithWebsite();
        $this->kw($website, 'shopify bundle app', 80, 'commercial', $this->page($website, '/features/bundles', 'Shopify Bundle App | Growvia'));
        $this->kw($website, 'shopify cart upsell', 78, 'commercial', $this->page($website, '/features/popups', 'Upsell Popups | Growvia'));
        $this->kw($website, 'shopify upsell revenue attribution tool', 75, 'commercial');
        $this->kw($website, 'how to write upsell email sequences', 60, 'informational');
        $this->kw($website, 'shopify bundle builder', 66, 'commercial');
        $this->kw($website, 'best shopify bundle builder app', 64, 'commercial');
        $this->kw($website, 'kaching bundles shopify', 70, 'commercial');
        $this->kw($website, 'shopify gift cards', 70, 'commercial');
        $this->kw($website, 'shopify bundles api', 66, 'commercial');
        $website->competitors()->create(['domain' => 'support.kachingappz.com', 'source' => 'autopilot', 'active' => true]);
        $planned = $this->kw($website, 'shopify bundles with variants', 70, 'informational');
        $website->contentBriefs()->create(['title' => 'Bundles with variants', 'primary_keyword_id' => $planned->id, 'status' => 'idea']);
        $this->kw($website, 'shopify login', 90, 'navigational');
        $this->kw($website, 'cheap flights', 70, 'commercial', null, 5);
        $this->kw($website, 'shopify pos hardware', 30, 'commercial');

        $res = $this->actingAs($user)->get(route('keywords.index', $website));
        $res->assertOk()->assertInertia(fn ($p) => $p->component('keywords/Index')
            ->where('counts.todo', 5)->where('counts.done', 1)->where('counts.planned', 1)->where('counts.skip', 6)->where('counts.all', 13)
            ->where('filters.show', 'todo')->has('keywords.data', 5));

        $todo = collect($res->viewData('page')['props']['keywords']['data'])->keyBy('keyword');
        $this->assertSame('Mention it on your page', $todo['shopify cart upsell']['advice']['action']);
        $this->assertSame('/features/popups', $todo['shopify cart upsell']['advice']['page_path']);
        $this->assertSame('Shopify cart upsell', $todo['shopify cart upsell']['advice']['suggestion']['text']);
        $this->assertSame('Create a landing page', $todo['shopify upsell revenue attribution tool']['advice']['action']);
        $this->assertTrue($todo['shopify upsell revenue attribution tool']['advice']['can_brief']);
        $this->assertSame('Write a blog post', $todo['how to write upsell email sequences']['advice']['action']);
        $this->assertSame('Add a section to an existing page', $todo['shopify bundle builder']['advice']['action']);
        $this->assertSame('/features/bundles', $todo['shopify bundle builder']['advice']['page_path'], 'extend the closest product page');
        $this->assertSame('Create a comparison page', $todo['kaching bundles shopify']['advice']['action']);
        $this->assertStringContainsString('vs Kaching', $todo['kaching bundles shopify']['advice']['suggestion']['text']);

        $skip = collect($this->actingAs($user)->get(route('keywords.index', [$website, 'show' => 'skip']))->viewData('page')['props']['keywords']['data'])->keyBy('keyword');
        $this->assertStringContainsString('specific website', $skip['shopify login']['advice']['summary']);
        $this->assertStringContainsString('Off-topic', $skip['cheap flights']['advice']['summary']);
        $this->assertStringContainsString('Low opportunity', $skip['shopify pos hardware']['advice']['summary']);
        $this->assertStringContainsString('Same topic as "shopify bundle builder"', $skip['best shopify bundle builder app']['advice']['summary']);
        $this->assertStringContainsString('Gift cards', $skip['shopify gift cards']['advice']['summary']);
        $this->assertStringContainsString('Developers', $skip['shopify bundles api']['advice']['summary']);
    }

    public function test_create_brief_from_a_keyword_once()
    {
        [$user, $website] = $this->userWithWebsite();
        $k = $this->kw($website, 'shopify upsell revenue attribution tool', 75, 'commercial');

        $this->actingAs($user)->post(route('keywords.brief', [$website, $k]))->assertRedirect(route('content.index', $website));
        $brief = $website->contentBriefs()->first();
        $this->assertSame('landing_page', $brief->content_type);
        $this->assertSame($k->id, $brief->primary_keyword_id);

        $this->actingAs($user)->post(route('keywords.brief', [$website, $k]));
        $this->assertSame(1, $website->contentBriefs()->count(), 'no duplicate briefs');
    }
}
