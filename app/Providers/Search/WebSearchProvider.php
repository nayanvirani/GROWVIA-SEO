<?php

namespace App\Providers\Search;

interface WebSearchProvider
{
    /** @return list<array{url: string, title: string, snippet: string}> */
    public function search(string $query, int $limit = 10): array;

    public function name(): string;
}
