<?php

use App\Jobs\RunAutomation;
use App\Models\CrawlRun;
use App\Models\Website;
use Illuminate\Support\Facades\Schedule;

/*
| Section 18 automation schedules. Each run is recorded in automation_runs and is idempotent;
| paused automations are skipped. Run `php artisan schedule:work` locally.
*/
$forEachWebsite = function (string $key, ?callable $filter = null) {
    return function () use ($key, $filter) {
        Website::where('status', '!=', 'archived')->each(function (Website $w) use ($key, $filter) {
            if (! $filter || $filter($w)) {
                RunAutomation::dispatch($w->id, $key);
            }
        });
    };
};

Schedule::call($forEachWebsite('website_crawl', fn (Website $w) => $w->crawl_enabled && $w->ownership_confirmed
    && $w->setting('crawl_frequency') === 'weekly'))->weeklyOn(1, '02:00')->name('website-crawl-weekly')->withoutOverlapping();
Schedule::call($forEachWebsite('website_crawl', fn (Website $w) => $w->crawl_enabled && $w->ownership_confirmed
    && $w->setting('crawl_frequency') === 'monthly'))->monthlyOn(1, '02:00')->name('website-crawl-monthly')->withoutOverlapping();
Schedule::call($forEachWebsite('critical_issue_recheck'))->dailyAt('03:00')->name('critical-issue-recheck')->withoutOverlapping();
Schedule::call($forEachWebsite('keyword_refresh'))->monthlyOn(1, '04:00')->name('keyword-refresh')->withoutOverlapping();
Schedule::call($forEachWebsite('backlink_recheck'))->monthlyOn(1, '05:00')->name('backlink-recheck')->withoutOverlapping();
Schedule::call($forEachWebsite('search_console_sync'))->dailyAt('06:00')->name('search-console-sync')->withoutOverlapping();
Schedule::call($forEachWebsite('weekly_digest'))->weeklyOn(1, '08:00')->name('weekly-digest')->withoutOverlapping();
Schedule::call($forEachWebsite('stale_draft_reminder'))->weeklyOn(1, '08:30')->name('stale-draft-reminder')->withoutOverlapping();

// Housekeeping: crawls stuck for >2h are marked failed (never resolves issues).
Schedule::call(fn () => CrawlRun::whereIn('status', CrawlRun::ACTIVE_STATUSES)->where('updated_at', '<', now()->subHours(2))
    ->update(['status' => 'failed', 'completed_at' => now(), 'error_summary' => json_encode(['message' => 'Timed out'])]))
    ->hourly()->name('stale-crawl-cleanup');
Schedule::command('queue:prune-failed --hours=720')->daily();
