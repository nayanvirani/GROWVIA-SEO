<?php

namespace App\Providers;

use App\Models;
use App\Policies\WebsitePolicy;
use App\Policies\WebsiteResourcePolicy;
use App\Policies\WorkspacePolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Gate::policy(Models\Website::class, WebsitePolicy::class);
        Gate::policy(Models\Workspace::class, WorkspacePolicy::class);

        foreach ([
            Models\CrawlRun::class, Models\WebsitePage::class, Models\SeoIssue::class, Models\Keyword::class,
            Models\KeywordCluster::class, Models\ContentBrief::class, Models\ContentDraft::class, Models\ImageAsset::class,
            Models\Competitor::class, Models\BacklinkProspect::class, Models\Backlink::class, Models\OutreachDraft::class,
            Models\AutomationRun::class, Models\ListingSubmission::class,
        ] as $model) {
            Gate::policy($model, WebsiteResourcePolicy::class);
        }

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('ai', fn (Request $request) => Limit::perMinute(20)->by($request->user()?->current_workspace_id ?: $request->ip()));
        RateLimiter::for('crawl', fn (Request $request) => Limit::perMinute(5)->by($request->user()?->current_workspace_id ?: $request->ip()));
    }
}
