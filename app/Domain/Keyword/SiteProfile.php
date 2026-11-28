<?php

namespace App\Domain\Keyword;

use App\Models\Website;
use App\Models\WebsitePage;
use Illuminate\Support\Str;

/**
 * What the website is about, derived from its page titles and settings.
 *
 * Page titles are the best signal: they are written to describe the page, while headings
 * are often marketing slogans ("Test ideas, not guesses"). Titles like
 * "Shopify Bundles, Upsells & Free Gift App | Brand" become the topics
 * "shopify bundles", "shopify upsells" and "shopify free gift app".
 */
class SiteProfile
{
    public const STOPWORDS = ['a', 'an', 'the', 'and', 'or', 'for', 'to', 'of', 'in', 'on', 'with', 'by', 'at', 'from', 'your', 'you', 'our',
        'we', 'is', 'are', 'be', 'it', 'its', 'this', 'that', 'how', 'what', 'why', 'best', 'free', 'vs', 'top', 'new', 'all', 'more', 'get',
        'can', 'do', 'does', 'my', 'use', 'using', 'about', 'us', 'home', 'page', 'welcome', 'i', 'me', 'will', 'has', 'have', 'not', 'no',
        'yes', 'every', 'each', 'one', 'into', 'than', 'that', 'them', 'they', 'their', 'there', 'which', 'who', 'when', 'where', 'just',
        'guide', 'guides', 'step', 'steps', 'explained', 'practical', 'ultimate', 'complete', 'easy', 'simple', 'quick',
        'app', 'apps', 'tool', 'tools', 'software', 'plugin', 'plugins', 'platform',
        'store', 'stores', 'shop', 'shops', 'website', 'websites', 'business', 'businesses', 'brand', 'brands', 'ecommerce',
        'growth', 'online', 'services', 'service',
        'docs', 'help', 'works', 'work', 'valid', 'setup', 'setups', 'features', 'feature', 'things', 'ways', 'tips', 'well', 'really'];

    /** Grammar words trimmed from the edges of a topic phrase. Unlike STOPWORDS, "free" or "best" are kept. */
    private const FUNCTION_WORDS = ['a', 'an', 'the', 'and', 'or', 'for', 'to', 'of', 'in', 'on', 'with', 'by', 'at', 'from', 'your', 'you',
        'our', 'we', 'is', 'are', 'it', 'its', 'this', 'that', 'how', 'what', 'why', 'vs', 'can', 'do', 'does', 'my', 'every', 'each', 'one', 'into'];

    /** Words that describe the format, not the topic ("CRO guides" is about CRO). */
    private const GENERIC_TAIL = ['guide', 'guides', 'docs', 'help', 'tips', 'explained', 'setup', 'setups', 'features', 'feature', 'overview', 'basics'];

    /** Pages that describe the business, not its topics. Matched on the first path segment. */
    private const SKIP_SECTIONS = ['pricing', 'plans', 'legal', 'privacy', 'terms', 'cookies', 'cookie', 'dpa', 'contact', 'about', 'security', 'help', 'docs',
        'login', 'signin', 'signup', 'register', 'account', 'careers', 'jobs', 'press', 'status', 'support', 'sitemap', 'search', 'tag', 'author'];

    private const LEADING_PHRASES = '/^(how to (add|create|use|run|set up|setup|build|make|start|get|choose|pick|grow|increase|improve)\s+(a|an|the|your)?\s*|what (is|are)\s+(a|an|the)?\s*|why\s+|the\s+|a\s+|an\s+)/';

    /** @var array<string, int> topic word => weight */
    public array $tokens = [];

    /** @var list<string> best topic phrases first */
    public array $phrases = [];

    public ?string $context = null;

    public string $text = '';

    public static function for(Website $website): self
    {
        $p = new self;
        $brand = Str::lower($website->name);
        $pages = WebsitePage::forWebsite($website)->where('status_code', 200)->where('depth', '<=', 3)
            ->orderBy('depth')->limit(300)->get(['canonical_url', 'title', 'depth']);

        // 1. Title parts ("Topic: detail | Brand" -> "topic", "detail"), without the brand.
        //    The first part is usually the main subject, so it weighs more.
        $heads = [];
        $titleWords = [];
        foreach ($pages as $page) {
            $section = Str::lower(explode('/', trim((string) parse_url($page->canonical_url, PHP_URL_PATH), '/'))[0] ?? '');
            if (in_array($section, self::SKIP_SECTIONS, true) || ! $page->title) {
                continue;
            }
            $title = Str::lower(html_entity_decode($page->title));
            $title = trim(preg_replace('/\b'.preg_quote($brand, '/').'\b(\s+(docs|blog|help))?/u', ' ', $title));
            $titleWords[] = array_unique(self::words($title));
            $parts = collect(preg_split('/\s*[|:?!]\s*|\s*[–—]\s*|\s+-\s+/u', $title))
                ->map(fn ($x) => trim($x, " \t-–—|:"))->filter(fn ($x) => count(self::words($x)) >= 2)->values();
            foreach ($parts as $i => $part) {
                $heads[] = ['text' => $part, 'weight' => ($page->depth <= 1 ? 2 : 1) * ($i === 0 ? 2 : 1)];
            }
        }

        // 2. The platform/context word most titles share and lead with (e.g. "shopify").
        $inTitles = array_count_values(array_merge(...($titleWords ?: [[]])));
        $leading = array_count_values(array_map(fn ($h) => self::words($h['text'])[0], $heads));
        $best = 0;
        foreach ($leading as $word => $count) {
            if (! in_array($word, self::STOPWORDS, true) && ($inTitles[$word] ?? 0) >= max(2, count($titleWords) * 0.5)
                && $count >= max(2, count($heads) * 0.3) && $count > $best) {
                $p->context = (string) $word;
                $best = $count;
            }
        }

        // 3. Topic vocabulary (weighted) from title heads plus the owner's own description.
        $texts = array_column($heads, 'text');
        $settingsText = Str::lower(implode(' ', array_filter([$website->setting('description'), $website->setting('brand_facts')])));
        foreach (self::words(implode(' ', $texts)) as $w) {
            $p->addToken($w, 3, $brand);
        }
        foreach (self::words($settingsText) as $w) {
            $p->addToken($w, 1, $brand);
        }
        $p->text = implode(' . ', $texts).' . '.$settingsText;

        // 4. Candidate topic phrases: split combined titles into parts and add the context word.
        $candidates = [];
        foreach ($heads as $head) {
            foreach (preg_split('/\s*(?:&|,|\+|\/)\s*|\s+(?:and|vs\.?|or)\s+/u', $head['text']) as $part) {
                if (($phrase = $p->cleanPhrase($part)) !== null) {
                    $candidates[$phrase] = ($candidates[$phrase] ?? 0) + $head['weight'];
                }
            }
        }

        // 5. Rank by how many titles cover the topic: a site keeps returning to its core topics.
        $headStems = array_map(fn ($h) => array_flip(self::stems(self::words($h['text']))), $heads);
        $stemsOf = fn (string $phrase) => array_values(array_diff(self::stems(self::words($phrase)), [$p->context]));
        $df = [];
        foreach (array_keys($candidates) as $phrase) {
            $stems = $stemsOf($phrase);
            $df[$phrase] = count(array_filter($headStems, fn ($set) => ! array_diff($stems, array_keys($set))));
        }
        $score = [];
        foreach ($candidates as $phrase => $weight) {
            $score[$phrase] = $df[$phrase] * 10 + $weight;
        }
        arsort($score);

        // 6. Prefer the specific phrase ("free gift") over a vague one it contains ("gift") when both
        //    are common, and keep the list diverse.
        foreach (array_keys($score) as $phrase) {
            $stems = $stemsOf($phrase);
            foreach (array_keys($score) as $other) {
                $otherStems = $stemsOf($other);
                if ($other !== $phrase && count($otherStems) > count($stems) && ! array_diff($stems, $otherStems)
                    && $df[$other] >= 0.5 * $df[$phrase]) {
                    continue 2;
                }
            }
            foreach ($p->phrases as $chosen) {
                $overlap = array_intersect($stems, $stemsOf($chosen));
                if ($stems && count($overlap) / count($stems) >= 0.6) {
                    continue 2;
                }
            }
            if ($df[$phrase] >= 2 || count($p->phrases) < 3) {
                $p->phrases[] = $phrase;
            }
        }

        return $p;
    }

    /**
     * 0..1: how much the keyword is about this site's topics. The platform word ("shopify") is
     * ignored, and each word counts by how central it is: a word in 3+ page titles counts fully.
     */
    public function relevance(string $keyword): float
    {
        $words = array_values(array_filter(self::words($keyword), fn ($w) => ! in_array($w, self::STOPWORDS, true) && $w !== $this->context && ! self::isNoise($w)));
        if (! $words || ! $this->tokens) {
            return $words ? 0.5 : 0.1; // no vocabulary yet: neutral; only the platform/generic words: weak
        }
        $hit = 0.0;
        foreach ($words as $w) {
            $hit += min(1, $this->weight($w) / 9);
        }
        $score = $hit / count($words);
        if ($score > 0 && str_contains($this->text, Str::lower($keyword))) {
            $score = min(1, $score + 0.2);
        }

        return round($score, 2);
    }

    /** Years and "where to look" modifiers (reddit, youtube, pdf) say nothing about the topic. */
    public static function isNoise(string $w): bool
    {
        return (bool) preg_match('/^(19|20)\d\d$/', $w) || in_array($w, ['reddit', 'quora', 'youtube', 'tiktok', 'github', 'pdf', 'online', 'near', 'today', 'now'], true);
    }

    /** @return list<string> */
    public static function words(string $text): array
    {
        return preg_split('/[^\p{L}\p{N}]+/u', Str::lower(str_replace(["'", '’'], '', $text)), -1, PREG_SPLIT_NO_EMPTY);
    }

    /** @return list<string> crude singular forms so "upsell" and "upsells" count as the same topic */
    private static function stems(array $words): array
    {
        return array_values(array_map(fn ($w) => preg_replace('/(ies|s)$/', '', $w), $words));
    }

    private function weight(string $w): int
    {
        $best = 0;
        foreach (array_unique([$w, rtrim($w, 's'), $w.'s', preg_replace('/(ies)$/', 'y', $w), preg_replace('/(ing|ed)$/', '', $w)]) as $form) {
            $best = max($best, $this->tokens[$form] ?? 0);
        }

        return $best;
    }

    private function addToken(string $w, int $weight, string $brand): void
    {
        if ($w !== $brand && mb_strlen($w) > 2 && ! in_array($w, self::STOPWORDS, true) && ! ctype_digit($w)) {
            $this->tokens[$w] = ($this->tokens[$w] ?? 0) + $weight;
        }
    }

    private function cleanPhrase(string $part): ?string
    {
        $part = trim(preg_replace(self::LEADING_PHRASES, '', trim($part)));
        // Drop audience qualifiers: "upsell app for growing stores" -> "upsell app".
        $core = trim(preg_replace('/\s+(for|to|that|which|with)\s+(?!'.preg_quote((string) $this->context, '/').'\b).*$/u', '', $part));
        if ($core !== '' && $core !== $part && count(self::words($core)) >= 1) {
            $part = $core;
        }
        if ($this->context) {
            $part = trim(preg_replace('/\b(on|for|in|to|with)\s+'.preg_quote($this->context, '/').'\b/u', '', $part));
        }
        $words = array_values(array_filter(self::words($part), fn ($w) => $w !== $this->context));
        while ($words && (in_array(end($words), self::FUNCTION_WORDS, true) || in_array(end($words), self::GENERIC_TAIL, true))) {
            array_pop($words);
        }
        while ($words && in_array($words[0], self::FUNCTION_WORDS, true)) {
            array_shift($words);
        }
        $meaningful = array_filter($words, fn ($w) => ! in_array($w, self::STOPWORDS, true));
        if (! $meaningful || count($words) > 4) {
            return null;
        }
        $phrase = implode(' ', $words);
        if ($this->context) {
            $phrase = $this->context.' '.$phrase;
        }

        return count(self::words($phrase)) >= 2 ? $phrase : null;
    }
}
