<?php

namespace App\Jobs;

use App\Domain\Audit\AuditEngine;
use App\Domain\Automation\Automations;
use App\Domain\Crawl\CrawlerClient;
use App\Domain\Crawl\PageIngestor;
use App\Models\CrawlRun;
use App\Notifications\CrawlFinished;
use App\Support\ActivityLogger;
use App\Support\Notifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Pulls page batches from the crawler, stores them and re-dispatches itself until the crawl ends.
 * Audit rules run once the crawl is complete; a failed crawl never resolves earlier issues.
 */
class PollCrawl implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public array $backoff = [5, 15, 30, 60];

    public int $timeout = 300;

    public function __construct(public string $crawlRunId)
    {
        $this->onQueue('crawl');
    }

    public function handle(CrawlerClient $crawler, PageIngestor $ingestor, AuditEngine $audit): void
    {
        $run = CrawlRun::with('website')->find($this->crawlRunId);
        if (! $run || $run->status !== 'running') {
            return;
        }

        do {
            $result = $crawler->results($run->crawler_job_id, $run->cursor);
            $stored = $ingestor->ingest($run, $result['pages'] ?? []);
            $run->update([
                'cursor' => $result['next_cursor'] ?? $run->cursor,
                'pages_crawled' => $run->pages_crawled + $stored,
                'pages_found' => max($run->pages_found, (int) ($result['pages_found'] ?? 0)),
            ]);
        } while (! empty($result['pages']) && count($result['pages']) >= 50);

        if (empty($result['done'])) {
            static::dispatch($run->id)->delay(now()->addSeconds(config('seo.crawler.poll_seconds')));

            return;
        }

        $status = $result['status'] ?? 'completed';
        $ingestor->resolveLinkStatuses($run);
        $run->update(['sitemaps_json' => $result['sitemaps'] ?? []]);

        $issues = in_array($status, ['completed', 'partial'], true) && $run->pages_crawled > 0
            ? $audit->run($run)
            : 0;

        $run->update([
            'status' => $run->pages_crawled > 0 ? $status : 'failed',
            'completed_at' => now(),
            'issues_found' => $issues,
            'error_summary' => array_slice($result['errors'] ?? [], 0, 50) ?: null,
        ]);
        ActivityLogger::log($run->website, 'crawl.finished', $run, ['status' => $run->status, 'pages' => $run->pages_crawled]);
        Notifier::website($run->website, new CrawlFinished($run));

        if (in_array($run->status, ['completed', 'partial'], true)) {
            app(Automations::class)->followUp($run->website);
        }
    }

    public function failed(Throwable $e): void
    {
        $updated = CrawlRun::whereKey($this->crawlRunId)->whereIn('status', CrawlRun::ACTIVE_STATUSES)->update([
            'status' => 'failed',
            'completed_at' => now(),
            'error_summary' => json_encode(['message' => $e->getMessage()]),
        ]);
        if ($updated && $run = CrawlRun::with('website')->find($this->crawlRunId)) {
            Notifier::website($run->website, new CrawlFinished($run));
        }
    }
}
