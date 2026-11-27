<?php

namespace App\Domain\Automation;

use App\Domain\Backlink\BacklinkDiscovery;
use App\Domain\Backlink\BrandMentions;
use App\Domain\Backlink\ListingKit;
use App\Domain\Crawl\CrawlService;
use App\Domain\Keyword\KeywordResearchService;
use App\Jobs\RunAutomation;
use App\Models\Website;
use App\Notifications\StaleDraftsReminder;
use App\Notifications\WeeklyDigest;
use App\Support\Enums\ContentStatus;
use App\Support\Notifier;
use App\Support\Security\HostGuard;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

/** The concrete tasks behind each automation (guide section 18, plus autopilot). */
class Automations
{
    public function __construct(
        private readonly AutomationRunner $runner,
        private readonly CrawlService $crawls,
        private readonly KeywordResearchService $keywords,
        private readonly BacklinkDiscovery $discovery,
        private readonly BrandMentions $mentions,
        private readonly ListingKit $listings,
    ) {}

    public function execute(Website $website, string $key, string $trigger = 'schedule')
    {
        if ($trigger === 'schedule' && $this->runner->paused($website, $key)) {
            return null;
        }

        return $this->runner->run($website, $key, fn (Website $w) => match ($key) {
            'autopilot' => $this->autopilot($w, $trigger),
            'website_crawl' => ['crawl_run_id' => $this->crawls->queue($w, $trigger === 'manual' ? 'manual' : 'schedule')->id],
            'keyword_research' => $this->keywords->autoResearch($w),
            'backlink_discovery' => $this->discovery->discover($w),
            'brand_mentions' => $this->mentions->find($w),
            'listing_check' => $this->listings->verifyAll($w),
            'critical_issue_recheck' => $this->recheckCritical($w),
            'weekly_digest' => $this->digest($w),
            'stale_draft_reminder' => $this->staleDrafts($w),
        }, $trigger);
    }

    /**
     * Full pipeline. If a crawl can run, the research steps follow automatically when it
     * finishes (see followUp). Otherwise research runs straight away on what is known.
     */
    private function autopilot(Website $w, string $trigger): array
    {
        $canCrawl = $w->crawl_enabled && $w->ownership_confirmed
            && ! $w->crawlRuns()->whereIn('status', ['queued', 'running'])->exists();
        if ($canCrawl) {
            $run = $this->crawls->queue($w, $trigger === 'manual' ? 'manual' : 'schedule');

            return ['step' => 'crawl queued; keyword research and backlink discovery follow when it finishes', 'crawl_run_id' => $run->id];
        }
        $this->followUp($w, force: true);

        return ['step' => 'crawl not possible right now; research queued on existing data'];
    }

    /** Called after a successful crawl. Skips steps that ran in the last 7 days unless forced. */
    public function followUp(Website $w, bool $force = false): void
    {
        if (! $force && ! $w->setting('autopilot')) {
            return;
        }
        $recent = fn (string $key) => $w->automationRuns()->where('automation_key', $key)->where('status', 'succeeded')
            ->where('started_at', '>', now()->subDays(7))->exists();

        $jobs = [];
        if ($force || ! $recent('keyword_research')) {
            $jobs[] = new RunAutomation($w->id, 'keyword_research', 'autopilot');
        }
        if ($force || ! $recent('backlink_discovery')) {
            $jobs[] = new RunAutomation($w->id, 'backlink_discovery', 'autopilot');
        }
        if ($force || ! $recent('brand_mentions')) {
            $jobs[] = new RunAutomation($w->id, 'brand_mentions', 'autopilot');
        }
        if ($force || ! $recent('listing_check')) {
            $jobs[] = new RunAutomation($w->id, 'listing_check', 'autopilot');
        }
        if ($jobs) {
            Bus::chain($jobs)->onQueue('low')->dispatch();
        }
    }

    private function recheckCritical(Website $w): array
    {
        $checked = $changed = 0;
        foreach ($w->issues()->where('status', 'open')->whereIn('rule_key', ['http_error', 'broken_internal_link'])->with('page')->limit(50)->get() as $issue) {
            $url = $issue->evidence_json['target_url'] ?? $issue->page?->canonical_url;
            if (! $url || ! HostGuard::isAllowedUrl($url)) {
                continue;
            }
            $checked++;
            $status = rescue(fn () => Http::timeout(15)->withUserAgent(config('seo.crawler.user_agent'))
                ->withOptions(['allow_redirects' => ['max' => 5]])->head($url)->status(), null, false);
            if ($status && $status < 400) {
                // Do not resolve here; the next full crawl confirms it. Avoids repeated alerts.
                $issue->update(['evidence_json' => $issue->evidence_json + ['recheck_status' => $status, 'rechecked_at' => now()->toIso8601String()]]);
                $changed++;
            }
        }

        return compact('checked', 'changed');
    }

    private function digest(Website $w): array
    {
        $since = now()->subWeek();
        $summary = [
            'new_issues' => $w->issues()->where('first_seen_at', '>=', $since)->count(),
            'resolved_issues' => $w->issues()->where('resolved_at', '>=', $since)->count(),
            'open_high' => $w->issues()->where('status', 'open')->where('severity', 'high')->count(),
            'drafts_waiting_review' => $w->contentDrafts()->whereIn('status', [ContentStatus::ContentDraft->value, ContentStatus::HumanEdit->value])->count(),
            'new_prospects' => $w->backlinkProspects()->where('created_at', '>=', $since)->where('status', 'discovered')->count(),
            'new_keywords' => $w->keywords()->where('created_at', '>=', $since)->count(),
        ];
        Notifier::website($w, new WeeklyDigest($w, $summary));

        return $summary;
    }

    private function staleDrafts(Website $w): array
    {
        $stale = $w->contentDrafts()->whereIn('status', [ContentStatus::ContentDraft->value, ContentStatus::HumanEdit->value])
            ->where('updated_at', '<', now()->subDays(14))->get(['id', 'title']);
        $drafts = $stale->map(fn ($d) => ['id' => $d->id, 'title' => $d->title])->all();
        if ($drafts) {
            Notifier::website($w, new StaleDraftsReminder($w, $drafts));
        }

        return ['stale_drafts' => $drafts];
    }
}
