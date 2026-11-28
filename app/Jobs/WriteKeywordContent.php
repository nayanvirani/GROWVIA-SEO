<?php

namespace App\Jobs;

use App\Domain\Keyword\KeywordContentWriter;
use App\Models\Keyword;
use App\Models\WebsitePage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class WriteKeywordContent implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public array $backoff = [90];

    public int $timeout = 420;

    public function __construct(public string $keywordId, public string $pageId, public string $kind)
    {
        $this->onQueue('ai');
    }

    public function uniqueId(): string
    {
        return $this->keywordId;
    }

    public function handle(KeywordContentWriter $writer): void
    {
        $keyword = Keyword::with('website')->find($this->keywordId);
        $page = WebsitePage::find($this->pageId);
        if (! $keyword || ! $page) {
            return;
        }
        $content = $writer->write($keyword->website, $keyword, $page, $this->kind);
        $keyword->update(['content_json' => $content + ['status' => 'ready', 'page_id' => $page->id, 'written_at' => now()->toIso8601String()]]);
    }

    public function failed(Throwable $e): void
    {
        $k = Keyword::find($this->keywordId);
        $k?->update(['content_json' => array_merge($k->content_json ?? [], ['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 300)])]);
    }
}
