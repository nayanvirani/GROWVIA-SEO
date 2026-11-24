<?php

namespace App\Providers\Search;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Key-free search through DuckDuckGo's lightweight HTML page. Deliberately slow
 * (one query every few seconds) to stay polite; results are cached for a week.
 */
class DuckDuckGoLiteSearch implements WebSearchProvider
{
    public const MIN_INTERVAL_SECONDS = 10;

    public const COOLDOWN_MINUTES = 20;

    public function name(): string
    {
        return 'duckduckgo';
    }

    public function search(string $query, int $limit = 10): array
    {
        return Cache::remember('ddg:'.md5($query), now()->addDays(7), function () use ($query, $limit) {
            if (Cache::has('ddg-cooldown')) {
                throw new RuntimeException('DuckDuckGo asked us to slow down; autopilot continues automatically in a few minutes. A free Tavily key avoids this.');
            }
            $this->throttle();
            $response = Http::timeout(20)
                ->withUserAgent('Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130 Safari/537.36')
                ->asForm()->post('https://lite.duckduckgo.com/lite/', ['q' => $query]);
            if ($response->status() !== 200) {
                Cache::put('ddg-cooldown', true, now()->addMinutes(self::COOLDOWN_MINUTES));
                throw new RuntimeException('DuckDuckGo is temporarily limiting searches (HTTP '.$response->status().'). Autopilot continues automatically in about '.self::COOLDOWN_MINUTES.' minutes. A free Tavily key avoids this.');
            }

            return array_slice(self::parse($response->body()), 0, $limit);
        });
    }

    /** @return list<array{url: string, title: string, snippet: string}> */
    public static function parse(string $html): array
    {
        $doc = new \DOMDocument;
        @$doc->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NONET | LIBXML_NOERROR);
        $xpath = new \DOMXPath($doc);
        $results = [];
        foreach ($xpath->query("//a[contains(concat(' ', normalize-space(@class), ' '), ' result-link ')]") as $a) {
            $href = $a->getAttribute('href');
            if (str_contains($href, 'uddg=')) {
                parse_str((string) parse_url(html_entity_decode($href), PHP_URL_QUERY), $q);
                $href = $q['uddg'] ?? $href;
            }
            if (! preg_match('#^https?://#i', $href) || str_contains($href, 'duckduckgo.com/y.js')) {
                continue; // ads and internal links
            }
            $snippet = '';
            $row = $a->parentNode?->parentNode;
            for ($n = $row?->nextSibling, $i = 0; $n && $i < 4; $n = $n->nextSibling, $i++) {
                if ($n instanceof \DOMElement && $xpath->query(".//*[contains(@class,'result-snippet')]", $n)->length) {
                    $snippet = trim($xpath->query(".//*[contains(@class,'result-snippet')]", $n)->item(0)->textContent);
                    break;
                }
            }
            $results[] = ['url' => $href, 'title' => trim($a->textContent), 'snippet' => $snippet];
        }

        return $results;
    }

    private function throttle(): void
    {
        $lock = Cache::lock('ddg-search', 30);
        $lock->block(30);
        try {
            $wait = (int) Cache::get('ddg-search-next', 0) - time();
            if ($wait > 0 && ! app()->runningUnitTests()) {
                sleep(min($wait, self::MIN_INTERVAL_SECONDS));
            }
            Cache::put('ddg-search-next', time() + self::MIN_INTERVAL_SECONDS, 60);
        } finally {
            $lock->release();
        }
    }
}
