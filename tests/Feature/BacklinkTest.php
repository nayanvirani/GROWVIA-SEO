<?php

namespace Tests\Feature;

use App\Domain\Backlink\BacklinkService;
use App\Models\BacklinkProspect;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class BacklinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_csv_import_scores_flags_and_exports()
    {
        [$user, $website] = $this->userWithWebsite();
        $csv = "source_url,page_title,domain_rating,notes\n".
            "https://shopify-blog.example.com/resources/best-ecommerce-tools,Best Shopify tools for merchants,60,\n".
            "https://cheap-links.example.xyz/buy-backlinks,Buy backlinks cheap,90,\n".
            "http://192.168.0.10/internal,Internal,,\n".
            "not a url,,,\n";

        $this->actingAs($user)->post(route('backlinks.import', $website), ['file' => UploadedFile::fake()->createWithContent('p.csv', $csv)])
            ->assertSessionHas('success');

        $good = BacklinkProspect::where('source_domain', 'shopify-blog.example.com')->first();
        $spam = BacklinkProspect::where('source_domain', 'cheap-links.example.xyz')->first();
        $this->assertSame(2, BacklinkProspect::count(), 'private IP and invalid rows skipped');
        $this->assertGreaterThan(40, $good->score);
        $this->assertSame('discovered', $good->status);
        $this->assertSame('unavailable', $good->score_breakdown_json['freshness']['evidence']);
        $this->assertSame(0, $spam->score, 'hard reject regardless of authority metric');
        $this->assertSame('rejected', $spam->status);
        $this->assertContains('link_farm_terms', $spam->risk_flags_json);

        $export = $this->actingAs($user)->get(route('backlinks.export', $website))->getContent();
        $this->assertStringContainsString('shopify-blog.example.com', $export);
    }

    public function test_outreach_requires_approval_before_marking_sent()
    {
        [$user, $website] = $this->userWithWebsite();
        $prospect = app(BacklinkService::class)->upsertProspect($website, [
            'source_url' => 'https://ecommerce-blog.example.com/blog/best-shopify-tools', 'page_title' => 'Best Shopify tools for merchants',
        ], 'manual');

        $this->actingAs($user)->post(route('backlinks.outreach.store', [$website, $prospect]))->assertRedirect();
        $outreach = $prospect->outreachDrafts()->first();
        $this->assertSame('draft', $outreach->status);

        $this->actingAs($user)->patch(route('backlinks.outreach.update', [$website, $outreach]), ['status' => 'sent'])->assertSessionHas('error');
        $this->actingAs($user)->patch(route('backlinks.outreach.update', [$website, $outreach]), ['status' => 'approved']);
        $this->actingAs($user)->patch(route('backlinks.outreach.update', [$website, $outreach]), ['status' => 'sent']);
        $this->assertNotNull($outreach->fresh()->sent_at);
        $this->assertSame('contacted', $prospect->fresh()->status);

        $prospect->update(['status' => 'rejected']);
        $this->actingAs($user)->post(route('backlinks.outreach.store', [$website, $prospect]))->assertSessionHasErrors('prospect');

        $competitor = $website->competitors()->create(['domain' => 'rival.com']);
        $this->actingAs($user)->patch(route('backlinks.competitors.toggle', [$website, $competitor]));
        $this->assertFalse($competitor->fresh()->active);
        $this->actingAs($user)->get(route('backlinks.index', $website))->assertOk();
    }
}
