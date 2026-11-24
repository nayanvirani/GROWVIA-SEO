<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Crawl\CrawlService;
use App\Models\Website;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CrawlController extends ApiController
{
    public function store(Website $website, CrawlService $crawls): JsonResponse
    {
        $this->authorize('update', $website);
        $run = $crawls->queue($website);

        return $this->ok(['crawl_run_id' => $run->id, 'status' => $run->status], 202);
    }

    public function index(Website $website): JsonResponse
    {
        return $this->ok($website->crawlRuns()->latest()->paginate(20));
    }

    public function pages(Request $request, Website $website): JsonResponse
    {
        $data = $request->validate(['status_code' => ['nullable', 'integer'], 'q' => ['nullable', 'string', 'max:200']]);

        return $this->ok($website->pages()
            ->when($data['status_code'] ?? null, fn ($q, $s) => $q->where('status_code', $s))
            ->when($data['q'] ?? null, fn ($q, $s) => $q->where('canonical_url', 'like', "%{$s}%"))
            ->orderBy('canonical_url')
            ->paginate(50, ['id', 'canonical_url', 'status_code', 'content_type', 'title', 'meta_description', 'h1', 'canonical_url_found', 'robots_meta', 'word_count', 'last_crawled_at']));
    }
}
