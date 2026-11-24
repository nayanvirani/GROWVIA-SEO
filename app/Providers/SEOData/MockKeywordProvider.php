<?php

namespace App\Providers\SEOData;

/**
 * Deterministic fixture data for local development. Metrics are synthetic and are stored
 * with provider "mock" so the UI can label them as not real search data.
 */
class MockKeywordProvider implements KeywordResearchProvider
{
    private const MODIFIERS = [
        '', 'best', 'free', 'how to use', 'for shopify', 'alternatives', 'examples', 'pricing', 'vs', 'guide', 'tips', 'ideas',
    ];

    public function name(): string
    {
        return 'mock';
    }

    public function suggestions(string $seed, string $locale): array
    {
        $seed = strtolower(trim($seed));
        $rows = [];
        foreach (self::MODIFIERS as $mod) {
            $kw = match (true) {
                $mod === '' => $seed,
                in_array($mod, ['best', 'free', 'how to use'], true) => "{$mod} {$seed}",
                default => "{$seed} {$mod}",
            };
            $h = crc32($kw.'|'.$locale);
            $rows[] = [
                'keyword' => $kw,
                'volume' => 10 * (($h % 400) + 1),
                'difficulty' => $h % 90 + 5,
                'cpc' => round((($h >> 8) % 600) / 100, 2),
                'is_estimate' => true,
            ];
        }

        return $rows;
    }
}
