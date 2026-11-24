<?php

namespace App\Domain\Keyword;

use App\Domain\AI\AiService;
use App\Jobs\GenerateContentDraft;
use App\Models\ContentBrief;
use App\Models\Keyword;
use App\Models\KeywordCluster;
use App\Models\PageKeywordTarget;
use App\Models\Website;
use App\Models\WebsitePage;
use App\Providers\AI\MockAiProvider;
use App\Providers\SEOData\KeywordResearchProvider;
use App\Support\ActivityLogger;
use App\Support\Enums\ContentStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class KeywordResearchService
{
    private const ARTICLE_PATH = '#/(blog|blogs|guides?|learn|resources|articles?|docs|help|academy|news|posts?)(/|$)#i';

    private const INTENT_POINTS = ['transactional' => 10, 'commercial' => 10, 'informational' => 7, 'navigational' => 2];

    public function __construct(private readonly KeywordResearchProvider $provider, private readonly AiService $ai) {}

    /** Fully automatic: find topics, expand them, score, map to pages and plan content. */
    public function autoResearch(Website $website): array
    {
        $seeds = $this->seeds($website);
        if (! $seeds) {
            return ['seeds' => [], 'keywords' => 0, 'note' => 'Not enough information yet. Run a crawl or add a website description in settings.'];
        }
        $keywords = $this->research($website, $seeds, $website->target_country ?: 'us', $website->language ?: 'en', 'autopilot');
        $briefs = $this->planContent($website);

        return ['seeds' => $seeds, 'keywords' => $keywords->count(), 'briefs_created' => $briefs];
    }

    /** @return list<string> */
    public function seeds(Website $website): array
    {
        $max = (int) config('seo.autopilot.max_seeds');
        $profile = SiteProfile::for($website);
        $seeds = [];

        if ($this->ai->isReal($website)) {
            $result = rescue(fn () => $this->ai->run($website, 'seed_topics', [
                'page_titles_untrusted' => WebsitePage::forWebsite($website)->where('status_code', 200)->orderBy('depth')
                    ->limit(40)->pluck('title')->filter()->values()->all(),
                'instructions' => "List {$max} short search phrases (2-4 words) that this website's potential customers type into Google when looking for what it offers. No brand names.",
            ]), null, false);
            $seeds = array_slice((array) ($result['data']['seeds'] ?? []), 0, $max);
        }

        $seeds = array_merge($seeds, $website->keywordSeeds()->where('source', 'user')->latest()->limit(5)->pluck('seed_text')->all(), $profile->phrases);

        return collect($seeds)->map(fn ($s) => Str::lower(trim(preg_replace('/\s+/', ' ', (string) $s))))
            ->filter(fn ($s) => mb_strlen($s) >= 4 && str_word_count($s) <= 5 && ! str_contains($s, Str::lower($website->name)))
            ->unique()->take($max)->values()->all();
    }

    /** @return Collection<int, Keyword> */
    public function research(Website $website, array $seeds, string $locale, string $language, string $seedSource = 'user'): Collection
    {
        $keywords = collect();
        foreach (array_unique(array_filter(array_map('trim', $seeds))) as $seed) {
            $website->keywordSeeds()->firstOrCreate(['seed_text' => Str::lower($seed), 'source' => $seedSource]);

            $cacheKey = 'kw:'.md5(Str::lower($seed).'|'.$locale.'|'.$language.'|'.$this->provider->name());
            $rows = Cache::remember($cacheKey, now()->addDays(config('seo.seo_data.cache_days')), function () use ($website, $seed, $locale) {
                ActivityLogger::usage($website, $this->provider->name(), 'keywords.suggestions', 1);

                return $this->provider->suggestions($seed, $locale);
            });

            foreach ($rows as $row) {
                $kw = Str::lower(trim(preg_replace('/\s+/', ' ', $row['keyword'])));
                if ($kw === '') {
                    continue;
                }
                $keywords->push(Keyword::updateOrCreate(
                    ['website_id' => $website->id, 'keyword' => $kw, 'locale' => $locale, 'language' => $language],
                    [
                        'workspace_id' => $website->workspace_id,
                        'volume' => $row['volume'], 'difficulty' => $row['difficulty'], 'cpc' => $row['cpc'],
                        'is_estimate' => $row['is_estimate'], 'provider' => $this->provider->name(),
                        'suggest_rank' => $row['rank'] ?? null, 'sources_json' => $row['sources'] ?? null,
                        'metric_date' => now()->toDateString(),
                    ]
                ));
            }
        }

        $keywords = $keywords->unique('id')->values();
        $this->classify($website, $keywords->whereNull('intent'));
        $this->cluster($website, $keywords);
        $this->scoreAndMap($website);
        ActivityLogger::log($website, 'keywords.researched', null, ['seeds' => $seeds, 'count' => $keywords->count()]);

        return $keywords;
    }

    public function addManual(Website $website, string $keyword, string $locale, string $language, ?string $intent): Keyword
    {
        $kw = Keyword::firstOrCreate(
            ['website_id' => $website->id, 'keyword' => Str::lower(trim($keyword)), 'locale' => $locale, 'language' => $language],
            ['workspace_id' => $website->workspace_id, 'intent' => $intent, 'provider' => 'manual', 'is_estimate' => false]
        );
        $this->scoreAndMap($website);

        return $kw->fresh();
    }

    /**
     * Opportunity score (0-100): our prioritisation, not search volume.
     * popularity 35 + sources agree 10 + relevance 35 + intent 10 + content gap 10.
     */
    public function scoreAndMap(Website $website): void
    {
        $profile = SiteProfile::for($website);
        $pages = WebsitePage::forWebsite($website)->where('status_code', 200)->get(['id', 'canonical_url', 'title', 'h1']);
        $common = $this->commonWords($pages);
        // Searchers who want to learn are served by articles, not product pages (intent match).
        $articles = $pages->filter(fn ($p) => preg_match(self::ARTICLE_PATH, (string) parse_url($p->canonical_url, PHP_URL_PATH)));

        foreach ($website->keywords()->with('pageTargets')->get() as $kw) {
            $target = $kw->pageTargets->first();
            $candidates = $kw->intent === 'informational' ? $articles : $pages;
            if (! $target && ($match = $this->bestPage($candidates, $kw->keyword, $common)) && $match['score'] >= 0.75) {
                $target = PageKeywordTarget::create(['website_id' => $website->id, 'page_id' => $match['page_id'], 'keyword_id' => $kw->id,
                    'target_type' => 'primary', 'notes' => 'Matched automatically']);
            }

            $relevance = $profile->relevance($kw->keyword);
            $popularity = $kw->suggest_rank
                ? max(5, (int) round(35 * (1 - (min($kw->suggest_rank, 10) - 1) / 10)))
                : ($kw->volume ? (int) min(35, round(log10(max($kw->volume, 1)) * 9)) : 12);
            $agree = count($kw->sources_json ?? []) >= 2 ? 10 : 0;
            $intent = self::INTENT_POINTS[$kw->intent] ?? 5;
            $gap = $target ? 0 : 10;
            $relevancePoints = (int) round($relevance * 35);
            $score = $popularity + $agree + $relevancePoints + $intent + $gap;
            if ($relevance < 0.34) {
                $score = (int) round($score * 0.4); // probably off-topic for this site
            }

            $kw->update([
                'opportunity_score' => min(100, $score),
                'is_gap' => ! $target,
                'opportunity_json' => [
                    'popularity' => ['points' => $popularity, 'why' => $kw->suggest_rank ? "Suggested at position {$kw->suggest_rank} when people type related searches" : 'No popularity signal'],
                    'sources' => ['points' => $agree, 'why' => $agree ? 'Suggested by both Google and DuckDuckGo' : 'Suggested by one source'],
                    'relevance' => ['points' => $relevancePoints, 'why' => sprintf('%d%% of its words match what your site talks about', $relevance * 100)],
                    'intent' => ['points' => $intent, 'why' => match ($kw->intent) {
                        'transactional' => 'Searcher is ready to act (install, buy, sign up)',
                        'commercial' => 'Searcher is comparing options — good for showing your product',
                        'informational' => 'Searcher wants to learn — good for helpful articles',
                        'navigational' => 'Searcher is looking for a specific site',
                        default => 'Intent unknown',
                    }],
                    'gap' => ['points' => $gap, 'why' => $gap ? 'No page on your site targets this yet' : 'Already covered by a page'],
                ],
            ]);
        }
    }

    /** Create briefs for the best uncovered topics (one per cluster) and optionally draft them. */
    public function planContent(Website $website): int
    {
        // Keep a small queue of unwritten ideas instead of piling up new ones every week.
        $waiting = $website->contentBriefs()->where('source', 'autopilot')->where('status', ContentStatus::Idea->value)->count();
        $limit = max(0, (int) config('seo.autopilot.auto_briefs') - $waiting);
        if ($limit === 0) {
            return 0;
        }
        $taken = ContentBrief::forWebsite($website)->whereNotNull('primary_keyword_id')->pluck('primary_keyword_id')->all();
        // Topics (clusters) that already have a brief are covered; don't plan a second article on them.
        $takenClusters = Keyword::whereIn('id', $taken)->with('clusters:id')->get()->flatMap(fn ($k) => $k->clusters->pluck('id'))->unique()->all();

        $candidates = $website->keywords()->with('clusters:id')->where('is_gap', true)->where('opportunity_score', '>=', 50)
            ->whereNotIn('intent', ['navigational'])->whereNotIn('id', $taken)->orderByDesc('opportunity_score')->limit(100)->get()
            ->reject(fn ($k) => $k->clusters->pluck('id')->intersect($takenClusters)->isNotEmpty())
            ->filter(fn ($k) => ($k->opportunity_json['relevance']['points'] ?? 0) >= 18) // clearly about this site
            ->reject(fn ($k) => preg_match('/\b(reddit|quora|youtube|tiktok|github|pdf|login|jobs?|salary|near me|download)\b/', $k->keyword)) // not article searches
            ->unique(fn ($k) => $k->clusters->first()?->id ?? $k->id);
        $candidates = $this->aiRelevanceCheck($website, $candidates)->take($limit);

        $created = [];
        foreach ($candidates as $kw) {
            $created[] = $website->contentBriefs()->create([
                'title' => Str::ucfirst($kw->keyword),
                'content_type' => $kw->intent === 'transactional' ? 'landing_page' : 'blog_post',
                'primary_keyword_id' => $kw->id,
                'intent' => $kw->intent,
                'audience' => Str::limit((string) $website->setting('description'), 250) ?: null,
                'source_notes' => $website->setting('brand_facts') ?: null,
                'status' => ContentStatus::Idea->value,
                'source' => 'autopilot',
            ]);
        }

        if ($created && $this->ai->isReal($website)) {
            foreach (array_slice($created, 0, (int) config('seo.autopilot.auto_drafts')) as $brief) {
                $brief->update(['status' => 'generating']);
                GenerateContentDraft::dispatch($brief->id);
            }
        }
        if ($created) {
            ActivityLogger::log($website, 'content.auto_planned', null, ['briefs' => count($created)]);
        }

        return count($created);
    }

    /**
     * With a real AI connected, ask it which ideas truly fit what the site offers (word matching
     * cannot tell "trust badges" from "can Shopify be trusted"). Rejected ideas are demoted.
     */
    private function aiRelevanceCheck(Website $website, Collection $candidates): Collection
    {
        if (! $this->ai->isReal($website) || $candidates->isEmpty()) {
            return $candidates->values();
        }
        $batch = $candidates->take(30);
        $result = rescue(fn () => $this->ai->run($website, 'relevance_check', [
            'page_titles_untrusted' => WebsitePage::forWebsite($website)->where('status_code', 200)->orderBy('depth')->limit(40)->pluck('title')->filter()->values()->all(),
            'keywords' => $batch->pluck('keyword')->values()->all(),
            'instructions' => 'For each keyword decide if an article targeting it would genuinely help THIS website\'s potential customers and relate to what it offers. '
                .'Questions about the platform itself (e.g. its pricing or plans) or unrelated services are not relevant. '
                .'Return {"results":[{"keyword":"...","relevant":true|false,"reason":"short"}]}.',
        ]), null, false);
        if (! $result) {
            return $candidates->values();
        }
        $verdicts = collect($result['data']['results'] ?? [])->keyBy(fn ($r) => Str::lower(trim((string) ($r['keyword'] ?? ''))));

        return $candidates->filter(function (Keyword $k) use ($verdicts) {
            $v = $verdicts[$k->keyword] ?? null;
            if ($v === null || ! empty($v['relevant'])) {
                return true;
            }
            $k->update([
                'opportunity_score' => (int) round($k->opportunity_score * 0.4),
                'opportunity_json' => ($k->opportunity_json ?? []) + ['ai_check' => ['points' => 0, 'why' => 'AI: '.Str::limit((string) ($v['reason'] ?? 'not a fit for this site'), 120)]],
            ]);

            return false;
        })->values();
    }

    private function classify(Website $website, Collection $keywords): void
    {
        foreach ($keywords->chunk(60) as $chunk) {
            $result = rescue(fn () => $this->ai->run($website, 'classify_intent', ['keywords' => $chunk->pluck('keyword')->values()->all()]), null, false);
            $map = collect($result['data']['results'] ?? [])->pluck('intent', 'keyword');
            foreach ($chunk as $kw) {
                $intent = $map[$kw->keyword] ?? null;
                if (! in_array($intent, array_keys(self::INTENT_POINTS), true)) {
                    $intent = MockAiProvider::heuristicIntent($kw->keyword);
                }
                $kw->update(['intent' => $intent]);
            }
        }
    }

    /** Group by the most significant shared words; users can rename clusters afterwards. */
    public function cluster(Website $website, Collection $keywords): void
    {
        $groups = $keywords->groupBy(fn (Keyword $k) => $this->clusterKey($k->keyword));
        foreach ($groups as $name => $members) {
            if ($name === '' || $members->count() < 2) {
                continue;
            }
            $primary = $members->sortBy(fn ($k) => [$k->suggest_rank ?? 99, -($k->volume ?? 0)])->first();
            $cluster = KeywordCluster::firstOrCreate(
                ['website_id' => $website->id, 'name' => Str::title($name)],
                ['workspace_id' => $website->workspace_id, 'primary_keyword_id' => $primary->id]
            );
            $cluster->keywords()->syncWithoutDetaching($members->pluck('id'));
        }
    }

    private function clusterKey(string $keyword): string
    {
        $tokens = array_values(array_filter(SiteProfile::words($keyword), fn ($w) => ! in_array($w, SiteProfile::STOPWORDS, true)));

        return implode(' ', array_slice($tokens, 0, 2));
    }

    /** Suggest the existing page whose title/H1/URL best overlaps the keyword. */
    public function suggestPage(Website $website, Keyword $keyword): ?array
    {
        $pages = WebsitePage::forWebsite($website)->where('status_code', 200)->get(['id', 'canonical_url', 'title', 'h1']);
        $best = $this->bestPage($pages, $keyword->keyword, $this->commonWords($pages));

        return ($best['score'] ?? 0) >= 0.5 ? $best : null;
    }

    /** Words on more than 40% of pages (like the platform name) say nothing about which page fits. */
    private function commonWords(Collection $pages): array
    {
        $df = [];
        foreach ($pages as $page) {
            foreach (array_unique(SiteProfile::words($page->title.' '.$page->h1)) as $w) {
                $df[$w] = ($df[$w] ?? 0) + 1;
            }
        }

        return array_keys(array_filter($df, fn ($c) => $pages->count() >= 5 && $c > $pages->count() * 0.4));
    }

    private function bestPage(Collection $pages, string $keyword, array $common = []): ?array
    {
        $generic = ['app', 'apps', 'tool', 'tools', 'plugin', 'plugins', 'software', 'add', 'use', 'set', 'create', 'make'];
        $tokens = array_values(array_filter(SiteProfile::words($keyword), fn ($w) => ! in_array($w, SiteProfile::STOPWORDS, true) && ! in_array($w, $common, true) && ! in_array($w, $generic, true) && ! SiteProfile::isNoise($w)));
        if (! $tokens) {
            return null;
        }
        $best = null;
        foreach ($pages as $page) {
            $hay = Str::lower($page->title.' '.$page->h1.' '.str_replace(['-', '/', '_'], ' ', (string) parse_url($page->canonical_url, PHP_URL_PATH)));
            $score = collect($tokens)->filter(fn ($t) => preg_match('/\b'.preg_quote(rtrim($t, 's'), '/').'/u', $hay))->count() / count($tokens);
            if ($score > ($best['score'] ?? 0)) {
                $best = ['page_id' => $page->id, 'url' => $page->canonical_url, 'title' => $page->title, 'score' => round($score, 2)];
            }
        }

        return $best;
    }
}
