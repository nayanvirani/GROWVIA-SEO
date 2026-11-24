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

Schedule::call($forEachWebsite('autopilot', fn (Website $w) => $w->setting('autopilot')
    && $w->setting('crawl_frequency') !== 'manual'))->weeklyOn(1, '02:00')->name('autopilot-weekly')->withoutOverlapping();
Schedule::call($forEachWebsite('critical_issue_recheck'))->dailyAt('03:00')->name('critical-issue-recheck')->withoutOverlapping();
Schedule::call($forEachWebsite('weekly_digest'))->weeklyOn(1, '08:00')->name('weekly-digest')->withoutOverlapping();
Schedule::call($forEachWebsite('stale_draft_reminder'))->weeklyOn(1, '08:30')->name('stale-draft-reminder')->withoutOverlapping();

// Housekeeping: crawls stuck for >2h are marked failed (never resolves issues).
Schedule::call(fn () => CrawlRun::whereIn('status', CrawlRun::ACTIVE_STATUSES)->where('updated_at', '<', now()->subHours(2))
    ->update(['status' => 'failed', 'completed_at' => now(), 'error_summary' => json_encode(['message' => 'Timed out'])]))
    ->hourly()->name('stale-crawl-cleanup');
Schedule::command('queue:prune-failed --hours=720')->daily();
