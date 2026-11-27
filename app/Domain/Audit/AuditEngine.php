<?php

namespace App\Domain\Audit;

use App\Models\CrawlRun;
use App\Models\PageLink;
use App\Models\SeoIssue;
use App\Models\Website;
use App\Models\WebsitePage;
use App\Support\Security\SafeFetcher;
use App\Support\Url;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class AuditEngine
{
    /** @var array<string, array> fingerprint => issue attributes */
    private array $found = [];

    /** Evaluate all pages and return the number of open issues for the website. */
    public function run(CrawlRun $run): int
    {
        $this->found = [];
        $website = $run->website;
        $cfg = config('seo.audit');

        $pages = WebsitePage::forWebsite($website)->get();
        $byHash = $pages->keyBy('url_hash');
        $indexable = $pages->filter(fn (WebsitePage $p) => $p->isHtml() && $p->isIndexable());

        foreach ($pages as $page) {
            $this->pageRules($page, $website->normalized_host, $cfg);
        }

        $this->duplicates($indexable, 'title', 'duplicate_title');
        $this->duplicates($indexable, 'meta_description', 'duplicate_meta_description');
        $this->linkRules($run, $byHash);

        if ($root = $pages->firstWhere('depth', 0)) {
            $this->aiSearchRule($run->website, $root);
        }

        if (empty($run->fresh()->sitemaps_json) && $root = $pages->firstWhere('depth', 0)) {
            $this->add('missing_sitemap', $root, ['checked' => ['robots.txt Sitemap directives', '/sitemap.xml']]);
        }

        return $this->persist($run, $pages);
    }

    private function pageRules(WebsitePage $page, string $host, array $cfg): void
    {
        if ($page->status_code >= 400 || ($page->status_code === null && $page->fetch_error)) {
            $this->add('http_error', $page, ['status_code' => $page->status_code, 'error' => $page->fetch_error]);

            return;
        }
        if (! $page->isHtml() || $page->status_code >= 300) {
            return;
        }

        $robots = strtolower(trim($page->robots_meta.' '.$page->x_robots_tag));
        if (str_contains($robots, 'noindex')) {
            $this->add('noindex_page', $page, ['robots_meta' => $page->robots_meta, 'x_robots_tag' => $page->x_robots_tag]);
        }

        $title = trim((string) $page->title);
        if ($title === '') {
            $this->add('missing_title', $page, ['title' => null]);
        } elseif (mb_strlen($title) < $cfg['title_min'] || mb_strlen($title) > $cfg['title_max']) {
            $this->add('title_length', $page, ['title' => $title, 'length' => mb_strlen($title),
                'reviewed_range' => "{$cfg['title_min']}–{$cfg['title_max']} characters (heuristic)"]);
        }

        $desc = trim((string) $page->meta_description);
        if ($desc === '') {
            $this->add('missing_meta_description', $page, ['meta_description' => null]);
        } elseif (mb_strlen($desc) < $cfg['description_min'] || mb_strlen($desc) > $cfg['description_max']) {
            $this->add('meta_description_length', $page, ['meta_description' => $desc, 'length' => mb_strlen($desc),
                'reviewed_range' => "{$cfg['description_min']}–{$cfg['description_max']} characters (heuristic)"]);
        }

        $h1s = $page->headings_json['h1'] ?? [];
        if (count($h1s) === 0) {
            $this->add('missing_h1', $page, ['h1_count' => 0]);
        } elseif (count($h1s) > 1) {
            $this->add('multiple_h1', $page, ['h1_count' => count($h1s), 'h1' => array_slice($h1s, 0, 5)]);
        }

        if (! $page->canonical_url_found) {
            $this->add('missing_canonical', $page, ['canonical' => null]);
        } else {
            try {
                $canonical = Url::normalize($page->canonical_url_found);
                $self = Url::normalize($page->final_url ?: $page->canonical_url);
                if (! Url::sameSite($canonical, $host) || $canonical !== $self) {
                    $this->add('canonical_mismatch', $page, [
                        'canonical' => $page->canonical_url_found, 'page_url' => $self,
                        'off_host' => ! Url::sameSite($canonical, $host),
                    ]);
                }
            } catch (\InvalidArgumentException) {
                $this->add('canonical_mismatch', $page, ['canonical' => $page->canonical_url_found, 'invalid' => true]);
            }
        }

        $missingAlt = collect($page->images_json ?? [])->filter(fn ($img) => ! array_key_exists('alt', $img) || $img['alt'] === null);
        if ($missingAlt->isNotEmpty()) {
            $this->add('missing_image_alt', $page, ['count' => $missingAlt->count(), 'images' => $missingAlt->pluck('src')->take(10)->values()]);
        }

        if ($page->word_count < $cfg['thin_content_words'] && ! str_contains($robots, 'noindex')) {
            $this->add('thin_content_signal', $page, ['word_count' => $page->word_count, 'reviewed_below' => $cfg['thin_content_words'].' words (heuristic)']);
        }
    }

    /** Bots that fetch pages to answer a user's question or show search results (not training crawlers). */
    public const AI_SEARCH_AGENTS = ['OAI-SearchBot', 'ChatGPT-User', 'PerplexityBot', 'Perplexity-User', 'Claude-SearchBot',
        'Claude-User', 'DuckAssistBot', 'MistralAI-User', 'YouBot'];

    private function aiSearchRule(Website $website, WebsitePage $root): void
    {
        $res = SafeFetcher::get(rtrim($website->base_url, '/').'/robots.txt');
        $text = $res['status'] === 200 ? $res['body'] : null;
        if (! $text) {
            return;
        }
        $blocked = self::blockedAgents($text, self::AI_SEARCH_AGENTS);
        if ($blocked) {
            $this->add('ai_search_blocked', $root, ['blocked_search_bots' => $blocked, 'robots_txt' => rtrim($website->base_url, '/').'/robots.txt']);
        }
    }

    /** Agents (from $agents) whose robots.txt group disallows the whole site. */
    public static function blockedAgents(string $robots, array $agents): array
    {
        $groups = [];
        $current = [];
        $inRules = false;
        foreach (preg_split('/\r?\n/', $robots) as $line) {
            $line = trim(preg_replace('/#.*$/', '', $line));
            if ($line === '' || ! str_contains($line, ':')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode(':', $line, 2));
            $key = strtolower($key);
            if ($key === 'user-agent') {
                if ($inRules) {
                    $current = [];
                    $inRules = false;
                }
                $current[] = strtolower($value);
            } elseif (in_array($key, ['disallow', 'allow'], true)) {
                $inRules = true;
                foreach ($current as $ua) {
                    $groups[$ua][] = [$key, $value];
                }
            }
        }

        return array_values(array_filter($agents, function ($agent) use ($groups) {
            $rules = $groups[strtolower($agent)] ?? null;

            return $rules && in_array(['disallow', '/'], $rules, true) && ! collect($rules)->contains(fn ($r) => $r[0] === 'allow' && $r[1] === '/');
        }));
    }

    private function duplicates(Collection $pages, string $field, string $rule): void
    {
        $pages->filter(fn ($p) => trim((string) $p->{$field}) !== '')
            ->groupBy(fn ($p) => Str::lower(preg_replace('/\s+/', ' ', trim($p->{$field}))))
            ->filter(fn ($group) => $group->count() > 1)
            ->each(function (Collection $group) use ($rule, $field) {
                foreach ($group as $page) {
                    $this->add($rule, $page, [
                        $field => $page->{$field},
                        'also_on' => $group->where('id', '!=', $page->id)->pluck('canonical_url')->take(10)->values(),
                    ]);
                }
            });
    }

    private function linkRules(CrawlRun $run, Collection $byHash): void
    {
        PageLink::forWebsite($run->website_id)->where('link_type', 'internal')
            ->with('sourcePage:id,canonical_url')
            ->chunkById(1000, function ($links) use ($byHash) {
                foreach ($links->groupBy('source_page_id') as $sourceLinks) {
                    $source = $sourceLinks->first()->sourcePage;
                    foreach ($sourceLinks->unique('target_url_hash') as $link) {
                        $target = $byHash[$link->target_url_hash] ?? null;
                        if (! $target) {
                            continue;
                        }
                        if ($target->status_code >= 400) {
                            $this->add('broken_internal_link', $source, [
                                'target_url' => $link->target_url, 'status_code' => $target->status_code, 'anchor_text' => $link->anchor_text,
                            ], $link->target_url_hash);
                        } elseif (count($target->redirect_chain_json ?? []) >= 2) {
                            $this->add('redirect_chain', $source, [
                                'target_url' => $link->target_url, 'hops' => $target->redirect_chain_json, 'final_url' => $target->final_url,
                            ], $link->target_url_hash);
                        }
                    }
                }
            });
    }

    private function add(string $rule, WebsitePage $page, array $evidence, string $extra = ''): void
    {
        $fingerprint = hash('sha256', $rule.'|'.$page->id.'|'.$extra);
        $this->found[$fingerprint] = [
            'rule_key' => $rule,
            'page_id' => $page->id,
            'severity' => AuditRules::get($rule)['severity'],
            'recommendation' => AuditRules::get($rule)['recommendation'],
            'evidence_json' => $evidence,
        ];
    }

    private function persist(CrawlRun $run, Collection $pages): int
    {
        $website = $run->website;
        $now = now();

        foreach ($this->found as $fingerprint => $attrs) {
            $issue = SeoIssue::firstOrNew(['website_id' => $website->id, 'fingerprint' => $fingerprint]);
            $issue->fill($attrs + ['last_seen_at' => $now]);
            $issue->workspace_id = $website->workspace_id;
            if (! $issue->exists) {
                $issue->first_seen_at = $now;
                $issue->status = 'open';
            } elseif ($issue->status === 'resolved') {
                $issue->status = 'open'; // regression: it came back
                $issue->resolved_at = null;
            }
            $issue->save();
        }

        // Only pages actually re-checked in this run can have their issues resolved.
        $recheckedPageIds = $pages->where('last_crawl_run_id', $run->id)->pluck('id');
        SeoIssue::forWebsite($website)->where('status', 'open')
            ->whereIn('page_id', $recheckedPageIds)
            ->whereNotIn('fingerprint', array_keys($this->found))
            ->update(['status' => 'resolved', 'resolved_at' => $now]);

        return SeoIssue::forWebsite($website)->where('status', 'open')->count();
    }
}
