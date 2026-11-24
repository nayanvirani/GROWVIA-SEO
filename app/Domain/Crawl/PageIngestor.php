<?php

namespace App\Domain\Crawl;

use App\Models\CrawlRun;
use App\Models\PageLink;
use App\Models\WebsitePage;
use App\Support\Url;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Upserts normalized crawler page records and their link graph. */
class PageIngestor
{
    public function ingest(CrawlRun $run, array $pages): int
    {
        $website = $run->website;
        $count = 0;

        foreach ($pages as $p) {
            try {
                $url = Url::normalize($p['url']);
            } catch (\InvalidArgumentException) {
                continue;
            }

            DB::transaction(function () use ($run, $website, $p, $url, &$count) {
                $h1s = array_values(array_filter((array) ($p['h1'] ?? []), fn ($h) => trim((string) $h) !== ''));
                $page = WebsitePage::updateOrCreate(
                    ['website_id' => $website->id, 'url_hash' => Url::hash($url)],
                    [
                        'workspace_id' => $website->workspace_id,
                        'last_crawl_run_id' => $run->id,
                        'canonical_url' => $url,
                        'final_url' => $p['final_url'] ?? null,
                        'status_code' => $p['status_code'] ?? null,
                        'content_type' => Str::limit((string) ($p['content_type'] ?? ''), 250, ''),
                        'title' => $p['title'] ?? null,
                        'meta_description' => $p['meta_description'] ?? null,
                        'h1' => $h1s[0] ?? null,
                        'headings_json' => ['h1' => $h1s] + (array) ($p['headings'] ?? []),
                        'canonical_url_found' => isset($p['canonical']) ? Str::limit($p['canonical'], 2040, '') : null,
                        'robots_meta' => $p['robots_meta'] ?? null,
                        'x_robots_tag' => $p['x_robots_tag'] ?? null,
                        'word_count' => (int) ($p['word_count'] ?? 0),
                        'content_hash' => $p['content_hash'] ?? null,
                        'depth' => (int) ($p['depth'] ?? 0),
                        'redirect_chain_json' => $p['redirect_chain'] ?? [],
                        'images_json' => array_slice((array) ($p['images'] ?? []), 0, 200),
                        'text_excerpt' => isset($p['text_excerpt']) ? Str::limit($p['text_excerpt'], 4000) : null,
                        'fetch_error' => isset($p['error']) ? Str::limit($p['error'], 250) : null,
                        'last_crawled_at' => now(),
                    ]
                );

                $page->links()->delete();
                $rows = [];
                foreach (array_slice((array) ($p['links'] ?? []), 0, 500) as $link) {
                    try {
                        $target = Url::normalize($link['url']);
                    } catch (\InvalidArgumentException) {
                        continue;
                    }
                    $rows[] = [
                        'id' => (string) Str::ulid(),
                        'workspace_id' => $website->workspace_id,
                        'website_id' => $website->id,
                        'source_page_id' => $page->id,
                        'target_url' => Str::limit($target, 2040, ''),
                        'target_url_hash' => Url::hash($target),
                        'anchor_text' => isset($link['anchor']) ? Str::limit($link['anchor'], 500) : null,
                        'link_type' => Url::sameSite($target, $website->normalized_host) ? 'internal' : 'external',
                        'rel' => isset($link['rel']) ? Str::limit($link['rel'], 250, '') : null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
                foreach (array_chunk($rows, 200) as $chunk) {
                    PageLink::insert($chunk);
                }
                $count++;
            });
        }

        return $count;
    }

    /** Fill http_status on internal links from the statuses of crawled pages. */
    public function resolveLinkStatuses(CrawlRun $run): void
    {
        $statuses = WebsitePage::forWebsite($run->website_id)->pluck('status_code', 'url_hash');
        PageLink::forWebsite($run->website_id)->where('link_type', 'internal')
            ->select(['id', 'target_url_hash'])->chunkById(1000, function ($links) use ($statuses) {
                foreach ($links->groupBy(fn ($l) => $statuses[$l->target_url_hash] ?? null) as $status => $group) {
                    if ($status !== '') {
                        PageLink::whereIn('id', $group->pluck('id'))->update(['http_status' => $status]);
                    }
                }
            });
    }
}
