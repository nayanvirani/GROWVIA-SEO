<?php

namespace Tests\Feature;

use App\Domain\Automation\Automations;
use App\Models\AutomationRun;
use App\Models\Integration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnalyticsAutomationTest extends TestCase
{
    use RefreshDatabase;

    public function test_mock_search_console_sync_is_recorded_and_reported()
    {
        [$user, $website] = $this->userWithWebsite();
        $this->actingAs($user)->post(route('analytics.sync', $website))->assertRedirect();

        $run = AutomationRun::first();
        $this->assertSame('search_console_sync', $run->automation_key);
        $this->assertSame('succeeded', $run->status);
        $this->assertSame('manual', $run->trigger);
        $this->assertGreaterThan(0, $website->analytics()->count());

        $this->actingAs($user)->get(route('analytics.index', [$website, 'days' => 7]))->assertOk()
            ->assertInertia(fn ($p) => $p->where('report.source', 'mock')->has('report.queries'));

        // Re-sync replaces, not duplicates.
        $count = $website->analytics()->count();
        $this->actingAs($user)->post(route('analytics.sync', $website));
        $this->assertSame($count, $website->analytics()->count());
    }

    public function test_automations_record_runs_and_respect_pause()
    {
        [$user, $website] = $this->userWithWebsite();
        $automations = app(Automations::class);

        $run = $automations->execute($website, 'weekly_digest');
        $this->assertSame('succeeded', $run->status);
        $this->assertArrayHasKey('open_high', $run->result_json);

        $this->actingAs($user)->post(route('automation.toggle', [$website, 'weekly_digest']));
        $this->assertNull($automations->execute($website->fresh(), 'weekly_digest'));
        $this->assertNotNull($automations->execute($website->fresh(), 'weekly_digest', 'manual'), 'manual runs ignore pause');

        $this->fakeCrawler([$this->crawledPage('/')]);
        $crawl = $automations->execute($website, 'website_crawl');
        $this->assertSame('succeeded', $crawl->status, (string) $crawl->error_message);

        $website->update(['ownership_confirmed' => false]);
        $failed = $automations->execute($website, 'website_crawl');
        $this->assertSame('failed', $failed->status);
        $this->assertNotEmpty($failed->error_message);

        $this->actingAs($user)->get(route('automation.index', $website))->assertOk();
        $this->actingAs($user)->getJson(route('api.v1.automation-runs.index', $website))->assertOk()->assertJsonPath('meta.total', 4);
    }

    public function test_integration_credentials_are_encrypted_and_hidden()
    {
        [$user, $website] = $this->userWithWebsite();
        $i = Integration::create(['workspace_id' => $website->workspace_id, 'provider' => 'search_console', 'status' => 'connected',
            'encrypted_credentials' => ['refresh_token' => 'super-secret'], 'metadata_json' => ['property' => 'sc-domain:site.test', 'properties' => ['sc-domain:site.test']]]);

        $raw = \DB::table('integrations')->where('id', $i->id)->value('encrypted_credentials');
        $this->assertStringNotContainsString('super-secret', $raw);
        $this->assertArrayNotHasKey('encrypted_credentials', $i->toArray());

        $this->actingAs($user)->get(route('integrations.index'))->assertOk()->assertDontSee('super-secret');
    }
}
