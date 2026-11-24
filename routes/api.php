<?php

use App\Http\Controllers\Api\V1;
use Illuminate\Support\Facades\Route;

/*
| Versioned JSON API (section 15). Session-authenticated for the first-party UI;
| every endpoint is scoped to the authenticated user's workspace via policies.
*/
Route::prefix('v1')->middleware(['web', 'auth', 'throttle:api'])->name('api.v1.')->group(function () {
    Route::get('websites', [V1\WebsiteController::class, 'index'])->name('websites.index');
    Route::post('websites', [V1\WebsiteController::class, 'store'])->name('websites.store');

    Route::prefix('websites/{website}')->middleware('can:view,website')->scopeBindings()->group(function () {
        Route::get('overview', [V1\WebsiteController::class, 'overview'])->name('websites.overview');
        Route::post('crawl-runs', [V1\CrawlController::class, 'store'])->middleware('throttle:crawl')->name('crawl-runs.store');
        Route::get('crawl-runs', [V1\CrawlController::class, 'index'])->name('crawl-runs.index');
        Route::get('pages', [V1\CrawlController::class, 'pages'])->name('pages.index');
        Route::get('issues', [V1\IssueController::class, 'index'])->name('issues.index');
        Route::post('keyword-research', [V1\KeywordController::class, 'research'])->middleware('throttle:ai')->name('keyword-research');
        Route::get('keywords', [V1\KeywordController::class, 'index'])->name('keywords.index');
        Route::post('content-briefs', [V1\ContentController::class, 'storeBrief'])->name('content-briefs.store');
        Route::post('backlink-imports', [V1\BacklinkController::class, 'import'])->name('backlink-imports.store');
        Route::get('backlink-prospects', [V1\BacklinkController::class, 'prospects'])->name('backlink-prospects.index');
        Route::post('analytics/sync', [V1\AnalyticsController::class, 'sync'])->name('analytics.sync');
        Route::get('automation-runs', [V1\AnalyticsController::class, 'automationRuns'])->name('automation-runs.index');
    });

    Route::patch('issues/{issue}', [V1\IssueController::class, 'update'])->name('issues.update');
    Route::post('content-briefs/{brief}/generate', [V1\ContentController::class, 'generate'])->middleware('throttle:ai')->name('content-briefs.generate');
    Route::patch('content-drafts/{draft}', [V1\ContentController::class, 'updateDraft'])->name('content-drafts.update');
    Route::post('content-drafts/{draft}/approve', [V1\ContentController::class, 'approve'])->name('content-drafts.approve');
    Route::get('content-drafts/{draft}/export', [V1\ContentController::class, 'export'])->name('content-drafts.export');
    Route::post('backlink-prospects/{prospect}/outreach-draft', [V1\BacklinkController::class, 'outreach'])->middleware('throttle:ai')->name('outreach-draft.store');
    Route::post('integrations/search-console/connect', [V1\AnalyticsController::class, 'connect'])->name('integrations.search-console.connect');
});
