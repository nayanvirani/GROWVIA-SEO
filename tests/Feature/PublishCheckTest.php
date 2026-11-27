<?php

namespace Tests\Feature;

use App\Models\ContentDraft;
use App\Models\Keyword;
use App\Models\WebsitePage;
use App\Support\Url;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PublishCheckTest extends TestCase
{
    use RefreshDatabase;

    private function setUpSite(): array
    {
        [$user, $website] = $this->userWithWebsite();
        foreach ([
            ['/features/bundles', 'Shopify Bundle App: Quantity Breaks | Growvia', 'Create bundles with variants and quantity breaks for your products.'],
            ['/pricing', 'Pricing: bundles for every plan | Growvia', 'Bundles with variants on all plans.'],
            ['/about', 'About Growvia', 'We build apps.'],
        ] as [$path, $title, $text]) {
            WebsitePage::create(['website_id' => $website->id, 'canonical_url' => 'https://site.test'.$path, 'url_hash' => Url::hash('https://site.test'.$path),
                'status_code' => 200, 'title' => $title, 'text_excerpt' => $text, 'depth' => 1]);
        }
        $kw = Keyword::create(['website_id' => $website->id, 'keyword' => 'shopify bundles with variants', 'provider' => 'autocomplete', 'is_gap' => true]);
        $brief = $website->contentBriefs()->create(['title' => 'Shopify bundles with variants', 'primary_keyword_id' => $kw->id, 'status' => 'content_draft']);
        $draft = ContentDraft::create(['website_id' => $website->id, 'brief_id' => $brief->id, 'type' => 'blog_post', 'status' => 'approved_for_export',
            'title' => 'Shopify Bundles with Variants: A Setup Guide', 'meta_title' => 'Shopify Bundles with Variants: A Setup Guide',
            'meta_description' => 'How to set up bundles with variants.', 'body_markdown' => str_repeat('Bundles with variants explained clearly. ', 40)]);

        return [$user, $website, $draft, $kw];
    }

    private function postHtml(string $extraHead = ''): string
    {
        return '<html><head><title>Shopify Bundles with Variants: A Setup Guide</title><meta name="description" content="How to set up bundles with variants.">'
            .$extraHead.'</head><body><h1>Shopify Bundles with Variants</h1><p>'.str_repeat('Bundles with variants explained clearly. ', 40).'</p>'
            .'<a href="/features/bundles">bundle app</a> <a href="https://site.test/old-page">old</a><img src="/a.png"></body></html>';
    }

    public function test_checks_the_live_post_registers_it_and_suggests_internal_links()
    {
        [$user, $website, $draft, $kw] = $this->setUpSite();
        $this->actingAs($user)->get(route('websites.show', $website))
            ->assertInertia(fn ($p) => $p->where('overview.todo', fn ($t) => collect($t)->contains(fn ($i) => str_starts_with($i['text'], 'Publish "Shopify Bundles'))));

        Http::fake([
            'site.test/blog/bundles-with-variants' => Http::response($this->postHtml(), 200, ['Content-Type' => 'text/html']),
            'site.test/old-page' => Http::response('gone', 404, ['Content-Type' => 'text/html']),
        ]);
        $this->actingAs($user)->post(route('content.drafts.published', [$website, $draft]), ['url' => 'https://site.test/blog/bundles-with-variants'])
            ->assertSessionHas('error');

        $draft->refresh();
        $checks = collect($draft->publish_check_json['checks'])->keyBy('key');
        $this->assertSame('published_externally', $draft->status);
        $this->assertSame('ok', $checks['loads']['state']);
        $this->assertSame('ok', $checks['title']['state']);
        $this->assertSame('ok', $checks['keyword']['state']);
        $this->assertSame('ok', $checks['complete']['state']);
        $this->assertSame('warn', $checks['images']['state']);
        $this->assertSame('fail', $checks['links']['state']);
        $this->assertStringContainsString('old-page', $checks['links']['detail']);

        $page = WebsitePage::where('canonical_url', 'https://site.test/blog/bundles-with-variants')->first();
        $this->assertNotNull($page, 'published post is registered as a site page');
        $this->assertSame($page->id, $kw->pageTargets()->value('page_id'));
        $this->assertFalse($kw->fresh()->is_gap);

        $suggested = collect($draft->publish_check_json['link_suggestions'])->pluck('url');
        $this->assertContains('https://site.test/features/bundles', $suggested);
        $this->assertNotContains('https://site.test/pricing', $suggested, 'no article links from pricing/legal pages');
        $this->assertNotContains('https://site.test/about', $suggested, 'unrelated page');
        $this->assertSame('Shopify bundles with variants', $draft->publish_check_json['link_suggestions'][0]['anchor']);

        $this->actingAs($user)->get(route('websites.show', $website))
            ->assertInertia(fn ($p) => $p->where('overview.todo', fn ($t) => collect($t)->contains(fn ($i) => str_starts_with($i['text'], 'Add 1 link(s)'))));
    }

    public function test_noindex_other_domains_and_missing_pages_are_reported()
    {
        [$user, $website, $draft] = $this->setUpSite();
        $this->actingAs($user)->post(route('content.drafts.published', [$website, $draft]), ['url' => 'https://elsewhere.com/post'])->assertSessionHasErrors('url');

        Http::fake([
            'site.test/blog/hidden' => Http::response($this->postHtml('<meta name="robots" content="noindex, follow">'), 200, ['Content-Type' => 'text/html']),
            'site.test/blog/missing' => Http::response('not found', 404, ['Content-Type' => 'text/html']),
            'site.test/*' => Http::response('', 200, ['Content-Type' => 'text/html']),
        ]);
        $this->actingAs($user)->post(route('content.drafts.published', [$website, $draft]), ['url' => 'https://site.test/blog/hidden']);
        $this->assertSame('fail', collect($draft->fresh()->publish_check_json['checks'])->firstWhere('key', 'indexable')['state']);

        $this->actingAs($user)->post(route('content.drafts.published', [$website, $draft]), ['url' => 'https://site.test/blog/missing']);
        $checks = $draft->fresh()->publish_check_json['checks'];
        $this->assertSame([['key' => 'loads', 'state' => 'fail', 'label' => 'The page does not load', 'detail' => 'HTTP 404. Check the link, or that the post is published (not a draft).']], $checks);
    }
}
