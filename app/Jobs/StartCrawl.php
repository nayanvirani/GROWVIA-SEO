<?php

namespace App\Jobs;

use App\Domain\Crawl\CrawlerClient;
use App\Models\CrawlRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class StartCrawl implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 60];

    public function __construct(public string $crawlRunId)
    {
        $this->onQueue('crawl');
    }

    public function handle(CrawlerClient $crawler): void
    {
        $run = CrawlRun::with('website')->find($this->crawlRunId);
        if (! $run || $run->status !== 'queued') {
            return;
        }
        $website = $run->website;

        $crawlId = $crawler->start([
            'website_id' => $website->id,
            'start_url' => $website->base_url,
            'allowed_host' => $website->normalized_host,
            'max_pages' => $run->max_pages,
            'max_depth' => $run->max_depth,
            'respect_robots' => true,
            'exclusion_paths' => $website->setting('exclusion_paths') ?? [],
            'user_agent' => config('seo.crawler.user_agent'),
        ]);

        $run->update(['status' => 'running', 'crawler_job_id' => $crawlId, 'started_at' => now()]);
        PollCrawl::dispatch($run->id)->delay(now()->addSeconds(2));
    }

    public function failed(Throwable $e): void
    {
        CrawlRun::whereKey($this->crawlRunId)->update([
            'status' => 'failed',
            'completed_at' => now(),
            'error_summary' => json_encode(['message' => $e->getMessage()]),
        ]);
    }
}
