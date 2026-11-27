<?php

namespace App\Domain\Backlink;

use App\Domain\Keyword\SiteProfile;
use App\Jobs\DraftOutreach;
use App\Models\Website;
use App\Providers\Search\SearchProviderFactory;
use App\Support\ActivityLogger;
use App\Support\Security\SafeFetcher;
use App\Support\Url;
use Illuminate\Support\Str;
use Throwable;

/**
 * Finds pages that already mention the brand. Mentions without a link are the easiest backlinks:
 * the writer already likes you, so a short "could you link it?" note often works.
 */
class BrandMentions
{
    public function __construct(private readonly SearchProviderFactory $search, private readonly BacklinkService $backlinks) {}

    public function find(Website $website): array
    {
        $provider = $this->search->forWorkspace($website->workspace_id);
        if (! $provider) {
            return ['skipped' => 'Web search is turned off in Settings → AI & data providers.'];
        }
        $brand = trim($website->name);
        $profile = SiteProfile::for($website);
        $queries = array_values(array_filter(["\"{$brand}\"", $profile->context ? "\"{$brand}\" {$profile->context}" : null, "\"{$brand}\" review"]));

        $urls = [];
        $errors = [];
        foreach ($queries as $q) {
            try {
                foreach ($provider->search($q, 10) as $r) {
                    $host = rescue(fn () => Url::host($r['url']), null, false);
                    if ($host && $host !== $website->normalized_host && ! BacklinkDiscovery::skipped($host)) {
                        $urls[$r['url']] ??= $q;
                    }
                }
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
                break;
            }
        }

        $known = $website->backlinkProspects()->pluck('source_url_hash')->flip();
        $mentions = $linked = $checked = 0;
        foreach (array_slice($urls, 0, 15, true) as $url => $query) {
            if (isset($known[Url::hash($url)])) {
                continue;
            }
            $checked++;
            $res = SafeFetcher::get($url);
            if (! $res['html'] || $res['status'] >= 400) {
                continue;
            }
            $text = preg_replace('/\s+/', ' ', strip_tags(preg_replace('#<(script|style)[^>]*>.*?</\1>#si', ' ', $res['html'])));
            if (! preg_match('/.{0,120}\b'.preg_quote($brand, '/').'\b.{0,120}/iu', $text, $m)) {
                continue; // search result without a real mention
            }
            // Other companies can share the name: make sure the mention is about THIS business.
            if (! $this->isAboutUs($website, $profile, $res['final_url'], $text, $res['html'])) {
                continue;
            }

            preg_match('/<a\b[^>]*href=["\']https?:\/\/(www\.)?'.preg_quote($website->normalized_host, '/').'[^"\']*["\']/i', $res['html'], $link);
            $prospect = $this->backlinks->upsertProspect($website, [
                'source_url' => $res['final_url'],
                'page_title' => $this->title($res['html']),
                'target_url' => $website->base_url,
                'notes' => trim($m[0]),
                'editorial_hint' => true,
            ], 'autopilot', $link ? 'existing_link' : 'unlinked_mention', [
                'found_by_search' => $query,
                'mention' => Str::limit(trim($m[0]), 240),
            ]);
            if (! $prospect) {
                continue;
            }
            if ($link) {
                $linked++;
                $prospect->update(['status' => 'placed']);
                $website->backlinks()->updateOrCreate(
                    ['source_url' => $prospect->source_url, 'target_url' => $website->base_url],
                    ['source_domain' => $prospect->source_domain, 'first_seen_at' => now(), 'last_seen_at' => now(), 'status' => 'active', 'provider' => 'autopilot']
                );
            } else {
                $mentions++;
                if (! $prospect->outreachDrafts()->exists()) {
                    DraftOutreach::dispatch($prospect->id);
                }
            }
        }
        ActivityLogger::log($website, 'backlinks.mentions', null, compact('checked', 'mentions', 'linked'));

        return ['queries' => count($queries), 'pages_checked' => $checked, 'unlinked_mentions' => $mentions, 'already_linked' => $linked, 'errors' => $errors];
    }

    private const GENERIC = ['growth', 'free', 'sales', 'sale', 'store', 'stores', 'shop', 'business', 'marketing', 'tools', 'online',
        'ecommerce', 'best', 'guide', 'platform', 'services', 'digital', 'brand', 'customer', 'customers'];

    /**
     * A mention counts only when it is not on a same-named company's own site, the page talks about
     * the site's platform (e.g. Shopify), and a core topic word appears near the brand name, or the
     * page refers to our domain.
     */
    public function isAboutUs(Website $website, SiteProfile $profile, string $url, string $text, string $html): bool
    {
        $brand = Str::lower(preg_replace('/[^\p{L}\p{N}]+/u', '', $website->name));
        $host = rescue(fn () => Url::host($url), '', false);
        if ($brand !== '' && str_contains(str_replace(['-', '.'], '', $host), $brand)) {
            return false; // e.g. growviagroup.com: a different business with the same name
        }
        if (stripos($html, $website->normalized_host) !== false) {
            return true; // refers to our domain
        }
        if ($profile->context && stripos($text, $profile->context) === false) {
            return false;
        }
        $core = collect($profile->phrases)->take(8)
            ->flatMap(fn ($p) => SiteProfile::words($p))
            ->reject(fn ($w) => $w === $profile->context || mb_strlen($w) < 4 || in_array($w, SiteProfile::STOPWORDS, true) || in_array($w, self::GENERIC, true))
            ->map(fn ($w) => preg_replace('/(ies|s)$/', '', $w))->unique()->values();
        if ($core->isEmpty()) {
            return true;
        }
        preg_match_all('/.{0,250}\b'.preg_quote($website->name, '/').'\b.{0,250}/iu', $text, $windows);
        foreach ($windows[0] as $window) {
            if ($core->contains(fn ($w) => stripos($window, $w) !== false)) {
                return true;
            }
        }

        return false;
    }

    private function title(string $html): ?string
    {
        return preg_match('#<title[^>]*>(.*?)</title>#si', $html, $m) ? Str::limit(trim(html_entity_decode(strip_tags($m[1]))), 250, '') : null;
    }
}
