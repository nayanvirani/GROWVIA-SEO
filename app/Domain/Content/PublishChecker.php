<?php

namespace App\Domain\Content;

use App\Domain\Keyword\SiteProfile;
use App\Models\ContentDraft;
use App\Models\PageKeywordTarget;
use App\Models\PageLink;
use App\Models\Website;
use App\Models\WebsitePage;
use App\Support\ActivityLogger;
use App\Support\Enums\ContentStatus;
use App\Support\Security\SafeFetcher;
use App\Support\Url;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * After the owner publishes an article on their own site: check the live post, register it as
 * the page for its keyword, and suggest existing pages that should link to it (internal links
 * help new articles get found and ranked).
 */
class PublishChecker
{
    /** Sections that should not carry article links (legal, docs, pricing, account pages). */
    private const NO_LINK_SECTIONS = ['legal', 'privacy', 'terms', 'cookies', 'dpa', 'contact', 'pricing', 'login', 'signup', 'account', 'docs', 'help'];

    public function check(ContentDraft $draft, ?string $url = null): ContentDraft
    {
        $website = $draft->website;
        $url = $url ?? $draft->published_url;
        try {
            $url = Url::normalize((string) $url);
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages(['url' => 'Enter the full link to your published post.']);
        }
        if (! Url::sameSite($url, $website->normalized_host)) {
            throw ValidationException::withMessages(['url' => "The post must be on {$website->normalized_host}."]);
        }

        $res = SafeFetcher::get($url);
        $checks = [];
        $add = function (string $key, string $state, string $label, ?string $detail = null) use (&$checks) {
            $checks[] = compact('key', 'state', 'label', 'detail');
        };

        if (! $res['html'] || $res['status'] !== 200) {
            $add('loads', 'fail', 'The page does not load', $res['error'] ?? 'HTTP '.$res['status'].'. Check the link, or that the post is published (not a draft).');
            $draft->update(['published_url' => $url, 'publish_checked_at' => now(), 'publish_check_json' => ['checks' => $checks, 'link_suggestions' => []]]);

            return $draft->fresh();
        }
        $add('loads', 'ok', 'The page loads');
        $page = $this->parse($res['html'], $res['final_url']);
        $keyword = $draft->brief?->primaryKeyword?->keyword;

        str_contains(Str::lower((string) $page['robots']), 'noindex')
            ? $add('indexable', 'fail', 'The page tells Google not to index it (noindex)', 'Remove "noindex" in your blog or SEO plugin settings.')
            : $add('indexable', 'ok', 'Google is allowed to index it');

        if (! $page['title']) {
            $add('title', 'fail', 'No page title', 'Paste this SEO title into your blog: '.$draft->meta_title);
        } elseif ($draft->meta_title && $this->overlap($page['title'], $draft->meta_title) < 0.5) {
            $add('title', 'warn', 'The page title differs from the approved SEO title', "Live: \"{$page['title']}\". Suggested: \"{$draft->meta_title}\".");
        } else {
            $add('title', 'ok', 'Title is set');
        }

        $page['description']
            ? $add('description', 'ok', 'Meta description is set')
            : $add('description', 'warn', 'No meta description', 'Paste this into your blog SEO settings: '.$draft->meta_description);

        match (count($page['h1'])) {
            1 => $add('h1', 'ok', 'One main heading (H1)'),
            0 => $add('h1', 'warn', 'No main heading (H1)', 'Make the article title a Heading 1.'),
            default => $add('h1', 'warn', count($page['h1']).' main headings (H1)', 'Keep one H1 (the title) and use Heading 2 for sections.'),
        };

        if ($page['canonical'] && Url::normalize($page['canonical']) !== Url::normalize($res['final_url'])) {
            $add('canonical', 'warn', 'Canonical points to a different URL', "It points to {$page['canonical']}. Usually it should point to the post itself.");
        }

        if ($keyword) {
            $covers = $this->covers($page['title'].' '.implode(' ', $page['h1']), $keyword);
            $covers ? $add('keyword', 'ok', "Title or heading mentions \"{$keyword}\"")
                : $add('keyword', 'warn', "Title and heading do not mention \"{$keyword}\"", 'Include the topic naturally in the title so searchers recognise it.');
        }

        $draftWords = str_word_count(strip_tags((string) $draft->body_markdown));
        if ($draftWords > 0 && $page['words'] < $draftWords * 0.7) {
            $add('complete', 'warn', 'The live post looks shorter than the approved draft', "About {$page['words']} words live vs {$draftWords} in the draft. Check nothing was cut off.");
        } else {
            $add('complete', 'ok', "Full article is published (~{$page['words']} words)");
        }

        $missingAlt = count(array_filter($page['images'], fn ($i) => $i['alt'] === null));
        $missingAlt
            ? $add('images', 'warn', "{$missingAlt} image(s) without a description (alt text)", 'Add a short description of what each image shows.')
            : $add('images', 'ok', 'Images have descriptions');

        $internal = array_values(array_filter($page['links'], fn ($l) => Url::sameSite($l, $website->normalized_host) && Url::normalize($l) !== Url::normalize($res['final_url'])));
        $broken = $this->brokenLinks($draft, $internal);
        $broken
            ? $add('links', 'fail', count($broken).' broken link(s) in the post', implode(', ', array_slice($broken, 0, 5)))
            : (count($internal) >= 2
                ? $add('links', 'ok', 'Links to '.count($internal).' other page(s) on your site')
                : $add('links', 'warn', 'Links to fewer than 2 of your own pages', 'Add links from the article to related feature pages, e.g. the suggestions in the draft\'s internal links.'));

        $newPage = $this->register($draft, $res, $page, $keyword);
        $suggestions = $this->linkSuggestions($draft, $newPage, $keyword);

        $draft->update([
            'published_url' => $url,
            'publish_checked_at' => now(),
            'publish_check_json' => ['checks' => $checks, 'link_suggestions' => $suggestions],
            'status' => in_array($draft->status, [ContentStatus::ApprovedForExport->value, ContentStatus::Exported->value], true)
                ? ContentStatus::PublishedExternally->value : $draft->status,
        ]);
        ActivityLogger::log($website, 'draft.publish_checked', $draft, ['url' => $url, 'issues' => collect($checks)->where('state', '!=', 'ok')->count()]);

        return $draft->fresh();
    }

    /** Re-check every published article (after each crawl) so link suggestions shrink as you add them. */
    public function recheckAll(Website $website): array
    {
        $done = 0;
        foreach ($website->contentDrafts()->whereNotNull('published_url')->get() as $draft) {
            rescue(fn () => $this->check($draft), null, false);
            $done++;
        }

        return ['rechecked' => $done];
    }

    private function parse(string $html, string $url): array
    {
        $doc = new \DOMDocument;
        @$doc->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NONET | LIBXML_NOERROR);
        $xpath = new \DOMXPath($doc);
        $meta = fn (string $name) => ($n = $xpath->query("//meta[translate(@name,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz')='{$name}']/@content")->item(0)) ? trim($n->nodeValue) : null;
        $links = [];
        foreach ($xpath->query('//a[@href]') as $a) {
            $href = trim($a->getAttribute('href'));
            if ($href !== '' && ! str_starts_with($href, '#') && ! preg_match('/^(mailto|tel|javascript):/i', $href)) {
                $links[] = (string) UriResolver::resolve(new Uri($url), new Uri($href));
            }
        }
        $images = [];
        foreach ($xpath->query('//img') as $img) {
            $images[] = ['src' => $img->getAttribute('src'), 'alt' => $img->hasAttribute('alt') ? $img->getAttribute('alt') : null];
        }
        foreach ($xpath->query('//script|//style|//noscript') as $n) {
            $n->parentNode->removeChild($n);
        }
        $text = trim(preg_replace('/\s+/', ' ', (string) $xpath->query('//body')->item(0)?->textContent));

        return [
            'title' => trim((string) $xpath->query('//title')->item(0)?->textContent) ?: null,
            'description' => $meta('description'),
            'robots' => $meta('robots'),
            'canonical' => ($c = $xpath->query("//link[@rel='canonical']/@href")->item(0)) ? trim($c->nodeValue) : null,
            'h1' => array_values(array_filter(array_map(fn ($n) => trim($n->textContent), iterator_to_array($xpath->query('//h1'))))),
            'h2' => array_slice(array_values(array_filter(array_map(fn ($n) => trim($n->textContent), iterator_to_array($xpath->query('//h2'))))), 0, 20),
            'links' => array_values(array_unique($links)),
            'images' => $images,
            'words' => str_word_count($text),
            'text' => mb_substr($text, 0, 4000),
        ];
    }

    /** Record the live post as a site page and make it the target page for its keyword. */
    private function register(ContentDraft $draft, array $res, array $page, ?string $keyword): WebsitePage
    {
        $website = $draft->website;
        $url = Url::normalize($res['final_url']);
        $wp = WebsitePage::updateOrCreate(
            ['website_id' => $website->id, 'url_hash' => Url::hash($url)],
            ['workspace_id' => $website->workspace_id, 'canonical_url' => $url, 'final_url' => $url, 'status_code' => 200,
                'content_type' => 'text/html', 'title' => $page['title'], 'meta_description' => $page['description'], 'h1' => $page['h1'][0] ?? null,
                'headings_json' => ['h1' => $page['h1'], 'h2' => $page['h2']], 'canonical_url_found' => $page['canonical'], 'robots_meta' => $page['robots'],
                'word_count' => $page['words'], 'text_excerpt' => $page['text'], 'images_json' => array_slice($page['images'], 0, 100),
                'depth' => max(1, substr_count(trim((string) parse_url($url, PHP_URL_PATH), '/'), '/') + 1), 'last_crawled_at' => now()]
        );

        if ($kw = $draft->brief?->primaryKeyword) {
            $kw->pageTargets()->delete();
            PageKeywordTarget::create(['website_id' => $website->id, 'page_id' => $wp->id, 'keyword_id' => $kw->id,
                'target_type' => 'primary', 'notes' => 'Published article']);
            $kw->update(['is_gap' => false]);
        }

        return $wp;
    }

    /** @return list<array{url: string, title: ?string, anchor: string, snippet: ?string, score: int}> */
    private function linkSuggestions(ContentDraft $draft, WebsitePage $newPage, ?string $keyword): array
    {
        $website = $draft->website;
        $profile = SiteProfile::for($website);
        $topic = collect(SiteProfile::words(($keyword ?? '').' '.$draft->title))
            ->reject(fn ($w) => in_array($w, SiteProfile::STOPWORDS, true) || $w === $profile->context || SiteProfile::isNoise($w) || mb_strlen($w) < 3)
            ->map(fn ($w) => preg_replace('/(ies|s)$/', '', $w))->unique()->values();
        if ($topic->isEmpty()) {
            return [];
        }

        $alreadyLinking = PageLink::forWebsite($website)->where('target_url_hash', $newPage->url_hash)->pluck('source_page_id')->flip();

        return WebsitePage::forWebsite($website)->where('status_code', 200)->where('id', '!=', $newPage->id)
            ->get(['id', 'canonical_url', 'title', 'h1', 'text_excerpt'])
            ->reject(fn ($p) => isset($alreadyLinking[$p->id])
                || in_array(Str::lower(explode('/', trim((string) parse_url($p->canonical_url, PHP_URL_PATH), '/'))[0] ?? ''), self::NO_LINK_SECTIONS, true))
            ->map(function ($p) use ($topic) {
                $head = Str::lower($p->title.' '.$p->h1);
                $body = Str::lower((string) $p->text_excerpt);
                $score = 0;
                $hit = null;
                foreach ($topic as $t) {
                    $score += str_contains($head, $t) ? 3 : 0;
                    if (str_contains($body, $t)) {
                        $score += 1;
                        $hit ??= $t;
                    }
                }

                return ['page' => $p, 'score' => $score, 'hit' => $hit];
            })
            ->filter(fn ($r) => $r['score'] >= 3)
            ->sortByDesc('score')->take(5)->values()
            ->map(fn ($r) => [
                'url' => $r['page']->canonical_url,
                'title' => $r['page']->title,
                'anchor' => $keyword ? Str::ucfirst($keyword) : Str::limit($draft->title, 60),
                'snippet' => $r['hit'] && preg_match('/.{0,90}'.preg_quote($r['hit'], '/').'.{0,90}/iu', (string) $r['page']->text_excerpt, $m) ? trim($m[0]) : null,
                'score' => $r['score'],
            ])->all();
    }

    private function brokenLinks(ContentDraft $draft, array $internal): array
    {
        $known = WebsitePage::forWebsite($draft->website_id)->whereIn('url_hash', array_map(fn ($l) => Url::hash($l), $internal))
            ->pluck('status_code', 'url_hash');
        $broken = [];
        $fetched = 0;
        foreach ($internal as $link) {
            $status = $known[Url::hash($link)] ?? null;
            if ($status === null && $fetched < 5) {
                $fetched++;
                $status = SafeFetcher::get($link)['status'];
            }
            if ($status !== null && $status >= 400) {
                $broken[] = $link;
            }
        }

        return $broken;
    }

    private function overlap(string $a, string $b): float
    {
        $wa = array_unique(SiteProfile::words($a));
        $wb = array_unique(SiteProfile::words($b));

        return $wb ? count(array_intersect($wa, $wb)) / count($wb) : 1.0;
    }

    private function covers(string $text, string $keyword): bool
    {
        $text = ' '.Str::lower(str_replace(['-', '–'], ' ', $text)).' ';
        $words = array_filter(SiteProfile::words($keyword), fn ($w) => ! in_array($w, SiteProfile::STOPWORDS, true));
        foreach ($words as $w) {
            if (! str_contains($text, ' '.preg_replace('/(ies|s)$/', '', $w))) {
                return false;
            }
        }

        return true;
    }
}
