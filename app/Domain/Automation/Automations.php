<?php

namespace App\Domain\Automation;

use App\Domain\Analytics\AnalyticsService;
use App\Domain\Backlink\BacklinkService;
use App\Domain\Crawl\CrawlService;
use App\Domain\Keyword\KeywordResearchService;
use App\Models\ProviderUsage;
use App\Models\Website;
use App\Notifications\StaleDraftsReminder;
use App\Notifications\WeeklyDigest;
use App\Support\Enums\ContentStatus;
use App\Support\Notifier;
use App\Support\Security\HostGuard;
use Illuminate\Support\Facades\Http;

/** The concrete tasks behind each scheduled automation (section 18). */
class Automations
{
    public function __construct(
        private readonly AutomationRunner $runner,
        private readonly CrawlService $crawls,
        private readonly AnalyticsService $analytics,
        private readonly KeywordResearchService $keywords,
        private readonly BacklinkService $backlinks,
    ) {}

    public function execute(Website $website, string $key, string $trigger = 'schedule')
    {
        if ($trigger === 'schedule' && $this->runner->paused($website, $key)) {
            return null;
        }

        return $this->runner->run($website, $key, fn (Website $w) => match ($key) {
            'website_crawl' => ['crawl_run_id' => $this->crawls->queue($w, 'schedule')->id],
            'critical_issue_recheck' => $this->recheckCritical($w),
            'keyword_refresh' => $this->refreshKeywords($w),
            'backlink_recheck' => $this->recheckBacklinks($w),
            'search_console_sync' => ['rows' => $this->analytics->sync($w, 7)],
            'weekly_digest' => $this->digest($w),
            'stale_draft_reminder' => $this->staleDrafts($w),
        }, $trigger);
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
                // Do not resolve here; flag so the next full crawl confirms it. Avoid repeated alerts.
                $issue->update(['evidence_json' => $issue->evidence_json + ['recheck_status' => $status, 'rechecked_at' => now()->toIso8601String()]]);
                $changed++;
            }
        }

        return compact('checked', 'changed');
    }

    private function refreshKeywords(Website $w): array
    {
        $seeds = $w->keywordSeeds()->distinct()->pluck('seed_text')->take(20)->all();
        $locale = $w->target_country ?: 'us';

        return ['keywords' => $seeds ? $this->keywords->research($w, $seeds, $locale, $w->language)->count() : 0];
    }

    private function recheckBacklinks(Website $w): array
    {
        $total = 0;
        foreach ($w->competitors()->where('active', true)->get() as $c) {
            [$imported] = $this->backlinks->importFromProvider($w, $c);
            $total += $imported;
        }

        return ['prospects_updated' => $total];
    }

    private function digest(Website $w): array
    {
        $since = now()->subWeek();

        $summary = [
            'new_issues' => $w->issues()->where('first_seen_at', '>=', $since)->count(),
            'resolved_issues' => $w->issues()->where('resolved_at', '>=', $since)->count(),
            'open_high' => $w->issues()->where('status', 'open')->where('severity', 'high')->count(),
            'drafts_waiting_review' => $w->contentDrafts()->whereIn('status', [ContentStatus::ContentDraft->value, ContentStatus::HumanEdit->value])->count(),
            'clicks_last_7d' => (int) $w->analytics()->where('date', '>=', now()->subDays(10))->sum('clicks'),
            'provider_requests' => ProviderUsage::where('website_id', $w->id)->where('created_at', '>=', $since)->count(),
        ];
        Notifier::website($w, new WeeklyDigest($w, $summary));

        return $summary + ['notified' => $w->workspace->members()->count()];
    }

    private function staleDrafts(Website $w): array
    {
        $stale = $w->contentDrafts()->whereIn('status', [ContentStatus::ContentDraft->value, ContentStatus::HumanEdit->value])
            ->where('updated_at', '<', now()->subDays(14))->get(['id', 'title']);

        $drafts = $stale->map(fn ($d) => ['id' => $d->id, 'title' => $d->title])->all();
        if ($drafts) {
            Notifier::website($w, new StaleDraftsReminder($w, $drafts));
        }

        return ['stale_drafts' => $drafts, 'notified' => (bool) $drafts];
    }
}
