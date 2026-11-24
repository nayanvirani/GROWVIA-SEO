<?php

namespace App\Providers\SEOData;

use Illuminate\Support\Facades\Http;

/**
 * Free keyword ideas from Google and DuckDuckGo autocomplete: real phrases people type.
 * Autocomplete gives no search volume, so volume/difficulty/CPC stay null ("Not available").
 */
class AutocompleteKeywordProvider implements KeywordResearchProvider
{
    private const PATTERNS = ['%s', 'best %s', 'how to %s', '%s for', '%s vs', 'what is %s'];

    public function name(): string
    {
        return 'autocomplete';
    }

    public function suggestions(string $seed, string $locale): array
    {
        $seed = mb_strtolower(trim($seed));
        $found = [];
        foreach (self::PATTERNS as $pattern) {
            $query = sprintf($pattern, $seed);
            foreach (['google' => $this->google($query, $locale), 'duckduckgo' => $this->duckduckgo($query, $locale)] as $source => $list) {
                foreach (array_values($list) as $i => $phrase) {
                    $phrase = mb_strtolower(trim(preg_replace('/\s+/', ' ', (string) $phrase)));
                    if ($phrase === '' || mb_strlen($phrase) > 120) {
                        continue;
                    }
                    $found[$phrase]['rank'] = min($found[$phrase]['rank'] ?? 99, $i + 1);
                    $found[$phrase]['sources'][$source] = true;
                }
            }
            usleep(app()->runningUnitTests() ? 0 : 250_000);
        }

        $rows = [];
        foreach ($found as $phrase => $info) {
            $rows[] = [
                'keyword' => $phrase, 'volume' => null, 'difficulty' => null, 'cpc' => null, 'is_estimate' => false,
                'rank' => $info['rank'], 'sources' => array_keys($info['sources']),
            ];
        }

        return $rows;
    }

    private function google(string $q, string $locale): array
    {
        return rescue(fn () => Http::timeout(8)->get('https://suggestqueries.google.com/complete/search', [
            'client' => 'firefox', 'hl' => 'en', 'gl' => $locale, 'q' => $q,
        ])->json('1', []), [], false) ?: [];
    }

    private function duckduckgo(string $q, string $locale): array
    {
        $list = rescue(fn () => Http::timeout(8)->get('https://duckduckgo.com/ac/', [
            'q' => $q, 'type' => 'list', 'kl' => $locale.'-en',
        ])->json('1', []), [], false);

        return is_array($list) ? $list : [];
    }
}
