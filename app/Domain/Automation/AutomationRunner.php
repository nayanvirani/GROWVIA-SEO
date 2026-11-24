<?php

namespace App\Domain\Automation;

use App\Models\AutomationRun;
use App\Models\Website;
use Illuminate\Support\Str;
use Throwable;

/** Wraps any scheduled or manual task with an auditable automation_runs record. */
class AutomationRunner
{
    public const AUTOMATIONS = [
        'website_crawl' => ['label' => 'Website crawl', 'schedule' => 'Weekly (Mon 02:00)'],
        'critical_issue_recheck' => ['label' => 'Critical issue recheck', 'schedule' => 'Daily 03:00'],
        'keyword_refresh' => ['label' => 'Keyword refresh', 'schedule' => 'Monthly (1st, 04:00)'],
        'backlink_recheck' => ['label' => 'Backlink recheck', 'schedule' => 'Monthly (1st, 05:00)'],
        'search_console_sync' => ['label' => 'Search Console sync', 'schedule' => 'Daily 06:00'],
        'weekly_digest' => ['label' => 'Weekly SEO digest', 'schedule' => 'Weekly (Mon 08:00)'],
        'stale_draft_reminder' => ['label' => 'Stale draft reminder', 'schedule' => 'Weekly (Mon 08:30)'],
    ];

    public function run(Website $website, string $key, callable $task, string $trigger = 'schedule'): AutomationRun
    {
        // Idempotency: skip if the same automation is already running for this website.
        $existing = $website->automationRuns()->where('automation_key', $key)->where('status', 'running')
            ->where('started_at', '>', now()->subHours(2))->first();
        if ($existing) {
            return $existing;
        }

        $run = AutomationRun::create([
            'workspace_id' => $website->workspace_id, 'website_id' => $website->id,
            'automation_key' => $key, 'trigger' => $trigger, 'status' => 'running', 'started_at' => now(),
        ]);

        try {
            $result = $task($website);
            $run->update(['status' => 'succeeded', 'finished_at' => now(), 'result_json' => is_array($result) ? $result : ['result' => $result]]);
        } catch (Throwable $e) {
            report($e);
            $run->update(['status' => 'failed', 'finished_at' => now(), 'error_message' => Str::limit($e->getMessage(), 1000)]);
        }

        return $run;
    }

    public function paused(Website $website, string $key): bool
    {
        return in_array($key, $website->setting('paused_automations') ?? [], true);
    }
}
