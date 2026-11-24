<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Website;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /** Creates a local admin and the initial Growvia project (section 24). */
    public function run(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'admin@growvia.test'],
            ['name' => 'Growvia Admin', 'password' => 'password']
        );
        $user->forceFill(['email_verified_at' => now()])->save();
        $workspace = $user->fresh()->currentWorkspace;
        $workspace->update(['name' => 'Growvia']);

        $website = Website::firstOrNew(['workspace_id' => $workspace->id, 'normalized_host' => 'growvia.orderorbit.space']);
        $website->forceFill([
            'workspace_id' => $workspace->id,
            'name' => 'Growvia',
            'base_url' => 'https://growvia.orderorbit.space/',
            'website_type' => 'SaaS / Shopify app marketing website',
            'language' => 'en',
            'target_country' => null, // choose the market explicitly in settings
            'crawl_enabled' => true,
            'ownership_confirmed' => false, // confirm before the first production crawl
            'settings_json' => [
                'crawl_max_pages' => 300,
                'crawl_max_depth' => 5,
                'crawl_frequency' => 'weekly',
                'description' => 'Growvia is a Shopify app. Import brand facts from the live website and approved product docs.',
            ],
        ])->save();
    }
}
