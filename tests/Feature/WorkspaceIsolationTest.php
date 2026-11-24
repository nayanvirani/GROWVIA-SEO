<?php

namespace Tests\Feature;

use App\Models\ContentDraft;
use App\Models\SeoIssue;
use App\Models\WebsitePage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_cannot_access_another_workspaces_data()
    {
        [$alice, $aliceSite] = $this->userWithWebsite();
        [$bob, $bobSite] = $this->userWithWebsite(['normalized_host' => 'bob.test', 'base_url' => 'https://bob.test/']);

        $page = WebsitePage::create(['website_id' => $aliceSite->id, 'canonical_url' => 'https://site.test/', 'url_hash' => str_repeat('a', 64), 'status_code' => 200]);
        $issue = SeoIssue::create(['website_id' => $aliceSite->id, 'page_id' => $page->id, 'rule_key' => 'missing_title', 'fingerprint' => str_repeat('b', 64),
            'severity' => 'high', 'recommendation' => 'x', 'first_seen_at' => now(), 'last_seen_at' => now()]);
        $draft = ContentDraft::create(['website_id' => $aliceSite->id, 'type' => 'blog_post', 'title' => 'Secret', 'body_markdown' => 'x']);

        $this->assertSame($aliceSite->workspace_id, $page->workspace_id, 'workspace_id is derived from the website');

        $this->actingAs($bob);
        foreach (['websites.show', 'audit.index', 'audit.pages', 'keywords.index', 'content.index', 'backlinks.index', 'analytics.index', 'automation.index', 'websites.settings'] as $name) {
            $this->get(route($name, $aliceSite))->assertForbidden();
        }
        $this->get(route('audit.page', [$aliceSite, $page]))->assertForbidden();
        $this->get(route('content.drafts.edit', [$aliceSite, $draft]))->assertForbidden();
        $this->post(route('audit.crawl', $aliceSite))->assertForbidden();

        // Scoped bindings: Bob's website id with Alice's child id must 404, not leak.
        $this->get(route('audit.page', [$bobSite, $page]))->assertNotFound();
        $this->get(route('content.drafts.edit', [$bobSite, $draft]))->assertNotFound();

        // JSON API
        $this->getJson(route('api.v1.issues.index', $aliceSite))->assertForbidden();
        $this->patchJson(route('api.v1.issues.update', $issue), ['status' => 'dismissed'])->assertForbidden();
        $this->patchJson(route('api.v1.content-drafts.update', $draft), ['title' => 'pwned'])->assertForbidden();
        $this->getJson(route('api.v1.content-drafts.export', $draft))->assertForbidden();
        $this->getJson(route('api.v1.websites.index'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $bobSite->id);

        $this->assertSame('open', $issue->fresh()->status);
        $this->assertSame('Secret', $draft->fresh()->title);
    }

    public function test_api_returns_consistent_envelope_for_owner()
    {
        [$user, $website] = $this->userWithWebsite();
        $this->actingAs($user)->getJson(route('api.v1.websites.overview', $website))->assertOk()->assertJsonStructure(['data' => ['issues_by_severity', 'pages_discovered']]);
        $this->actingAs($user)->getJson(route('api.v1.issues.index', $website))->assertOk()->assertJsonStructure(['data', 'meta' => ['total']]);
        $this->actingAs($user)->postJson(route('api.v1.websites.store'), ['name' => 'x'])->assertStatus(422)->assertJsonStructure(['error' => ['message', 'fields']]);
    }
}
