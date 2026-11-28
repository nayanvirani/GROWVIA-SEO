<?php

namespace App\Http\Controllers\Web;

use App\Domain\Content\ContentService;
use App\Domain\Keyword\KeywordAdvisor;
use App\Domain\Keyword\KeywordResearchService;
use App\Http\Controllers\Controller;
use App\Models\ContentBrief;
use App\Models\Keyword;
use App\Models\KeywordCluster;
use App\Models\PageKeywordTarget;
use App\Models\Website;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class KeywordController extends Controller
{
    private const INTENTS = ['informational', 'commercial', 'transactional', 'navigational'];

    public function __construct(private readonly KeywordResearchService $research) {}

    public function index(Request $request, Website $website, KeywordAdvisor $advisor): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'cluster' => ['nullable', 'string', 'size:26'],
            'show' => ['nullable', Rule::in([...array_keys(KeywordAdvisor::GROUPS), 'all'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $show = $filters['show'] ?? 'todo';

        $all = $website->keywords()
            ->with(['clusters:id,name', 'pageTargets.page:id,canonical_url,title,headings_json'])
            ->when($filters['q'] ?? null, fn ($q, $s) => $q->where('keyword', 'like', "%{$s}%"))
            ->when($filters['cluster'] ?? null, fn ($q, $c) => $q->whereHas('clusters', fn ($w) => $w->whereKey($c)))
            ->orderByRaw('opportunity_score IS NULL')->orderByDesc('opportunity_score')->get();
        $advice = $advisor->advise($website, $all);
        $counts = collect($advice)->countBy('group')->all();

        $rows = $all->filter(fn ($k) => $show === 'all' || ($advice[$k->id]['group'] ?? null) === $show)->values();
        $perPage = 25;
        $current = max(1, (int) ($filters['page'] ?? 1));
        $pageRows = $rows->slice(($current - 1) * $perPage, $perPage)->values()
            ->map(fn ($k) => $k->only(['id', 'keyword', 'intent', 'opportunity_score', 'opportunity_json', 'sources_json', 'provider', 'volume', 'priority'])
                + ['clusters' => $k->clusters->pluck('name'), 'advice' => $advice[$k->id]]);
        $paginator = new LengthAwarePaginator($pageRows, $rows->count(), $perPage, $current, ['path' => $request->url(), 'query' => $request->query()]);

        return Inertia::render('keywords/Index', [
            'keywords' => $paginator,
            'filters' => $filters + ['show' => $show],
            'counts' => $counts + ['all' => $all->count()],
            'groups' => KeywordAdvisor::GROUPS,
            'clusters' => $website->keywordClusters()->withCount('keywords')->orderBy('name')->get(['id', 'name']),
            'seeds' => $website->keywordSeeds()->latest()->limit(20)->pluck('seed_text')->unique()->values(),
            'defaults' => ['locale' => $website->target_country ?: 'us', 'language' => $website->language],
            'lastRun' => $website->automationRuns()->where('automation_key', 'keyword_research')->latest('started_at')->first(),
        ]);
    }

    /** One click: plan content for a keyword (blog post or landing page) in Content Studio. */
    public function brief(Website $website, Keyword $keyword, ContentService $content): RedirectResponse
    {
        $this->authorize('update', $keyword);
        if (ContentBrief::forWebsite($website)->where('primary_keyword_id', $keyword->id)->exists()) {
            return redirect()->route('content.index', $website)->with('success', 'This keyword already has a brief. Open it below.');
        }
        $rec = $website->recommendations()->where('keyword_id', $keyword->id)->where('status', 'open')->first();
        $landing = in_array($keyword->intent, ['commercial', 'transactional'], true);
        $content->createBrief($website, [
            'title' => $rec?->proposal_json['ai']['title'] ?? $rec?->proposal_json['suggested_title'] ?? Str::ucfirst($keyword->keyword),
            'content_type' => $landing ? 'landing_page' : 'blog_post',
            'primary_keyword_id' => $keyword->id,
            'intent' => $keyword->intent,
            'source_notes' => $website->setting('brand_facts'),
        ]);
        $rec?->update(['status' => 'done']);

        return redirect()->route('content.index', $website)->with('success', 'Brief created. Click "Generate draft" and the AI writes it for you to review.');
    }

    public function research(Request $request, Website $website): RedirectResponse
    {
        $this->authorize('update', $website);
        $data = $request->validate([
            'seeds' => ['required', 'string', 'max:1000'],
            'locale' => ['required', 'string', 'size:2'],
            'language' => ['required', 'string', 'max:10'],
        ]);
        $seeds = array_slice(array_filter(array_map('trim', preg_split('/[\n,]+/', $data['seeds']))), 0, 10);
        $count = $this->research->research($website, $seeds, strtolower($data['locale']), $data['language'])->count();

        return back()->with('success', "{$count} keywords researched.");
    }

    public function store(Request $request, Website $website): RedirectResponse
    {
        $this->authorize('update', $website);
        $data = $request->validate([
            'keyword' => ['required', 'string', 'max:200'],
            'locale' => ['required', 'string', 'size:2'],
            'language' => ['required', 'string', 'max:10'],
            'intent' => ['nullable', Rule::in(self::INTENTS)],
        ]);
        $this->research->addManual($website, $data['keyword'], strtolower($data['locale']), $data['language'], $data['intent'] ?? null);

        return back()->with('success', 'Keyword added.');
    }

    public function update(Request $request, Website $website, Keyword $keyword): RedirectResponse
    {
        $this->authorize('update', $keyword);
        $keyword->update($request->validate([
            'intent' => ['nullable', Rule::in(self::INTENTS)],
            'priority' => ['nullable', Rule::in(['high', 'medium', 'low'])],
        ]));

        return back();
    }

    public function destroy(Website $website, Keyword $keyword): RedirectResponse
    {
        $this->authorize('delete', $keyword);
        $keyword->delete();

        return back();
    }

    public function map(Request $request, Website $website, Keyword $keyword): RedirectResponse
    {
        $this->authorize('update', $keyword);
        $data = $request->validate([
            'page_id' => ['nullable', Rule::exists('website_pages', 'id')->where('website_id', $website->id)],
            'suggest' => ['nullable', 'boolean'],
        ]);
        if (! empty($data['suggest'])) {
            $suggestion = $this->research->suggestPage($website, $keyword);

            return back()->with('flash_data', ['page_suggestion' => ['keyword_id' => $keyword->id, 'suggestion' => $suggestion]]);
        }
        $keyword->pageTargets()->delete();
        if (! empty($data['page_id'])) {
            PageKeywordTarget::create(['website_id' => $website->id, 'page_id' => $data['page_id'], 'keyword_id' => $keyword->id, 'target_type' => 'primary']);
        }

        return back()->with('success', 'Keyword mapping updated.');
    }

    public function updateCluster(Request $request, Website $website, KeywordCluster $cluster): RedirectResponse
    {
        $this->authorize('update', $cluster);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'primary_keyword_id' => ['nullable', Rule::exists('keywords', 'id')->where('website_id', $website->id)],
        ]);
        $cluster->update($data);

        return back();
    }

    public function export(Website $website, ContentService $content)
    {
        $rows = [['keyword', 'opportunity_score', 'intent', 'priority', 'volume', 'difficulty', 'cpc', 'provider', 'is_estimate', 'locale', 'language', 'metric_date', 'clusters', 'target_page']];
        foreach ($website->keywords()->with(['clusters:id,name', 'pageTargets.page:id,canonical_url'])->orderByDesc('volume')->get() as $k) {
            $rows[] = [$k->keyword, $k->opportunity_score, $k->intent, $k->priority, $k->volume, $k->difficulty, $k->cpc, $k->provider, $k->is_estimate ? 'yes' : 'no',
                $k->locale, $k->language, $k->metric_date?->toDateString(), $k->clusters->pluck('name')->implode('|'), $k->pageTargets->first()?->page?->canonical_url];
        }

        return response($content->csv($rows), 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="keywords-'.$website->normalized_host.'.csv"',
        ]);
    }
}
