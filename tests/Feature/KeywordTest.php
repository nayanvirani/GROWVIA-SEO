<?php

namespace Tests\Feature;

use App\Models\Keyword;
use App\Models\WebsitePage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KeywordTest extends TestCase
{
    use RefreshDatabase;

    public function test_mock_research_is_deterministic_classified_and_clustered()
    {
        [$user, $website] = $this->userWithWebsite();

        $this->actingAs($user)->post(route('keywords.research', $website), ['seeds' => "Shopify upsell app\nshopify free gift app", 'locale' => 'us', 'language' => 'en'])->assertRedirect();
        $first = Keyword::where('keyword', 'shopify upsell app')->first();
        $this->assertSame('mock', $first->provider);
        $this->assertTrue($first->is_estimate);
        $this->assertNotNull($first->intent);
        $this->assertSame('commercial', Keyword::where('keyword', 'best shopify upsell app')->value('intent'));
        $this->assertSame('transactional', Keyword::where('keyword', 'shopify upsell app pricing')->value('intent'));
        $this->assertGreaterThan(0, $website->keywordClusters()->count());
        $volume = $first->volume;

        // Same input -> same metrics; no duplicates.
        $count = Keyword::count();
        $this->actingAs($user)->post(route('keywords.research', $website), ['seeds' => 'shopify upsell app', 'locale' => 'us', 'language' => 'en']);
        $this->assertSame($count, Keyword::count());
        $this->assertSame($volume, $first->fresh()->volume);
    }

    public function test_manual_keyword_mapping_and_csv_export()
    {
        [$user, $website] = $this->userWithWebsite();
        $page = WebsitePage::create(['website_id' => $website->id, 'canonical_url' => 'https://site.test/upsell', 'url_hash' => str_repeat('c', 64), 'status_code' => 200, 'title' => 'Upsell features']);

        $this->actingAs($user)->post(route('keywords.store', $website), ['keyword' => 'Upsell Features', 'locale' => 'us', 'language' => 'en'])->assertRedirect();
        $kw = Keyword::first();
        $this->assertSame('manual', $kw->provider);
        $this->assertNull($kw->volume, 'no invented metrics for manual keywords');

        $this->actingAs($user)->post(route('keywords.map', [$website, $kw]), ['suggest' => true])
            ->assertSessionHas('flash_data.page_suggestion.suggestion.page_id', $page->id);
        $this->actingAs($user)->post(route('keywords.map', [$website, $kw]), ['page_id' => $page->id]);
        $this->assertSame($page->id, $kw->pageTargets()->value('page_id'));

        $csv = $this->actingAs($user)->get(route('keywords.export', $website));
        $csv->assertOk();
        $this->assertStringContainsString('upsell features', $csv->getContent());
        $this->actingAs($user)->get(route('keywords.index', $website))->assertOk();
    }

    public function test_api_keyword_research()
    {
        [$user, $website] = $this->userWithWebsite();
        $this->actingAs($user)->postJson(route('api.v1.keyword-research', $website), ['seeds' => ['shopify bundles'], 'locale' => 'gb', 'language' => 'en'])
            ->assertCreated()->assertJsonPath('data.0.locale', 'gb');
        $this->actingAs($user)->getJson(route('api.v1.keywords.index', $website))->assertOk()->assertJsonPath('meta.total', 12);
    }
}
