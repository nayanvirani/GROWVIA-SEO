<?php

namespace App\Providers\Search;

use App\Domain\Settings\ProviderSettings;

class SearchProviderFactory
{
    public function __construct(private readonly ProviderSettings $settings) {}

    public function forWorkspace(string $workspaceId): ?WebSearchProvider
    {
        $s = $this->settings->get($workspaceId, 'search');

        return match ($s['preset']) {
            'tavily' => filled($s['api_key']) ? new TavilySearch($s['api_key']) : null,
            'searxng' => filled($s['base_url']) ? new SearxngSearch($s['base_url']) : null,
            'none' => null,
            default => new DuckDuckGoLiteSearch,
        };
    }
}
