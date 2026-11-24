<?php

namespace App\Providers\SEOData;

interface KeywordResearchProvider
{
    /**
     * @return list<array{keyword: string, volume: ?int, difficulty: ?int, cpc: ?float, is_estimate: bool, rank: int, sources: list<string>}>
     *                                                                                                                                        rank: best position in the suggestion lists (1 = most common)
     */
    public function suggestions(string $seed, string $locale): array;

    public function name(): string;
}
