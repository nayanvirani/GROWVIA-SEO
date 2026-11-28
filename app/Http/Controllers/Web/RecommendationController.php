<?php

namespace App\Http\Controllers\Web;

use App\Domain\Content\ContentService;
use App\Http\Controllers\Controller;
use App\Jobs\RunAutomation;
use App\Models\Recommendation;
use App\Models\Website;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class RecommendationController extends Controller
{
    public function index(Request $request, Website $website): Response
    {
        $status = $request->validate(['status' => ['nullable', Rule::in(['open', 'done', 'dismissed', 'resolved'])]])['status'] ?? 'open';

        return Inertia::render('recommendations/Index', [
            'recommendations' => $website->recommendations()->where('status', $status)->with(['page:id,canonical_url,title', 'keyword:id,keyword,opportunity_score,intent'])
                ->orderByRaw("CASE priority WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END")->orderByDesc('score')->get(),
            'counts' => $website->recommendations()->selectRaw('status, COUNT(*) c')->groupBy('status')->pluck('c', 'status'),
            'status' => $status,
            'types' => Recommendation::TYPES,
            'lastRun' => $website->automationRuns()->where('automation_key', 'recommendations')->latest('started_at')->first(),
        ]);
    }

    public function refresh(Website $website): RedirectResponse
    {
        $this->authorize('update', $website);
        RunAutomation::dispatch($website->id, 'recommendations', 'manual');

        return back()->with('success', 'Checking your website for updates. New suggestions appear here in a minute.');
    }

    public function update(Request $request, Website $website, Recommendation $recommendation): RedirectResponse
    {
        $this->authorize('update', $recommendation);
        $recommendation->update($request->validate(['status' => ['required', Rule::in(['open', 'done', 'dismissed'])]]));

        return back();
    }

    /** Turn a "new blog post / landing page" suggestion into a Content Studio brief. */
    public function brief(Website $website, Recommendation $recommendation, ContentService $content): RedirectResponse
    {
        $this->authorize('update', $recommendation);
        abort_unless(in_array($recommendation->type, ['new_post', 'new_landing'], true) && $recommendation->keyword, 404);
        $content->createBrief($website, [
            'title' => $recommendation->proposal_json['ai']['title'] ?? $recommendation->proposal_json['suggested_title'] ?? Str::ucfirst($recommendation->keyword->keyword),
            'content_type' => $recommendation->type === 'new_landing' ? 'landing_page' : 'blog_post',
            'primary_keyword_id' => $recommendation->keyword_id,
            'intent' => $recommendation->keyword->intent,
            'source_notes' => $website->setting('brand_facts'),
        ]);
        $recommendation->update(['status' => 'done']);

        return redirect()->route('content.index', $website)->with('success', 'Brief created. Click "Generate draft" when you are ready.');
    }
}
