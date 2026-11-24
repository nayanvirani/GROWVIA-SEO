<?php

namespace App\Providers\SEOData;

class MockBacklinkProvider implements BacklinkDataProvider
{
    public function name(): string
    {
        return 'mock';
    }

    public function backlinks(string $domain, array $filters = []): array
    {
        $sources = [
            ['ecommerce-weekly.example.com', '/resources/best-shopify-apps', 'Best Shopify apps for growing stores'],
            ['storebuilders.example.org', '/blog/conversion-tools', 'Conversion tools every store needs'],
            ['dtc-guides.example.net', '/guides/upsell-strategies', 'Upsell strategies that work'],
            ['free-links-directory.example.biz', '/submit/cheap-links', 'Cheap links directory – submit now'],
            ['merchant-academy.example.com', '/library/tools', 'Merchant tool library'],
        ];

        return array_map(fn ($s) => [
            'source_url' => "https://{$s[0]}{$s[1]}",
            'target_url' => "https://{$domain}/",
            'anchor_text' => $domain,
            'rel' => str_contains($s[0], 'cheap') ? 'nofollow' : null,
            'first_seen' => now()->subDays(crc32($s[0]) % 300)->toDateString(),
            'page_title' => $s[2],
        ], $sources);
    }
}
