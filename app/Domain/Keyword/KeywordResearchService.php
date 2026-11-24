<?php

namespace App\Domain\Keyword;

use App\Domain\AI\AiService;
use App\Models\Keyword;
use App\Models\KeywordCluster;
use App\Models\Website;
use App\Models\WebsitePage;
use App\Providers\SEOData\KeywordResearchProvider;
use App\Support\ActivityLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class KeywordResearchService
{
    private const STOPWORDS = ['for', 'the', 'a', 'an', 'and', 'or', 'to', 'of', 'in', 'on', 'with', 'how', 'use', 'best', 'free', 'vs', 'what', 'is'];

    public function __construct(private readonly KeywordResearchProvider $provider, private readonly AiService $ai) {}

    /** @return Collection<int, Keyword> */
    public function research(Website $website, array $seeds, string $locale, string $language): Collection
    {
        $keywords = collect();
        foreach (array_unique(array_filter(array_map('trim', $seeds))) as $seed) {
            $website->keywordSeeds()->create(['seed_text' => $seed, 'source' => 'user']);

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
                        'metric_date' => now()->toDateString(),
                    ]
                ));
            }
        }

        $keywords = $keywords->unique('id')->values();
        $this->classify($website, $keywords->whereNull('intent'));
        $this->cluster($website, $keywords);
        ActivityLogger::log($website, 'keywords.researched', null, ['seeds' => $seeds, 'count' => $keywords->count()]);

        return $keywords;
    }

    public function addManual(Website $website, string $keyword, string $locale, string $language, ?string $intent): Keyword
    {
        return Keyword::firstOrCreate(
            ['website_id' => $website->id, 'keyword' => Str::lower(trim($keyword)), 'locale' => $locale, 'language' => $language],
            ['workspace_id' => $website->workspace_id, 'intent' => $intent, 'provider' => 'manual', 'is_estimate' => false]
        );
    }

    private function classify(Website $website, Collection $keywords): void
    {
        foreach ($keywords->chunk(50) as $chunk) {
            $result = $this->ai->run($website, 'classify_intent', ['keywords' => $chunk->pluck('keyword')->values()->all()]);
            $map = collect($result['data']['results'] ?? [])->pluck('intent', 'keyword');
            foreach ($chunk as $kw) {
                $intent = $map[$kw->keyword] ?? null;
                if (in_array($intent, ['informational', 'commercial', 'transactional', 'navigational'], true)) {
                    $kw->update(['intent' => $intent]);
                }
            }
        }
    }

    /** Group by the most significant shared token; users can edit clusters afterwards. */
    public function cluster(Website $website, Collection $keywords): void
    {
        $groups = $keywords->groupBy(fn (Keyword $k) => $this->clusterKey($k->keyword));
        foreach ($groups as $name => $members) {
            if ($name === '' || $members->count() < 2) {
                continue;
            }
            $primary = $members->sortByDesc('volume')->first();
            $cluster = KeywordCluster::firstOrCreate(
                ['website_id' => $website->id, 'name' => Str::title($name)],
                ['workspace_id' => $website->workspace_id, 'primary_keyword_id' => $primary->id]
            );
            $cluster->keywords()->syncWithoutDetaching($members->pluck('id'));
        }
    }

    private function clusterKey(string $keyword): string
    {
        $tokens = array_values(array_diff(preg_split('/\s+/', Str::lower($keyword)), self::STOPWORDS));

        return implode(' ', array_slice($tokens, 0, 2));
    }

    /** Suggest the existing page whose title/H1/URL best overlaps the keyword. */
    public function suggestPage(Website $website, Keyword $keyword): ?array
    {
        $tokens = array_diff(preg_split('/\s+/', $keyword->keyword), self::STOPWORDS);
        $best = null;
        foreach (WebsitePage::forWebsite($website)->where('status_code', 200)->get(['id', 'canonical_url', 'title', 'h1']) as $page) {
            $hay = Str::lower($page->title.' '.$page->h1.' '.str_replace(['-', '/'], ' ', $page->canonical_url));
            $score = collect($tokens)->filter(fn ($t) => str_contains($hay, $t))->count() / max(count($tokens), 1);
            if ($score > ($best['score'] ?? 0)) {
                $best = ['page_id' => $page->id, 'url' => $page->canonical_url, 'title' => $page->title, 'score' => round($score, 2)];
            }
        }

        return ($best['score'] ?? 0) >= 0.5 ? $best : null;
    }
}
