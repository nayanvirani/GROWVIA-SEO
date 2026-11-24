<?php

namespace App\Notifications;

use App\Models\CrawlRun;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** In-app only: crawls are frequent, so no email. */
class CrawlFinished extends Notification
{
    use Queueable;

    public function __construct(public CrawlRun $run) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $r = $this->run;

        return [
            'type' => 'crawl_finished',
            'website_id' => $r->website_id,
            'title' => $r->status === 'failed' ? "Crawl failed for {$r->website->name}" : "Crawl {$r->status} for {$r->website->name}",
            'body' => $r->status === 'failed'
                ? ($r->error_summary['message'] ?? 'See crawl history for details.')
                : "{$r->pages_crawled} pages crawled, {$r->issues_found} open issues.",
            'url' => route('audit.index', $r->website_id, false),
        ];
    }
}
