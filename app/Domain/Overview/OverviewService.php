<?php

namespace App\Domain\Overview;

use App\Domain\Audit\AuditRules;
use App\Models\ActivityLog;
use App\Models\Website;
use App\Support\Enums\ContentStatus;

class OverviewService
{
    public function build(Website $website): array
    {
        $latest = $website->crawlRuns()->latest()->first();
        $severity = $website->issues()->where('status', 'open')->selectRaw('severity, COUNT(*) c')->groupBy('severity')->pluck('c', 'severity');

        $priority = $website->issues()->where('status', 'open')->with('page:id,canonical_url')
            ->orderByRaw("CASE severity WHEN 'high' THEN 1 WHEN 'medium' THEN 2 WHEN 'low' THEN 3 ELSE 4 END")
            ->orderBy('first_seen_at')->limit(6)->get()
            ->map(fn ($i) => [
                'id' => $i->id, 'rule_key' => $i->rule_key, 'severity' => $i->severity,
                'label' => AuditRules::get($i->rule_key)['label'], 'url' => $i->page?->canonical_url,
                'recommendation' => $i->recommendation,
            ]);

        $trend = $website->analytics()->where('date', '>=', now()->subDays(31))
            ->selectRaw('date, SUM(clicks) clicks, SUM(impressions) impressions')->groupBy('date')->orderBy('date')->get()
            ->map(fn ($r) => ['date' => $r->date->toDateString(), 'clicks' => (int) $r->clicks, 'impressions' => (int) $r->impressions]);

        return [
            'latest_crawl' => $latest,
            'pages_discovered' => $website->pages()->count(),
            'pages_ok' => $website->pages()->whereBetween('status_code', [200, 299])->count(),
            'issues_by_severity' => [
                'high' => (int) ($severity['high'] ?? 0), 'medium' => (int) ($severity['medium'] ?? 0),
                'low' => (int) ($severity['low'] ?? 0), 'info' => (int) ($severity['info'] ?? 0),
            ],
            'priority_issues' => $priority,
            'pending_drafts' => $website->contentDrafts()->whereIn('status', [ContentStatus::ContentDraft->value, ContentStatus::HumanEdit->value])->count(),
            'approved_drafts' => $website->contentDrafts()->where('status', ContentStatus::ApprovedForExport->value)->count(),
            'keywords' => $website->keywords()->count(),
            'prospects' => $website->backlinkProspects()->where('status', '!=', 'rejected')->count(),
            'traffic_trend' => $trend,
            'recent_activity' => ActivityLog::where('website_id', $website->id)->with('user:id,name')->latest()->limit(8)->get(),
            'next_actions' => $this->nextActions($website, $latest),
        ];
    }

    private function nextActions(Website $website, $latest): array
    {
        $actions = [];
        if (! $website->ownership_confirmed) {
            $actions[] = ['text' => 'Confirm you own or are authorised to analyse this website.', 'route' => 'websites.settings'];
        }
        if (! $latest) {
            $actions[] = ['text' => 'Run your first crawl to discover pages and technical issues.', 'route' => 'audit.index'];
        }
        if ($website->issues()->where('status', 'open')->where('severity', 'high')->exists()) {
            $actions[] = ['text' => 'Review high-severity issues first — they can stop pages appearing in search.', 'route' => 'audit.index'];
        }
        if (! $website->keywords()->exists()) {
            $actions[] = ['text' => 'Add seed keywords to research what your audience searches for.', 'route' => 'keywords.index'];
        }
        if ($website->contentDrafts()->whereIn('status', ['content_draft', 'human_edit'])->exists()) {
            $actions[] = ['text' => 'Drafts are waiting for your review.', 'route' => 'content.index'];
        }

        return array_slice($actions, 0, 4);
    }
}
