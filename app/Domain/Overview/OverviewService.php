<?php

namespace App\Domain\Overview;

use App\Domain\Audit\AuditRules;
use App\Domain\Settings\ProviderSettings;
use App\Models\ActivityLog;
use App\Models\Website;
use App\Providers\AI\AiProviderFactory;
use App\Support\Enums\ContentStatus;

class OverviewService
{
    public function __construct(private readonly AiProviderFactory $ai) {}

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
            'top_keywords' => $website->keywords()->whereNotNull('opportunity_score')->orderByDesc('opportunity_score')->limit(5)
                ->get(['id', 'keyword', 'opportunity_score', 'intent', 'is_gap']),
            'prospects' => $website->backlinkProspects()->where('status', '!=', 'rejected')->count(),
            'existing_backlinks' => $website->backlinks()->count(),
            'autopilot' => [
                'enabled' => (bool) $website->setting('autopilot'),
                'ai_real' => $this->ai->isReal($website->workspace_id),
                'last_runs' => $website->automationRuns()->whereIn('automation_key', ['autopilot', 'keyword_research', 'backlink_discovery'])
                    ->latest('started_at')->limit(5)->get(['id', 'automation_key', 'status', 'started_at', 'error_message']),
                'running' => $website->automationRuns()->where('status', 'running')->exists()
                    || $website->crawlRuns()->whereIn('status', ['queued', 'running'])->exists()
                    || $website->contentBriefs()->where('status', 'generating')->exists(),
            ],
            'recent_activity' => ActivityLog::where('website_id', $website->id)->with('user:id,name')->latest()->limit(8)->get(),
            'todo' => $this->todo($website, $latest),
        ];
    }

    /** Recent backlink/mention runs failed because the key-free search was rate-limited. */
    private function searchBlocked(Website $w): bool
    {
        if (app(ProviderSettings::class)->get($w->workspace_id, 'search')['preset'] !== 'duckduckgo') {
            return false;
        }

        return $w->automationRuns()->whereIn('automation_key', ['backlink_discovery', 'brand_mentions'])->where('started_at', '>', now()->subDays(14))
            ->latest('started_at')->limit(3)->get()
            ->filter(fn ($r) => collect($r->result_json['errors'] ?? [])->contains(fn ($e) => str_contains((string) $e, 'DuckDuckGo')))
            ->count() >= 2;
    }

    /** A plain-English to-do list for someone who is not an SEO specialist. */
    private function todo(Website $w, $latest): array
    {
        $t = [];
        $add = function (string $text, string $why, string $route, string $priority = 'medium') use (&$t) {
            $t[] = compact('text', 'why', 'route', 'priority');
        };

        if (! $w->ownership_confirmed) {
            $add('Confirm you own this website', 'The app only crawls websites you are allowed to analyse.', 'websites.settings', 'high');
        }
        if (! $this->ai->isReal($w->workspace_id)) {
            $add('Connect a free AI provider', 'Without it, drafts are only templates. Gemini or Groq are free and take 2 minutes to set up.', 'providers.index', 'high');
        }
        if ($this->searchBlocked($w)) {
            $add('Add a free Tavily key so link searching works', 'DuckDuckGo keeps blocking automatic searches from your connection, so new backlink opportunities and brand mentions are not being found. Tavily is free (1,000 searches/month, no card).', 'providers.index', 'high');
        }
        if (! $latest) {
            $add('Run autopilot for the first time', 'It reads your site, finds problems, researches keywords and finds link opportunities.', 'websites.show', 'high');
        }
        if ($high = $w->issues()->where('status', 'open')->where('severity', 'high')->count()) {
            $add("Fix {$high} important website problem(s)", 'These can stop pages from appearing in Google. Each one explains exactly what to change.', 'audit.index', 'high');
        }
        if ($drafts = $w->contentDrafts()->whereIn('status', [ContentStatus::ContentDraft->value, ContentStatus::HumanEdit->value])->count()) {
            $add("Review {$drafts} article draft(s)", 'Read, correct any facts, then approve and copy it to your website.', 'content.index', 'medium');
        }
        if ($ideas = $w->contentBriefs()->where('status', ContentStatus::Idea->value)->count()) {
            $add("{$ideas} article idea(s) are ready to write", 'Autopilot picked topics people search for that your site does not cover yet. Click "Generate draft".', 'content.index', 'medium');
        }
        $toPublish = $w->contentDrafts()->whereIn('status', [ContentStatus::ApprovedForExport->value, ContentStatus::Exported->value])->whereNull('published_url')->first();
        if ($toPublish) {
            $add("Publish \"{$toPublish->title}\" on your blog", 'It is approved. Download it, publish it, then paste the post link in the draft so the app can check it.', 'content.index', 'high');
        }
        $linkWork = $w->contentDrafts()->whereNotNull('published_url')->get()
            ->sum(fn ($d) => count($d->publish_check_json['link_suggestions'] ?? []));
        if ($linkWork) {
            $add("Add {$linkWork} link(s) to your new articles from your other pages", 'Links from your own pages help Google find and rank new articles. Each suggestion shows the page and the text to link.', 'content.index', 'medium');
        }
        if ($ready = $w->backlinkProspects()->where('status', 'discovered')->whereHas('outreachDrafts', fn ($q) => $q->where('status', '!=', 'discarded'))->count()) {
            $add("Send {$ready} outreach message(s)", 'These sites list tools like yours. Personalise the drafted message and send it from your own email.', 'backlinks.index', 'medium');
        }
        $done = $w->listings()->whereIn('status', ['submitted', 'live', 'skipped'])->pluck('platform')->all();
        $next = collect(config('listings'))->sortBy('priority')->except($done)->first();
        if ($next) {
            $add("Get listed on {$next['name']}".($next['minutes'] ? " (about {$next['minutes']} min)" : ''),
                'A trusted site that links to you. Every field is written for you: open the page and paste.', 'listings.index', 'medium');
        }
        if ($med = $w->issues()->where('status', 'open')->where('severity', 'medium')->count()) {
            $add("Improve {$med} medium issue(s)", 'Mostly missing titles, descriptions or headings. Quick wins.', 'audit.index', 'low');
        }

        return array_slice($t, 0, 6);
    }
}
