<?php

namespace App\Http\Controllers\Web;

use App\Domain\AI\AiService;
use App\Domain\Audit\AuditRules;
use App\Domain\Crawl\CrawlService;
use App\Http\Controllers\Controller;
use App\Models\PageLink;
use App\Models\SeoIssue;
use App\Models\Website;
use App\Models\WebsitePage;
use App\Support\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AuditController extends Controller
{
    public function index(Request $request, Website $website): Response
    {
        $filters = $request->validate([
            'severity' => ['nullable', Rule::in(['high', 'medium', 'low', 'info'])],
            'status' => ['nullable', Rule::in(SeoIssue::STATUSES)],
            'rule' => ['nullable', Rule::in(array_keys(AuditRules::RULES))],
        ]);
        $status = $filters['status'] ?? 'open';

        $issues = $website->issues()->with('page:id,canonical_url')
            ->where('status', $status)
            ->when($filters['severity'] ?? null, fn ($q, $s) => $q->where('severity', $s))
            ->when($filters['rule'] ?? null, fn ($q, $r) => $q->where('rule_key', $r))
            ->orderByRaw("CASE severity WHEN 'high' THEN 1 WHEN 'medium' THEN 2 WHEN 'low' THEN 3 ELSE 4 END")
            ->orderBy('rule_key')
            ->paginate(50)->withQueryString();

        return Inertia::render('audit/Index', [
            'issues' => $issues,
            'filters' => $filters + ['status' => $status],
            'rules' => AuditRules::RULES,
            'ruleCounts' => $website->issues()->where('status', $status)->selectRaw('rule_key, COUNT(*) c')->groupBy('rule_key')->pluck('c', 'rule_key'),
            'crawlRuns' => $website->crawlRuns()->latest()->limit(10)->get(),
        ]);
    }

    public function crawl(Website $website, CrawlService $crawls): RedirectResponse
    {
        $this->authorize('update', $website);
        $crawls->queue($website);

        return back()->with('success', 'Crawl queued. Results appear as pages are processed.');
    }

    public function pages(Request $request, Website $website): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
            'status' => ['nullable', Rule::in(['2xx', '3xx', '4xx', '5xx', 'error'])],
        ]);
        $pages = $website->pages()->withCount(['issues as open_issues_count' => fn ($q) => $q->where('status', 'open')])
            ->when($filters['q'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('canonical_url', 'like', "%{$s}%")->orWhere('title', 'like', "%{$s}%")))
            ->when($filters['status'] ?? null, function ($q, $s) {
                return $s === 'error' ? $q->whereNull('status_code') : $q->whereBetween('status_code', [(int) $s[0] * 100, (int) $s[0] * 100 + 99]);
            })
            ->orderBy('depth')->orderBy('canonical_url')
            ->paginate(50, ['id', 'canonical_url', 'status_code', 'title', 'word_count', 'depth', 'last_crawled_at'])->withQueryString();

        return Inertia::render('audit/Pages', ['pages' => $pages, 'filters' => $filters]);
    }

    public function page(Website $website, WebsitePage $page): Response
    {
        return Inertia::render('audit/PageDetail', [
            'page' => $page,
            'issues' => $page->issues()->orderByRaw("CASE severity WHEN 'high' THEN 1 WHEN 'medium' THEN 2 WHEN 'low' THEN 3 ELSE 4 END")->get(),
            'links' => $page->links()->orderBy('link_type')->limit(300)->get(['id', 'target_url', 'anchor_text', 'link_type', 'rel', 'http_status']),
            'inlinks' => $website->pages()->whereIn('id', PageLink::forWebsite($website)->where('target_url_hash', $page->url_hash)->select('source_page_id'))
                ->limit(50)->get(['id', 'canonical_url', 'title']),
            'rules' => AuditRules::RULES,
        ]);
    }

    public function updateIssue(Request $request, Website $website, SeoIssue $issue): RedirectResponse
    {
        $this->authorize('update', $issue);
        $data = $request->validate(['status' => ['required', Rule::in(['open', 'dismissed'])]]);
        $issue->update($data);
        ActivityLogger::log($website, 'issue.'.$data['status'], $issue);

        return back()->with('success', $data['status'] === 'dismissed' ? 'Issue dismissed.' : 'Issue reopened.');
    }

    public function explain(Website $website, SeoIssue $issue, AiService $ai): RedirectResponse
    {
        $this->authorize('update', $issue);
        $rule = AuditRules::get($issue->rule_key);
        $result = $ai->run($website, 'explain_issue', [
            'issue_summary' => $rule['label'].' on '.$issue->page?->canonical_url,
            'why_it_matters' => $rule['why'],
            'evidence' => $issue->evidence_json,
            'recommendation' => $issue->recommendation,
        ]);
        $issue->update(['ai_explanation' => $result['data']['explanation']."\n\nNext step: ".$result['data']['next_step']]);

        return back();
    }
}
