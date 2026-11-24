<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WebsiteTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_website_project_with_competitors()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('websites.store'), [
            'name' => 'Growvia', 'base_url' => 'https://Growvia.OrderOrbit.space/some/path', 'language' => 'en',
            'ownership_confirmed' => true, 'competitors' => ['https://www.rival.com/', ''],
        ])->assertRedirect();

        $website = $user->currentWorkspace->websites()->first();
        $this->assertSame('https://growvia.orderorbit.space/', $website->base_url);
        $this->assertSame('growvia.orderorbit.space', $website->normalized_host);
        $this->assertSame(['rival.com'], $website->competitors()->pluck('domain')->all());
        $this->assertDatabaseHas('activity_logs', ['website_id' => $website->id, 'action' => 'website.created']);
    }

    public function test_ownership_confirmation_is_required()
    {
        $this->actingAs(User::factory()->create())
            ->post(route('websites.store'), ['name' => 'X', 'base_url' => 'https://x.com', 'language' => 'en'])
            ->assertSessionHasErrors('ownership_confirmed');
    }

    #[DataProvider('unsafeUrls')]
    public function test_unsafe_urls_are_rejected(string $url)
    {
        $this->actingAs(User::factory()->create())
            ->post(route('websites.store'), ['name' => 'X', 'base_url' => $url, 'language' => 'en', 'ownership_confirmed' => true])
            ->assertSessionHasErrors('base_url');
    }

    public static function unsafeUrls(): array
    {
        return [['http://localhost/'], ['http://127.0.0.1/'], ['http://10.0.0.8/'], ['http://169.254.169.254/'], ['ftp://example.com/'],
            ['http://intranet/'], ['http://server.internal/'], ['http://[::1]/']];
    }

    public function test_duplicate_host_in_workspace_is_rejected()
    {
        [$user] = $this->userWithWebsite();
        $this->actingAs($user)->post(route('websites.store'), ['name' => 'Dup', 'base_url' => 'https://www.site.test', 'language' => 'en', 'ownership_confirmed' => true])
            ->assertSessionHasErrors('base_url');
    }

    public function test_settings_update_merges_crawl_policy()
    {
        [$user, $website] = $this->userWithWebsite();
        $this->actingAs($user)->patch(route('websites.update', $website), [
            'crawl_max_pages' => 50, 'exclusion_paths' => "/cart\n /account \n", 'brand_facts' => 'Free plan available.',
        ])->assertRedirect();

        $website->refresh();
        $this->assertSame(50, $website->setting('crawl_max_pages'));
        $this->assertSame(['/cart', '/account'], $website->setting('exclusion_paths'));
        $this->assertSame(5, $website->setting('crawl_max_depth'));
    }
}
