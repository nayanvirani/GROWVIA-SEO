<?php

namespace App\Domain\Crawl;

use App\Jobs\StartCrawl;
use App\Models\CrawlRun;
use App\Models\Website;
use App\Support\ActivityLogger;
use Illuminate\Validation\ValidationException;

class CrawlService
{
    public function queue(Website $website, string $trigger = 'manual'): CrawlRun
    {
        if (! $website->crawl_enabled) {
            throw ValidationException::withMessages(['crawl' => 'Crawling is disabled for this website.']);
        }
        if (! $website->ownership_confirmed) {
            throw ValidationException::withMessages(['crawl' => 'Confirm that you own or are authorised to analyse this website before crawling.']);
        }
        if ($website->crawlRuns()->whereIn('status', CrawlRun::ACTIVE_STATUSES)->exists()) {
            throw ValidationException::withMessages(['crawl' => 'A crawl is already running for this website.']);
        }

        $limit = (int) config('seo.crawler.max_pages_limit');
        $run = $website->crawlRuns()->create([
            'status' => 'queued',
            'trigger' => $trigger,
            'max_pages' => min((int) $website->setting('crawl_max_pages'), $limit),
            'max_depth' => min((int) $website->setting('crawl_max_depth'), 10),
        ]);

        ActivityLogger::log($website, 'crawl.queued', $run, ['trigger' => $trigger]);
        StartCrawl::dispatch($run->id);

        return $run;
    }
}
