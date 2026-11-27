<?php

namespace App\Http\Controllers\Web;

use App\Domain\Content\ContentService;
use App\Http\Controllers\Controller;
use App\Models\ContentBrief;
use App\Models\ContentDraft;
use App\Models\Website;
use App\Models\WebsitePage;
use App\Support\Enums\ContentStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ContentController extends Controller
{
    public function __construct(private readonly ContentService $content) {}

    public function index(Request $request, Website $website): Response
    {
        return Inertia::render('content/Index', [
            'briefs' => $website->contentBriefs()->with(['primaryKeyword:id,keyword', 'page:id,canonical_url'])->withCount('drafts')->latest()->limit(100)->get(),
            'drafts' => $website->contentDrafts()->latest('updated_at')->limit(100)
                ->get(['id', 'brief_id', 'type', 'title', 'status', 'provider', 'model', 'updated_at', 'quality_flags_json']),
            'keywords' => $website->keywords()->orderByDesc('volume')->limit(300)->get(['id', 'keyword', 'intent']),
            'pages' => $website->pages()->where('status_code', 200)->orderBy('canonical_url')->limit(500)->get(['id', 'canonical_url', 'title']),
            'prefill' => $request->only(['keyword_id', 'page_id', 'title']),
        ]);
    }

    public function storeBrief(Request $request, Website $website): RedirectResponse
    {
        $this->authorize('update', $website);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'content_type' => ['required', Rule::in(['blog_post', 'landing_page'])],
            'primary_keyword_id' => ['nullable', Rule::exists('keywords', 'id')->where('website_id', $website->id)],
            'page_id' => ['nullable', Rule::exists('website_pages', 'id')->where('website_id', $website->id)],
            'audience' => ['nullable', 'string', 'max:300'],
            'intent' => ['nullable', 'string', 'max:20'],
            'source_notes' => ['nullable', 'string', 'max:10000'],
        ]);
        $this->content->createBrief($website, $data);

        return back()->with('success', 'Brief created. Generate a draft when ready.');
    }

    public function generate(Request $request, Website $website, ContentBrief $brief): RedirectResponse
    {
        $this->authorize('update', $brief);
        $this->content->queueGeneration($brief, $request->boolean('confirm'));

        return back()->with('success', 'Draft generation queued. It will appear in Drafts when ready.');
    }

    public function destroyBrief(Website $website, ContentBrief $brief): RedirectResponse
    {
        $this->authorize('delete', $brief);
        $brief->delete();

        return back();
    }

    public function edit(Website $website, ContentDraft $draft): Response
    {
        return Inertia::render('content/Edit', [
            'draft' => $draft->load(['brief.primaryKeyword:id,keyword', 'images']),
            'revisions' => $draft->revisions()->with('user:id,name')->limit(20)->get(['id', 'content_draft_id', 'user_id', 'change_summary', 'created_at']),
            'manualStatuses' => ContentStatus::manualDraftTransitions(),
        ]);
    }

    public function update(Request $request, Website $website, ContentDraft $draft): RedirectResponse
    {
        $this->authorize('update', $draft);
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:250'],
            'body_markdown' => ['sometimes', 'nullable', 'string', 'max:200000'],
            'meta_title' => ['sometimes', 'nullable', 'string', 'max:250'],
            'meta_description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'slug_suggestion' => ['sometimes', 'nullable', 'string', 'max:200', 'regex:/^[a-z0-9-]*$/'],
            'faq_json' => ['sometimes', 'nullable', 'array', 'max:20'],
            'faq_json.*.question' => ['required', 'string', 'max:300'],
            'faq_json.*.answer' => ['required', 'string', 'max:3000'],
            'status' => ['sometimes', Rule::in(ContentStatus::manualDraftTransitions())],
            'change_summary' => ['nullable', 'string', 'max:200'],
        ]);
        $this->content->saveDraft($draft, $data, $request->user()->id);

        return back()->with('success', 'Draft saved.');
    }

    public function approve(Request $request, Website $website, ContentDraft $draft): RedirectResponse
    {
        $this->authorize('update', $draft);
        $this->content->approve($draft, $request->user()->id);

        return back()->with('success', 'Approved for export. Publishing stays manual.');
    }

    public function export(Request $request, Website $website, ContentDraft $draft)
    {
        $this->authorize('view', $draft);
        $format = $request->validate(['format' => ['nullable', Rule::in(['md', 'html', 'csv'])]])['format'] ?? 'md';
        $file = $this->content->export($draft, $format);

        return response($file['content'], 200, [
            'Content-Type' => $file['mime'].'; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$file['filename'].'"',
        ]);
    }

    public function metaSuggestions(Website $website, WebsitePage $page): RedirectResponse
    {
        $this->authorize('update', $website);

        return back()->with('flash_data', ['meta_suggestions' => $this->content->metaSuggestions($website, $page)]);
    }
}
