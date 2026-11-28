<?php

namespace Tests\Feature;

use App\Domain\Keyword\KeywordContentWriter;
use App\Models\Keyword;
use App\Models\PageKeywordTarget;
use App\Models\WebsitePage;
use App\Support\Url;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class KeywordContentTest extends TestCase
{
    use RefreshDatabase;

    private function page($website, array $h2, string $path = '/features/upsells'): WebsitePage
    {
        return WebsitePage::create(['website_id' => $website->id, 'canonical_url' => 'https://site.test'.$path, 'url_hash' => Url::hash('https://site.test'.$path),
            'status_code' => 200, 'title' => 'Upsell Popups | Growvia', 'headings_json' => ['h1' => ['Upsell Popups'], 'h2' => $h2], 'text_excerpt' => 'Show upsell offers in the cart.', 'depth' => 1]);
    }

    public function test_placement_follows_the_real_heading_order()
    {
        [$user, $website] = $this->userWithWebsite();
        $writer = app(KeywordContentWriter::class);

        $p = $writer->placement($this->page($website, ['How cart upsells work', 'Setup in minutes', 'FAQ', 'Start free']), 'shopify cart upsell ideas', 'shopify');
        $this->assertSame(['How cart upsells work', 'Setup in minutes'], [$p['after'], $p['before']]);
        $this->assertStringContainsString('right after the section "How cart upsells work" and before "Setup in minutes"', $p['text']);

        $page2 = $this->page($website, ['Why it works', 'Setup', 'FAQ', 'Ready to grow?'], '/features/checkout');
        $p = $writer->placement($page2, 'one click checkout offer', 'shopify');
        $this->assertSame(['Setup', 'FAQ'], [$p['after'], $p['before']], 'no related heading: goes before the closing FAQ');
    }

    public function test_write_section_in_background_then_show_ready_content()
    {
        [$user, $website] = $this->userWithWebsite();
        $page = $this->page($website, ['How cart upsells work', 'FAQ']);
        $k = Keyword::create(['website_id' => $website->id, 'keyword' => 'one click upsell shopify', 'provider' => 'autocomplete', 'opportunity_score' => 70,
            'intent' => 'commercial', 'opportunity_json' => ['relevance' => ['points' => 30, 'why' => '']]]);
        PageKeywordTarget::create(['website_id' => $website->id, 'page_id' => $page->id, 'keyword_id' => $k->id]);

        $this->actingAs($user)->patch(route('providers.update', 'ai'), ['preset' => 'groq', 'api_key' => 'k', 'model' => 'm']);
        Http::fake(['api.groq.com/*' => Http::response(['model' => 'm', 'choices' => [['message' => ['content' => json_encode([
            'heading' => 'One-click upsells on Shopify', 'paragraphs' => ['First paragraph.', 'Second paragraph <b>safe</b>.'],
            'bullets' => ['Works in the cart'], 'faq' => ['question' => 'Is it one click?', 'answer' => 'Yes, in the cart drawer.'],
        ])]]]])]);

        $this->actingAs($user)->post(route('keywords.write', [$website, $k]), ['kind' => 'section', 'page_id' => $page->id])->assertSessionHas('success');

        $c = $k->fresh()->content_json;
        $this->assertSame('ready', $c['status']);
        $this->assertSame('One-click upsells on Shopify', $c['heading']);
        $this->assertCount(2, $c['paragraphs']);
        $this->assertSame('How cart upsells work', $c['placement']['after']);

        $row = collect($this->actingAs($user)->get(route('keywords.index', $website))->viewData('page')['props']['keywords']['data'])->firstWhere('keyword', 'one click upsell shopify');
        $this->assertSame('section', $row['advice']['write_kind']);
        $this->assertSame('ready', $row['advice']['content_status']);
        $this->assertSame('One-click upsells on Shopify', $row['advice']['content']['heading']);
        $this->assertStringContainsString('📍', $row['advice']['steps'][2]);

        $this->actingAs($user)->post(route('keywords.write', [$website, $k]), ['kind' => 'section', 'page_id' => 'not-a-page'])->assertSessionHasErrors('page_id');
    }
}
