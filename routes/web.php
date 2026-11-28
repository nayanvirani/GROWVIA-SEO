<?php

use App\Http\Controllers\Web;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => auth()->check() ? redirect()->route('dashboard') : Inertia::render('Welcome'))->name('home');

Route::get('invitations/{token}', [Web\TeamController::class, 'showInvitation'])->name('invitations.show');

Route::middleware(['auth'])->group(function () {
    Route::post('invitations/{token}/accept', [Web\TeamController::class, 'acceptInvitation'])->name('invitations.accept');
    Route::get('team', [Web\TeamController::class, 'index'])->name('team.index');
    Route::patch('team', [Web\TeamController::class, 'updateWorkspace'])->name('team.update');
    Route::post('team/invitations', [Web\TeamController::class, 'invite'])->name('team.invite');
    Route::delete('team/invitations/{invitation}', [Web\TeamController::class, 'revoke'])->name('team.invitations.revoke');
    Route::patch('team/members/{member}', [Web\TeamController::class, 'updateMember'])->name('team.members.update');
    Route::delete('team/members/{member}', [Web\TeamController::class, 'removeMember'])->name('team.members.remove');
    Route::post('workspaces/{workspace}/switch', [Web\TeamController::class, 'switch'])->name('workspaces.switch');

    Route::get('dashboard', [Web\WebsiteController::class, 'dashboard'])->name('dashboard');
    Route::get('websites/create', [Web\WebsiteController::class, 'create'])->name('websites.create');
    Route::post('websites', [Web\WebsiteController::class, 'store'])->name('websites.store');

    Route::get('notifications', [Web\NotificationController::class, 'index'])->name('notifications.index');
    Route::get('notifications/{id}', [Web\NotificationController::class, 'open'])->name('notifications.open');
    Route::post('notifications/read-all', [Web\NotificationController::class, 'readAll'])->name('notifications.read-all');

    Route::get('providers', [Web\ProviderController::class, 'index'])->name('providers.index');
    Route::post('providers/ai/models', [Web\ProviderController::class, 'models'])->name('providers.models');
    Route::patch('providers/{kind}', [Web\ProviderController::class, 'update'])->name('providers.update');
    Route::post('providers/{kind}/test', [Web\ProviderController::class, 'test'])->middleware('throttle:ai')->name('providers.test');

    Route::prefix('websites/{website}')->middleware('can:view,website')->scopeBindings()->group(function () {
        Route::get('/', [Web\WebsiteController::class, 'show'])->name('websites.show');
        Route::get('settings', [Web\WebsiteController::class, 'edit'])->name('websites.settings');
        Route::patch('settings', [Web\WebsiteController::class, 'update'])->name('websites.update');
        Route::delete('/', [Web\WebsiteController::class, 'destroy'])->name('websites.destroy');
        Route::post('verification', [Web\WebsiteController::class, 'startVerification'])->name('websites.verification.start');
        Route::post('verification/check', [Web\WebsiteController::class, 'checkVerification'])->name('websites.verification.check');

        Route::get('audit', [Web\AuditController::class, 'index'])->name('audit.index');
        Route::post('crawl', [Web\AuditController::class, 'crawl'])->middleware('throttle:crawl')->name('audit.crawl');
        Route::get('pages', [Web\AuditController::class, 'pages'])->name('audit.pages');
        Route::get('pages/{page}', [Web\AuditController::class, 'page'])->name('audit.page');
        Route::post('pages/{page}/meta-suggestions', [Web\ContentController::class, 'metaSuggestions'])->middleware('throttle:ai')->name('content.meta-suggestions');
        Route::patch('issues/{issue}', [Web\AuditController::class, 'updateIssue'])->name('audit.issues.update');
        Route::post('issues/{issue}/explain', [Web\AuditController::class, 'explain'])->middleware('throttle:ai')->name('audit.issues.explain');

        Route::get('keywords', [Web\KeywordController::class, 'index'])->name('keywords.index');
        Route::post('keywords/research', [Web\KeywordController::class, 'research'])->middleware('throttle:ai')->name('keywords.research');
        Route::post('keywords', [Web\KeywordController::class, 'store'])->name('keywords.store');
        Route::patch('keywords/{keyword}', [Web\KeywordController::class, 'update'])->name('keywords.update');
        Route::delete('keywords/{keyword}', [Web\KeywordController::class, 'destroy'])->name('keywords.destroy');
        Route::post('keywords/{keyword}/map', [Web\KeywordController::class, 'map'])->name('keywords.map');
        Route::post('keywords/{keyword}/brief', [Web\KeywordController::class, 'brief'])->name('keywords.brief');
        Route::post('keywords/{keyword}/write', [Web\KeywordController::class, 'write'])->middleware('throttle:ai')->name('keywords.write');
        Route::get('keywords/export', [Web\KeywordController::class, 'export'])->name('keywords.export');
        Route::patch('clusters/{cluster}', [Web\KeywordController::class, 'updateCluster'])->name('keywords.clusters.update');

        Route::get('content', [Web\ContentController::class, 'index'])->name('content.index');
        Route::post('content/briefs', [Web\ContentController::class, 'storeBrief'])->name('content.briefs.store');
        Route::post('content/briefs/{brief}/generate', [Web\ContentController::class, 'generate'])->middleware('throttle:ai')->name('content.briefs.generate');
        Route::delete('content/briefs/{brief}', [Web\ContentController::class, 'destroyBrief'])->name('content.briefs.destroy');
        Route::get('content/drafts/{draft}', [Web\ContentController::class, 'edit'])->name('content.drafts.edit');
        Route::patch('content/drafts/{draft}', [Web\ContentController::class, 'update'])->name('content.drafts.update');
        Route::post('content/drafts/{draft}/approve', [Web\ContentController::class, 'approve'])->name('content.drafts.approve');
        Route::post('content/drafts/{draft}/published', [Web\ContentController::class, 'published'])->name('content.drafts.published');
        Route::get('content/drafts/{draft}/export', [Web\ContentController::class, 'export'])->name('content.drafts.export');

        Route::get('images', [Web\ImageController::class, 'index'])->name('images.index');
        Route::post('images', [Web\ImageController::class, 'store'])->middleware('throttle:ai')->name('images.store');
        Route::patch('images/{image}', [Web\ImageController::class, 'update'])->name('images.update');
        Route::delete('images/{image}', [Web\ImageController::class, 'destroy'])->name('images.destroy');

        Route::get('backlinks', [Web\BacklinkController::class, 'index'])->name('backlinks.index');
        Route::post('competitors', [Web\BacklinkController::class, 'storeCompetitor'])->name('backlinks.competitors.store');
        Route::delete('competitors/{competitor}', [Web\BacklinkController::class, 'destroyCompetitor'])->name('backlinks.competitors.destroy');
        Route::patch('competitors/{competitor}', [Web\BacklinkController::class, 'toggleCompetitor'])->name('backlinks.competitors.toggle');
        Route::post('backlinks/discover', [Web\BacklinkController::class, 'discover'])->name('backlinks.discover');
        Route::post('backlinks/import', [Web\BacklinkController::class, 'importCsv'])->name('backlinks.import');
        Route::get('backlinks/export', [Web\BacklinkController::class, 'export'])->name('backlinks.export');
        Route::patch('prospects/{prospect}', [Web\BacklinkController::class, 'updateProspect'])->name('backlinks.prospects.update');
        Route::post('prospects/{prospect}/outreach', [Web\BacklinkController::class, 'outreach'])->middleware('throttle:ai')->name('backlinks.outreach.store');
        Route::patch('outreach/{outreach}', [Web\BacklinkController::class, 'updateOutreach'])->name('backlinks.outreach.update');

        Route::get('recommendations', [Web\RecommendationController::class, 'index'])->name('recommendations.index');
        Route::post('recommendations/refresh', [Web\RecommendationController::class, 'refresh'])->name('recommendations.refresh');
        Route::patch('recommendations/{recommendation}', [Web\RecommendationController::class, 'update'])->name('recommendations.update');
        Route::post('recommendations/{recommendation}/brief', [Web\RecommendationController::class, 'brief'])->name('recommendations.brief');
        Route::get('listings', [Web\ListingController::class, 'index'])->name('listings.index');
        Route::post('listings/regenerate', [Web\ListingController::class, 'regenerate'])->middleware('throttle:ai')->name('listings.regenerate');
        Route::post('listings/mentions', [Web\ListingController::class, 'findMentions'])->name('listings.mentions');
        Route::patch('listings/{platform}', [Web\ListingController::class, 'update'])->name('listings.update');

        Route::get('automation', [Web\AutomationController::class, 'index'])->name('automation.index');
        Route::post('automation/{key}/run', [Web\AutomationController::class, 'run'])->name('automation.run');
        Route::post('automation/{key}/toggle', [Web\AutomationController::class, 'toggle'])->name('automation.toggle');
    });
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
