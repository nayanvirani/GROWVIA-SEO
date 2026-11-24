<?php

namespace App\Providers;

use App\Providers\SEOData\AutocompleteKeywordProvider;
use App\Providers\SEOData\KeywordResearchProvider;
use App\Providers\SEOData\MockKeywordProvider;
use Illuminate\Support\ServiceProvider;

class SeoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Free Google + DuckDuckGo autocomplete; fixtures only for automated tests.
        $this->app->bind(KeywordResearchProvider::class, fn () => config('seo.seo_data.provider') === 'mock'
            ? new MockKeywordProvider
            : new AutocompleteKeywordProvider);
    }
}
