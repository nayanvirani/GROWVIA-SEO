<?php

namespace Tests\Feature;

use App\Domain\Content\QualityChecker;
use App\Models\ContentBrief;
use App\Models\ContentDraft;
use App\Models\Keyword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_brief_to_export_lifecycle_with_mock_provider()
    {
        [$user, $website] = $this->userWithWebsite();
        $kw = Keyword::create(['website_id' => $website->id, 'keyword' => 'shopify upsell app', 'provider' => 'mock']);

        $this->actingAs($user)->post(route('content.briefs.store', $website), [
            'title' => 'Shopify upsell apps explained', 'content_type' => 'blog_post', 'primary_keyword_id' => $kw->id,
            'source_notes' => 'Growvia offers post-purchase upsells.',
        ])->assertRedirect();
        $brief = ContentBrief::first();
        $this->assertSame('idea', $brief->status);

        $this->actingAs($user)->post(route('content.briefs.generate', [$website, $brief]))->assertRedirect();
        $draft = ContentDraft::first();
        $this->assertSame('content_draft', $draft->status);
        $this->assertSame('mock', $draft->provider);
        $this->assertSame('v1', $draft->prompt_version);
        $this->assertNotEmpty($brief->fresh()->outline_json['sections']);
        $this->assertContains('placeholder', collect($draft->quality_flags_json)->pluck('type'));
        $this->assertSame(1, $draft->revisions()->count());

        // Cannot export before approval; cannot approve with placeholders.
        $this->actingAs($user)->get(route('content.drafts.export', [$website, $draft]))->assertSessionHasErrors('draft');
        $this->actingAs($user)->post(route('content.drafts.approve', [$website, $draft]))->assertSessionHasErrors('draft');

        $this->actingAs($user)->patch(route('content.drafts.update', [$website, $draft]), [
            'body_markdown' => "# Shopify upsell apps\n\nA clear explanation of how a shopify upsell app helps stores. Guaranteed #1 rankings!",
            'change_summary' => 'Rewrote intro',
        ])->assertRedirect();
        $draft->refresh();
        $this->assertSame('human_edit', $draft->status);
        $this->assertContains('unsupported_claim', collect($draft->quality_flags_json)->pluck('type'));
        $this->assertSame(2, $draft->revisions()->count());

        $this->actingAs($user)->post(route('content.drafts.approve', [$website, $draft]))->assertRedirect();
        $this->assertSame('approved_for_export', $draft->fresh()->status);

        $md = $this->actingAs($user)->get(route('content.drafts.export', [$website, $draft, 'format' => 'md']));
        $md->assertOk()->assertHeader('Content-Disposition');
        $this->assertStringContainsString('# Shopify upsell apps', $md->getContent());
        $this->assertSame('exported', $draft->fresh()->status);

        $html = $this->actingAs($user)->get(route('content.drafts.export', [$website, $draft, 'format' => 'html']))->getContent();
        $this->assertStringContainsString('<h1>Shopify upsell apps</h1>', $html);

        // Manual publishing status, then editing reopens review and clears approval.
        $this->actingAs($user)->patch(route('content.drafts.update', [$website, $draft]), ['status' => 'published_externally']);
        $this->assertSame('published_externally', $draft->fresh()->status);
        $this->actingAs($user)->patch(route('content.drafts.update', [$website, $draft]), ['body_markdown' => 'Changed']);
        $this->assertSame('human_edit', $draft->fresh()->status);
        $this->assertNull($draft->fresh()->approved_at);

        $this->actingAs($user)->patch(route('content.drafts.update', [$website, $draft]), ['status' => 'approved_for_export'])->assertSessionHasErrors('status');
    }

    public function test_exported_html_escapes_untrusted_markup()
    {
        [$user, $website] = $this->userWithWebsite();
        $draft = ContentDraft::create(['website_id' => $website->id, 'type' => 'blog_post', 'title' => 'X', 'meta_title' => '<script>x</script>',
            'body_markdown' => 'Hello <script>alert(1)</script> [link](javascript:alert(1))', 'status' => 'approved_for_export']);

        $html = $this->actingAs($user)->get(route('content.drafts.export', [$website, $draft, 'format' => 'html']))->getContent();
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('javascript:', $html);
    }

    public function test_image_brief_and_meta_suggestions()
    {
        [$user, $website] = $this->userWithWebsite();
        $this->actingAs($user)->post(route('images.store', $website), ['topic' => 'free gift campaigns', 'aspect_ratio' => '1:1'])->assertRedirect();
        $img = $website->imageAssets()->first();
        $this->assertSame('1:1', $img->aspect_ratio);
        $this->assertNotEmpty($img->alt_text);
        $this->assertStringEndsWith('.webp', $img->suggested_filename);
        $this->actingAs($user)->get(route('images.index', $website))->assertOk();
    }

    public function test_quality_checks_avoid_false_alarms_but_catch_competitor_claims()
    {
        [$user, $website] = $this->userWithWebsite();
        $website->competitors()->create(['domain' => 'rival.com']);
        $check = fn (string $body, string $kw) => collect(app(QualityChecker::class)
            ->check(ContentDraft::create(['website_id' => $website->id, 'type' => 'blog_post', 'title' => 'x', 'body_markdown' => $body]), $kw))->pluck('type')->all();

        $this->assertSame([], $check("## What Is Cross-Selling in Shopify?\n\nCross-Selling vs. Upselling explained.", 'what is cross selling in shopify'));
        $this->assertContains('unverified_comparison', $check('Our app is better than Rival for shopify bundles.', 'shopify bundles'));
        $this->assertContains('keyword_missing', $check('Nothing about the topic here at all.', 'shopify bundles'));
    }

    public function test_generating_twice_needs_confirmation_and_running_jobs_are_not_duplicated()
    {
        [$user, $website] = $this->userWithWebsite();
        $brief = $website->contentBriefs()->create(['title' => 'Shopify bundles guide', 'status' => 'idea']);

        $this->actingAs($user)->post(route('content.briefs.generate', [$website, $brief]))->assertSessionHasNoErrors();
        $this->assertSame(1, $brief->drafts()->count());

        $this->actingAs($user)->post(route('content.briefs.generate', [$website, $brief]))->assertSessionHasErrors('brief');
        $this->assertSame(1, $brief->drafts()->count(), 'no accidental second version');

        $this->actingAs($user)->post(route('content.briefs.generate', [$website, $brief]), ['confirm' => true])->assertSessionHasNoErrors();
        $this->assertSame(2, $brief->drafts()->count());

        $brief->update(['status' => 'generating']);
        $this->actingAs($user)->post(route('content.briefs.generate', [$website, $brief]), ['confirm' => true])
            ->assertSessionHasErrors(['brief' => 'A draft for this brief is already being written. It appears in Drafts when ready.']);
    }
}
