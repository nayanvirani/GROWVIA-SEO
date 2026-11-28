<?php

namespace Tests\Feature;

use App\Domain\Content\Recommender;
use App\Models\Keyword;
use App\Models\PageKeywordTarget;
use App\Models\Recommendation;
use App\Models\WebsitePage;
use App\Support\Url;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecommendationTest extends TestCase
{
    use RefreshDatabase;

    private function page($website, string $path, string $title, array $h2 = [], int $words = 1200): WebsitePage
    {
        return WebsitePage::create(['website_id' => $website->id, 'canonical_url' => 'https://site.test'.$path, 'url_hash' => Url::hash('https://site.test'.$path),
            'status_code' => 200, 'title' => $title, 'h1' => $title, 'headings_json' => ['h1' => [$title], 'h2' => $h2], 'word_count' => $words, 'depth' => 1]);
    }

    private function kw($website, string $keyword, int $score, string $intent, ?WebsitePage $page = null, int $relevance = 30): Keyword
    {
        $k = Keyword::create(['website_id' => $website->id, 'keyword' => $keyword, 'provider' => 'autocomplete', 'opportunity_score' => $score,
            'intent' => $intent, 'is_gap' => $page === null, 'opportunity_json' => ['relevance' => ['points' => $relevance, 'why' => '']]]);
        if ($page) {
            PageKeywordTarget::create(['website_id' => $website->id, 'page_id' => $page->id, 'keyword_id' => $k->id]);
        }

        return $k;
    }

    public function test_generates_concrete_suggestions_and_resolves_them_when_done()
    {
        $this->travelTo(now()->setDate(2026, 11, 27));
        [$user, $website] = $this->userWithWebsite();
        $this->page($website, '/', 'Shopify Bundles & Upsells | Growvia', ['Bundles', 'Upsells']);
        $upsells = $this->page($website, '/features/upsells', 'Shopify Upsell Popup | Growvia', ['Popups that convert']);
        $this->page($website, '/blog/best-apps', 'Best Shopify apps 2026', ['The list']);
        $this->page($website, '/legal/terms', 'Terms 2025');
        $this->kw($website, 'shopify cart upsell', 80, 'commercial', $upsells);
        $this->kw($website, 'shopify post purchase upsell', 65, 'commercial', $upsells);
        $this->kw($website, 'shopify sales tracker', 75, 'commercial');
        $this->kw($website, 'how to set up quantity breaks', 60, 'informational');
        $this->kw($website, 'shopify login help', 90, 'navigational');
        $this->kw($website, 'cheap flights', 70, 'commercial', null, 5);

        $out = app(Recommender::class)->generate($website);
        $types = Recommendation::where('status', 'open')->get()->groupBy('type');

        $title = $types['update_page']->first();
        $this->assertStringContainsString('shopify cart upsell', $title->title);
        $this->assertSame('Shopify Cart Upsell | Growvia', $title->proposal_json['suggested_title']);
        $this->assertSame('high', $title->priority);
        $this->assertStringContainsString('shopify post purchase upsell', $types['add_section']->first()->title);
        $this->assertSame('/features/sales-tracker', $types['new_landing']->first()->proposal_json['suggested_url']);
        $this->assertStringContainsString('How to set up quantity breaks', $types['new_post']->first()->title);
        $refresh = $types['refresh']->first();
        $this->assertSame('Best Shopify apps 2027', $refresh->proposal_json['suggested_title'], 'November: titles move to next year');
        $this->assertSame(1, $types['refresh']->count(), 'legal pages are ignored');
        $this->assertSame(0, Recommendation::where('title', 'like', '%cheap flights%')->orWhere('title', 'like', '%login%')->count());

        // The owner updates the title; the next crawl shows it; the suggestion closes itself.
        $upsells->update(['title' => 'Shopify Cart Upsell App | Growvia']);
        app(Recommender::class)->generate($website);
        $this->assertSame('resolved', $title->fresh()->status);

        $this->actingAs($user)->get(route('recommendations.index', $website))->assertOk()->assertInertia(fn ($p) => $p->component('recommendations/Index')->has('recommendations'));
        $this->actingAs($user)->get(route('websites.show', $website))
            ->assertInertia(fn ($p) => $p->where('overview.todo', fn ($t) => collect($t)->contains(fn ($i) => str_contains($i['text'], 'suggested update'))));
    }

    public function test_create_brief_from_a_suggestion_and_dismiss()
    {
        [$user, $website] = $this->userWithWebsite();
        $this->kw($website, 'how to set up quantity breaks', 60, 'informational');
        app(Recommender::class)->generate($website);
        $rec = Recommendation::where('type', 'new_post')->first();

        $this->actingAs($user)->post(route('recommendations.brief', [$website, $rec]))->assertRedirect(route('content.index', $website));
        $this->assertSame('done', $rec->fresh()->status);
        $this->assertSame(1, $website->contentBriefs()->where('title', 'How to set up quantity breaks')->count());

        // Once briefed, the topic is no longer suggested.
        app(Recommender::class)->generate($website);
        $this->assertSame(0, Recommendation::where('status', 'open')->where('type', 'new_post')->count());

        $this->kw($website, 'shopify sales tracker', 75, 'commercial');
        app(Recommender::class)->generate($website);
        $landing = Recommendation::where('type', 'new_landing')->first();
        $this->actingAs($user)->patch(route('recommendations.update', [$website, $landing]), ['status' => 'dismissed']);
        app(Recommender::class)->generate($website);
        $this->assertSame('dismissed', $landing->fresh()->status, 'dismissed suggestions stay dismissed');
    }
}
