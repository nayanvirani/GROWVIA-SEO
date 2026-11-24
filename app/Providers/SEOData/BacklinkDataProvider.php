<?php

namespace App\Providers\SEOData;

interface BacklinkDataProvider
{
    /**
     * @return list<array{source_url: string, target_url: string, anchor_text: ?string, rel: ?string, first_seen: ?string, page_title: ?string}>
     */
    public function backlinks(string $domain, array $filters = []): array;

    public function name(): string;
}
