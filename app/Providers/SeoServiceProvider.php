<?php

namespace App\Providers;

use App\Providers\AI\AiContentProvider;
use App\Providers\AI\MockAiProvider;
use App\Providers\AI\OpenAiCompatibleProvider;
use App\Providers\SEOData\BacklinkDataProvider;
use App\Providers\SEOData\KeywordResearchProvider;
use App\Providers\SEOData\MockBacklinkProvider;
use App\Providers\SEOData\MockKeywordProvider;
use Illuminate\Support\ServiceProvider;

class SeoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AiContentProvider::class, fn () => match (config('seo.ai.provider')) {
            'openai' => new OpenAiCompatibleProvider(config('seo.ai')),
            default => new MockAiProvider,
        });

        // Real SEO data providers (e.g. DataForSEO) plug in here once credentials and budget exist.
        $this->app->bind(KeywordResearchProvider::class, fn () => new MockKeywordProvider);
        $this->app->bind(BacklinkDataProvider::class, fn () => new MockBacklinkProvider);
    }
}
