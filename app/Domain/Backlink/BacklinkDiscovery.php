<?php

namespace App\Domain\Backlink;

use App\Jobs\RunAutomation;
use App\Models\Keyword;
use App\Models\Website;
use App\Providers\Search\SearchProviderFactory;
use App\Support\ActivityLogger;
use App\Support\Security\SafeFetcher;
use App\Support\Url;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Finds link opportunities automatically: pages that list or review tools in the site's niche.
 *
 * 1. Search for "best <topic>", "<topic> resources", ... and keep editorial pages.
 * 2. Open each page. Apps that several of these pages link to are competitors.
 * 3. A page that already links to competitors is a strong prospect; one that already links
 *    to this site is an existing backlink. Blogs run by competitors are flagged.
 * Nothing is ever submitted or sent automatically.
 */
class BacklinkDiscovery
{
    /** Platforms where you cannot earn an editorial link, or where link building is spam. */
    private const SKIP_DOMAINS = ['google.', 'youtube.com', 'facebook.com', 'twitter.com', 'x.com', 'linkedin.com', 'instagram.com',
        'pinterest.', 'tiktok.com', 'wikipedia.org', 'amazon.', 'reddit.com', 'quora.com', 'github.com', 'apple.com',
        'apps.shopify.com', 'shopify.com', 'community.shopify.com', 'bing.com', 'duckduckgo.com', 'yahoo.com', 'trustpilot.com',
        'g2.com', 'capterra.com', 'wordpress.org', 'medium.com', 'substack.com', 'w3.org', 'schema.org', 'cloudflare.com', 'gstatic.com'];

    /** App stores: a listing URL identifies a competing product (apps.shopify.com/<app>). */
    private const MARKETPLACES = ['apps.shopify.com' => ['categories', 'collections', 'search', 'partners', 'stories', 'login', 'sitemap']];

    private const LISTICLE = '/\b(best|top\s*\d*|\d+\s+(best|top)|alternatives?|tools|apps|plugins|software|list|review(s|ed)?|compared|vs)\b/i';

    private const RESOURCE = '/\b(resources?|directory|library|toolkit|toolbox|stack|guide|ultimate)\b/i';

    private const MAX_RETRIES_PER_DAY = 4;

    public function __construct(
        private readonly SearchProviderFactory $search,
        private readonly BacklinkService $backlinks,
    ) {}

    public function discover(Website $website): array
    {
        $provider = $this->search->forWorkspace($website->workspace_id);
        if (! $provider) {
            return ['skipped' => 'Web search is turned off in Settings → AI & data providers.'];
        }
        $topics = $this->topics($website);
        if (! $topics) {
            return ['skipped' => 'No topics yet. Keyword research runs first and provides them.'];
        }

        $queries = [];
        foreach ($topics as $t) {
            array_push($queries, "best {$t}", "{$t} resources", "top {$t} tools");
        }
        $queries = array_slice(array_unique($queries), 0, (int) config('seo.autopilot.max_search_queries'));

        // 1. Search (results are cached, so a resumed run continues where it stopped).
        $candidates = [];
        $homepagesSeen = [];
        $errors = [];
        $done = 0;
        foreach ($queries as $query) {
            try {
                $results = $provider->search($query, 10);
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
                break;
            }
            $done++;
            foreach ($results as $r) {
                $host = rescue(fn () => Url::host($r['url']), null, false);
                if (! $host || $host === $website->normalized_host || $this->skipped($host)) {
                    continue;
                }
                $type = $this->classify($r['url'], $r['title']);
                if ($type === 'homepage') {
                    $homepagesSeen[$host] = ($homepagesSeen[$host] ?? 0) + 1;

                    continue;
                }
                $candidates[$r['url']] ??= $r + ['type' => $type, 'query' => $query, 'host' => $host];
            }
        }
        ActivityLogger::usage($website, $provider->name(), 'backlinks.search', $done);

        // 2. Inspect the most promising pages (max 2 per domain), skipping ones already saved.
        $known = $website->backlinkProspects()->pluck('source_url_hash')->flip();
        $inspected = [];
        $perDomain = [];
        foreach ($candidates as $url => $c) {
            if (count($inspected) >= (int) config('seo.autopilot.max_prospect_checks')) {
                break;
            }
            if (isset($known[Url::hash($url)]) || ($perDomain[$c['host']] = ($perDomain[$c['host']] ?? 0) + 1) > 2) {
                continue;
            }
            $facts = $this->inspect($url, $website->normalized_host);
            if ($facts['status'] !== null && $facts['status'] < 400) {
                $inspected[] = $c + ['facts' => $facts];
            }
        }

        // 3. Competitors = products linked from several of these pages (or repeatedly ranking homepages).
        $linkCounts = [];
        foreach ($inspected as $i) {
            foreach ($i['facts']['linked'] as $id) {
                $linkCounts[$id] = ($linkCounts[$id] ?? 0) + 1;
            }
        }
        $this->recordCompetitors($website, $linkCounts, $homepagesSeen);
        $competitors = $website->competitors()->where('active', true)->pluck('domain')->all();

        // 4. Save scored prospects.
        $saved = $alreadyLinking = 0;
        foreach ($inspected as $c) {
            $f = $c['facts'];
            $linkedCompetitors = array_values(array_intersect($f['linked'], $competitors));
            $owned = in_array($c['host'], $competitors, true) || in_array(Url::host($f['final_url']), $competitors, true);

            $prospect = $this->backlinks->upsertProspect($website, [
                'source_url' => $f['final_url'],
                'page_title' => $f['title'] ?: $c['title'],
                'target_url' => $website->base_url,
                'notes' => $c['snippet'],
                'outbound_links' => $f['outbound_links'],
                'last_updated' => $f['year'] ? $f['year'].'-06-30' : null,
                'competitor_links' => count($linkedCompetitors),
                'editorial_hint' => in_array($c['type'], ['listicle', 'resource_page', 'blog'], true),
                'risk_flags' => $owned ? ['competitor_owned'] : [],
            ], 'autopilot', $f['links_to_us'] ? 'existing_link' : $c['type'], [
                'found_by_search' => $c['query'],
                'competitors_linked' => $linkedCompetitors,
                'links_to_you' => $f['links_to_us'],
                'year_mentioned' => $f['year'],
            ]);
            if (! $prospect) {
                continue;
            }
            $saved++;
            if ($f['links_to_us']) {
                $alreadyLinking++;
                $prospect->update(['status' => 'placed']);
                $website->backlinks()->updateOrCreate(
                    ['source_url' => $prospect->source_url, 'target_url' => $f['links_to_us']],
                    ['source_domain' => $prospect->source_domain, 'anchor_text' => $f['our_anchor'], 'rel_attributes' => $f['our_rel'],
                        'first_seen_at' => now(), 'last_seen_at' => now(), 'status' => 'active', 'provider' => 'autopilot']
                );
            }
        }

        $drafted = $this->draftOutreach($website);
        $resume = $errors && $done < count($queries) ? $this->scheduleResume($website) : false;
        ActivityLogger::log($website, 'backlinks.discovered', null, compact('done', 'saved', 'alreadyLinking', 'drafted'));

        return [
            'queries' => count($queries), 'queries_done' => $done, 'pages_checked' => count($inspected), 'prospects_saved' => $saved,
            'already_linking_to_you' => $alreadyLinking, 'outreach_drafts' => $drafted,
            'competitors' => $website->competitors()->where('active', true)->where('source', 'autopilot')->pluck('domain')->all(),
            'errors' => $errors, 'will_resume' => $resume,
        ];
    }

    /** @return list<string> */
    public function topics(Website $website): array
    {
        $fromClusters = $website->keywordClusters()->with('primaryKeyword')->get()
            ->map(fn ($c) => $c->primaryKeyword)->filter()
            ->sortByDesc('opportunity_score')->pluck('keyword');
        $fromKeywords = Keyword::forWebsite($website)->where('opportunity_score', '>=', 50)->orderByDesc('opportunity_score')->limit(10)->pluck('keyword');

        return $fromClusters->merge($fromKeywords)
            ->map(fn ($k) => trim(preg_replace('/^(best|top|how to|what is)\s+/i', '', $k)))
            ->filter(fn ($k) => str_word_count($k) >= 2)
            ->unique()->take(4)->values()->all();
    }

    private function classify(string $url, string $title): string
    {
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
        if (preg_match(self::LISTICLE, $title)) {
            return 'listicle';
        }
        if (preg_match(self::RESOURCE, $title.' '.$path)) {
            return 'resource_page';
        }
        if (preg_match('#(^|/)(blog|guides?|articles?|news|learn|posts?)(/|$)#i', $path)) {
            return 'blog';
        }

        return $path === '' ? 'homepage' : 'blog';
    }

    private function skipped(string $host): bool
    {
        foreach (self::SKIP_DOMAINS as $d) {
            $matches = str_ends_with($d, '.')
                ? str_starts_with($host, $d) || str_contains($host, '.'.$d)   // "google." = any google TLD
                : $host === $d || str_ends_with($host, '.'.$d);
            if ($matches) {
                return true;
            }
        }

        return false;
    }

    /** Identity of a linked product: its domain, or its app-store listing (apps.shopify.com/<app>). */
    private function identity(string $href, string $host): ?string
    {
        if (isset(self::MARKETPLACES[$host])) {
            $slug = Str::lower(explode('/', trim((string) parse_url($href, PHP_URL_PATH), '/'))[0] ?? '');

            return $slug !== '' && ! in_array($slug, self::MARKETPLACES[$host], true) && preg_match('/^[a-z0-9-]+$/', $slug) ? "{$host}/{$slug}" : null;
        }

        return $this->skipped($host) ? null : $host;
    }

    private function recordCompetitors(Website $website, array $linkCounts, array $homepagesSeen): void
    {
        foreach ($linkCounts + $homepagesSeen as $id => $_) {
            $count = ($linkCounts[$id] ?? 0) + ($homepagesSeen[$id] ?? 0);
            if ($count < 2) {
                continue;
            }
            $c = $website->competitors()->firstOrNew(['domain' => $id]);
            if (! $c->exists) {
                $c->fill(['source' => 'autopilot', 'active' => true]);
            }
            $c->seen_count = max($c->seen_count, $count);
            $c->save();
        }
    }

    /** Fetch one page and pull out the facts used for scoring. */
    private function inspect(string $url, string $ourHost): array
    {
        $res = SafeFetcher::get($url);
        $facts = ['status' => $res['status'], 'final_url' => $res['final_url'], 'title' => null, 'outbound_links' => null,
            'linked' => [], 'links_to_us' => null, 'our_anchor' => null, 'our_rel' => null, 'year' => null];
        if (! $res['html']) {
            return $facts;
        }

        $doc = new \DOMDocument;
        @$doc->loadHTML('<?xml encoding="utf-8"?>'.$res['html'], LIBXML_NONET | LIBXML_NOERROR);
        $facts['title'] = trim((string) ($doc->getElementsByTagName('title')->item(0)?->textContent ?? '')) ?: null;
        $pageHost = rescue(fn () => Url::host($res['final_url']), '', false);
        $external = 0;
        $linked = [];
        foreach ($doc->getElementsByTagName('a') as $a) {
            $href = $a->getAttribute('href');
            if (! preg_match('#^https?://#i', $href)) {
                continue;
            }
            $host = rescue(fn () => Url::host($href), null, false);
            if (! $host || $host === $pageHost) {
                continue;
            }
            $external++;
            if ($host === $ourHost && ! $facts['links_to_us']) {
                $facts['links_to_us'] = Url::normalize($href);
                $facts['our_anchor'] = Str::limit(trim($a->textContent), 200);
                $facts['our_rel'] = $a->getAttribute('rel') ?: null;
            }
            if ($host !== $ourHost && ($id = $this->identity($href, $host))) {
                $linked[$id] = true;
            }
        }
        $facts['outbound_links'] = $external;
        $facts['linked'] = array_keys($linked);
        if (preg_match_all('/\b(20[2-3]\d)\b/', (string) $facts['title'].' '.mb_substr(strip_tags($res['html']), 0, 5000), $m)) {
            $years = array_filter(array_map('intval', $m[1]), fn ($y) => $y <= now()->year + 1);
            $facts['year'] = $years ? max($years) : null;
        }

        return $facts;
    }

    private function draftOutreach(Website $website): int
    {
        $limit = (int) config('seo.autopilot.auto_outreach');
        $prospects = $website->backlinkProspects()->where('status', 'discovered')->where('score', '>=', 35)
            ->whereDoesntHave('outreachDrafts')->orderByDesc('score')->limit($limit)->get()
            ->reject(fn ($p) => in_array('competitor_owned', $p->risk_flags_json ?? [], true));
        $count = 0;
        foreach ($prospects as $p) {
            if (rescue(fn () => $this->backlinks->outreachDraft($p), null, false)) {
                $count++;
            }
        }

        return $count;
    }

    /** Search was throttled: continue later (cached results make each retry progress). */
    private function scheduleResume(Website $website): bool
    {
        $key = 'backlink-resume:'.$website->id.':'.now()->toDateString();
        if (Cache::get($key, 0) >= self::MAX_RETRIES_PER_DAY) {
            return false;
        }
        Cache::increment($key);
        Cache::put($key, Cache::get($key), now()->endOfDay());
        RunAutomation::dispatch($website->id, 'backlink_discovery', 'autopilot')->delay(now()->addMinutes(25));

        return true;
    }
}
