<?php

namespace Tests\Feature;

use App\Domain\Backlink\BrandMentions;
use App\Domain\Backlink\ListingKit;
use App\Models\BacklinkProspect;
use App\Models\User;
use App\Models\WebsitePage;
use App\Support\Url;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ListingTest extends TestCase
{
    use RefreshDatabase;

    private function pages($website): void
    {
        foreach ([
            ['/', 'Shopify Bundles, Upsells & Free Gift App | Growvia', 'Raise order value with bundles and upsells.'],
            ['/features/bundles', 'Shopify Bundle App: Quantity Breaks, Mix & Match | Growvia', null],
            ['/features/cart-upsells', 'Shopify Cart Upsell & Cross-sell App | Growvia', null],
            ['/pricing', 'Pricing: Free Plan & Paid Plans for Shopify | Growvia', null],
        ] as [$path, $title, $desc]) {
            WebsitePage::create(['website_id' => $website->id, 'canonical_url' => 'https://site.test'.$path, 'url_hash' => Url::hash('https://site.test'.$path),
                'status_code' => 200, 'title' => $title, 'meta_description' => $desc, 'depth' => $path === '/' ? 0 : 1]);
        }
    }

    public function test_listing_texts_are_built_from_the_website_without_ai()
    {
        [$user, $website] = $this->userWithWebsite();
        $this->pages($website);

        $this->actingAs($user)->get(route('listings.index', $website))->assertOk()->assertInertia(fn ($p) => $p
            ->component('listings/Index')
            ->where('pack.tagline', 'Shopify Bundles, Upsells & Free Gift App')
            ->where('pack.short_description', 'Raise order value with bundles and upsells.')
            ->where('pack.features', ['Bundle App', 'Cart Upsell & Cross-sell App'])
            ->where('pack.pricing', 'Free Plan & Paid Plans for Shopify')
            ->where('platforms.0.key', 'shopify_app_store')
            ->has('platforms', count(config('listings'))));
        $this->assertNotNull($website->fresh()->setting('listing_pack'), 'pack is saved and reused');
    }

    public function test_ai_rewrites_the_pack_when_connected()
    {
        [$user, $website] = $this->userWithWebsite();
        $this->pages($website);
        $this->actingAs($user)->patch(route('providers.update', 'ai'), ['preset' => 'groq', 'api_key' => 'k', 'model' => 'm']);
        Http::fake(['api.groq.com/*' => Http::response(['model' => 'm', 'choices' => [['message' => ['content' => json_encode([
            'tagline' => str_repeat('Bundles and upsells for Shopify ', 4), 'short_description' => 'Short.', 'long_description' => 'Long text.',
            'features' => ['Bundles', 'Upsells'], 'categories' => ['Upselling'], 'audience' => 'Shopify merchants', 'pricing' => 'Free plan available',
        ])]]]])]);

        $this->actingAs($user)->post(route('listings.regenerate', $website))->assertRedirect();
        $pack = $website->fresh()->setting('listing_pack');
        $this->assertSame('ai', $pack['source']);
        $this->assertSame(['Bundles', 'Upsells'], $pack['features']);
        $this->assertLessThanOrEqual(60, mb_strlen($pack['tagline']), 'trimmed to the directory limit');
    }

    public function test_marking_live_verifies_the_link_automatically()
    {
        [$user, $website] = $this->userWithWebsite();
        Http::fake([
            'www.saashub.com/growvia' => Http::response('<html><a href="https://site.test/?ref=saashub">Visit</a></html>', 200, ['Content-Type' => 'text/html']),
            'www.indiehackers.com/product/growvia' => Http::response('<html><p>No link yet</p></html>', 200, ['Content-Type' => 'text/html']),
        ]);

        $this->actingAs($user)->patch(route('listings.update', [$website, 'saashub']), ['status' => 'submitted', 'listing_url' => 'https://www.saashub.com/growvia'])
            ->assertSessionHas('success');
        $row = $website->listings()->where('platform', 'saashub')->first();
        $this->assertSame('live', $row->status);
        $this->assertSame(1, $website->backlinks()->where('provider', 'listing')->count());

        $this->actingAs($user)->patch(route('listings.update', [$website, 'indie_hackers']), ['status' => 'submitted', 'listing_url' => 'https://www.indiehackers.com/product/growvia']);
        $row = $website->listings()->where('platform', 'indie_hackers')->first();
        $this->assertSame('submitted', $row->status);
        $this->assertStringContainsString('no link to your website', $row->check_result);

        // Sites that block bots are trusted as marked.
        $this->actingAs($user)->patch(route('listings.update', [$website, 'g2']), ['status' => 'live', 'listing_url' => 'https://www.g2.com/products/growvia']);
        $this->assertSame('live', $website->listings()->where('platform', 'g2')->value('status'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'g2.com'));

        $this->actingAs($user)->patch(route('listings.update', [$website, 'not-a-platform']), ['status' => 'todo'])->assertNotFound();

        $viewer = User::factory()->create();
        $website->workspace->members()->attach($viewer->id, ['role' => 'viewer']);
        $viewer->forceFill(['current_workspace_id' => $website->workspace_id])->save();
        $this->actingAs($viewer)->patch(route('listings.update', [$website, 'g2']), ['status' => 'todo'])->assertForbidden();
    }

    public function test_finds_unlinked_brand_mentions_and_drafts_a_thank_you_request()
    {
        [$user, $website] = $this->userWithWebsite();
        $this->pages($website);
        $result = fn (string $url, string $title) => '<tr><td><a href="//duckduckgo.com/l/?uddg='.urlencode($url)."&amp;rut=x\" class='result-link'>{$title}</a></td></tr>";
        Http::fake([
            'lite.duckduckgo.com/*' => Http::response('<table>'
                .$result('https://blog.example.com/apps-we-love', 'Apps we love')
                .$result('https://news.example.org/growvia-review', 'Growvia review')
                .$result('https://other.example.net/growvia-farm', 'Growvia farm co-op')
                .$result('https://www.facebook.com/growvia', 'Facebook').'</table>'),
            'blog.example.com/*' => Http::response('<html><title>Apps we love</title><p>We use Growvia for Shopify bundles and it works.</p></html>', 200, ['Content-Type' => 'text/html']),
            'news.example.org/*' => Http::response('<html><title>Growvia review</title><p>Growvia adds Shopify upsells. <a href="https://site.test/">site</a></p></html>', 200, ['Content-Type' => 'text/html']),
            'other.example.net/*' => Http::response('<html><title>Farm</title><p>The Growvia farm grows vegetables.</p></html>', 200, ['Content-Type' => 'text/html']),
        ]);

        $out = app(BrandMentions::class)->find($website);

        $this->assertSame(1, $out['unlinked_mentions']);
        $this->assertSame(1, $out['already_linked']);
        $mention = BacklinkProspect::where('prospect_type', 'unlinked_mention')->first();
        $this->assertSame('blog.example.com', $mention->source_domain);
        $this->assertStringContainsString('Growvia for Shopify bundles', $mention->evidence_json['mention']);
        $this->assertStringContainsString('Thank you for mentioning', $mention->outreachDrafts()->first()->body);
        $this->assertSame(0, BacklinkProspect::where('source_domain', 'other.example.net')->count(), 'same name, different business');
        $this->assertSame(0, BacklinkProspect::where('source_domain', 'facebook.com')->count());
        $this->assertSame(1, $website->backlinks()->count());
    }

    public function test_overview_points_to_the_next_listing()
    {
        [$user, $website] = $this->userWithWebsite();
        app(ListingKit::class)->update($website, 'shopify_app_store', ['status' => 'live']);
        $this->actingAs($user)->get(route('websites.show', $website))->assertInertia(fn ($p) => $p
            ->where('overview.todo', fn ($todo) => collect($todo)->contains(fn ($t) => str_starts_with($t['text'], 'Get listed on G2'))));
    }

    public function test_contact_email_is_saved_in_settings_and_shown_for_copying()
    {
        [$user, $website] = $this->userWithWebsite();
        $this->actingAs($user)->patch(route('websites.update', $website), ['contact_email' => 'owner@example.com'])->assertSessionHasNoErrors();
        $this->actingAs($user)->patch(route('websites.update', $website), ['contact_email' => 'not-an-email'])->assertSessionHasErrors('contact_email');
        $this->actingAs($user)->get(route('listings.index', $website))->assertInertia(fn ($p) => $p->where('pack.contact_email', 'owner@example.com'));
    }
}
