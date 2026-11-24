<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Content\ContentService;
use App\Jobs\GenerateContentDraft;
use App\Models\ContentBrief;
use App\Models\ContentDraft;
use App\Models\Website;
use App\Support\Enums\ContentStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ContentController extends ApiController
{
    public function __construct(private readonly ContentService $content) {}

    public function storeBrief(Request $request, Website $website): JsonResponse
    {
        $this->authorize('update', $website);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'content_type' => ['nullable', Rule::in(['blog_post', 'landing_page'])],
            'primary_keyword_id' => ['nullable', Rule::exists('keywords', 'id')->where('website_id', $website->id)],
            'page_id' => ['nullable', Rule::exists('website_pages', 'id')->where('website_id', $website->id)],
            'audience' => ['nullable', 'string', 'max:300'],
            'intent' => ['nullable', 'string', 'max:20'],
            'source_notes' => ['nullable', 'string', 'max:10000'],
        ]);

        return $this->ok($this->content->createBrief($website, $data), 201);
    }

    public function generate(ContentBrief $brief): JsonResponse
    {
        $this->authorize('update', $brief);
        $brief->update(['status' => 'generating']);
        GenerateContentDraft::dispatch($brief->id);

        return $this->ok(['brief_id' => $brief->id, 'status' => 'queued'], 202);
    }

    public function updateDraft(Request $request, ContentDraft $draft): JsonResponse
    {
        $this->authorize('update', $draft);
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:250'],
            'body_markdown' => ['sometimes', 'nullable', 'string', 'max:200000'],
            'meta_title' => ['sometimes', 'nullable', 'string', 'max:250'],
            'meta_description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'slug_suggestion' => ['sometimes', 'nullable', 'string', 'max:200', 'regex:/^[a-z0-9-]*$/'],
            'status' => ['sometimes', Rule::in(ContentStatus::manualDraftTransitions())],
            'change_summary' => ['nullable', 'string', 'max:200'],
        ]);

        return $this->ok($this->content->saveDraft($draft, $data, $request->user()->id));
    }

    public function approve(Request $request, ContentDraft $draft): JsonResponse
    {
        $this->authorize('update', $draft);

        return $this->ok($this->content->approve($draft, $request->user()->id));
    }

    public function export(Request $request, ContentDraft $draft)
    {
        $this->authorize('view', $draft);
        $format = $request->validate(['format' => ['nullable', Rule::in(['md', 'html', 'csv'])]])['format'] ?? 'md';
        $file = $this->content->export($draft, $format);

        return response($file['content'], 200, [
            'Content-Type' => $file['mime'].'; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$file['filename'].'"',
        ]);
    }
}
