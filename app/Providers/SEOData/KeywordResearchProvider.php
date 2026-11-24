<?php

namespace App\Providers\SEOData;

interface KeywordResearchProvider
{
    /**
     * @return list<array{keyword: string, volume: ?int, difficulty: ?int, cpc: ?float, is_estimate: bool}>
     */
    public function suggestions(string $seed, string $locale): array;

    public function name(): string;
}
