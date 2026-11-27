<?php

namespace App\Domain\Backlink;

use App\Domain\Keyword\SiteProfile;
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
            // Other companies can share the name: require a topic word from this site nearby.
            $topicWords = array_slice(array_keys($profile->tokens), 0, 40);
            $about = $profile->context && stripos($text, $profile->context) !== false
                || collect($topicWords)->contains(fn ($w) => stripos($text, $w) !== false);
            if (! $about) {
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
                    rescue(fn () => $this->backlinks->outreachDraft($prospect), null, false);
                }
            }
        }
        ActivityLogger::log($website, 'backlinks.mentions', null, compact('checked', 'mentions', 'linked'));

        return ['queries' => count($queries), 'pages_checked' => $checked, 'unlinked_mentions' => $mentions, 'already_linked' => $linked, 'errors' => $errors];
    }

    private function title(string $html): ?string
    {
        return preg_match('#<title[^>]*>(.*?)</title>#si', $html, $m) ? Str::limit(trim(html_entity_decode(strip_tags($m[1]))), 250, '') : null;
    }
}
