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
        'autopilot' => ['label' => 'Autopilot (everything below, in order)', 'schedule' => 'Weekly (Mon 02:00)',
            'help' => 'Crawls the site, then researches keywords, plans content and finds link opportunities.'],
        'website_crawl' => ['label' => 'Website crawl & audit', 'schedule' => 'Part of autopilot',
            'help' => 'Reads every page and checks for technical problems.'],
        'keyword_research' => ['label' => 'Keyword research & content plan', 'schedule' => 'After each crawl (max weekly)',
            'help' => 'Finds what people search for, scores each idea and creates content briefs for gaps.'],
        'backlink_discovery' => ['label' => 'Backlink opportunities', 'schedule' => 'After keyword research (max weekly)',
            'help' => 'Finds pages that list tools like yours and drafts outreach messages for you to send.'],
        'brand_mentions' => ['label' => 'Brand mentions', 'schedule' => 'After backlink search (max weekly)',
            'help' => 'Finds pages that mention your brand without linking to you and drafts a short request.'],
        'listing_check' => ['label' => 'Listing check', 'schedule' => 'Weekly with autopilot',
            'help' => 'Checks that your directory listings are live and link back to your website.'],
        'critical_issue_recheck' => ['label' => 'Broken page recheck', 'schedule' => 'Daily 03:00',
            'help' => 'Re-checks broken pages and links between full crawls.'],
        'weekly_digest' => ['label' => 'Weekly summary', 'schedule' => 'Weekly (Mon 08:00)',
            'help' => 'An in-app summary of what changed.'],
        'stale_draft_reminder' => ['label' => 'Draft reminder', 'schedule' => 'Weekly (Mon 08:30)',
            'help' => 'Reminds you about drafts waiting more than 14 days.'],
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
