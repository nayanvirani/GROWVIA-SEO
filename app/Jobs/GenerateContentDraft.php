<?php

namespace App\Jobs;

use App\Domain\Content\ContentService;
use App\Models\ContentBrief;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class GenerateContentDraft implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 240;

    public array $backoff = [30];

    public function __construct(public string $briefId)
    {
        $this->onQueue('ai');
    }

    public function uniqueId(): string
    {
        return $this->briefId;
    }

    public function handle(ContentService $content): void
    {
        $brief = ContentBrief::with('website', 'primaryKeyword', 'page')->find($this->briefId);
        if (! $brief) {
            return;
        }
        if (empty($brief->outline_json['sections'])) {
            $content->outlineBrief($brief);
        }
        $content->generateDraft($brief->fresh(['website', 'primaryKeyword', 'page']));
    }

    public function failed(Throwable $e): void
    {
        ContentBrief::whereKey($this->briefId)->update(['status' => 'generation_failed']);
    }
}
