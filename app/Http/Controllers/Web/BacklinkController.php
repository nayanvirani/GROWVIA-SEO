<?php

namespace App\Http\Controllers\Web;

use App\Domain\Backlink\BacklinkService;
use App\Domain\Content\ContentService;
use App\Http\Controllers\Controller;
use App\Jobs\RunAutomation;
use App\Models\BacklinkProspect;
use App\Models\Competitor;
use App\Models\OutreachDraft;
use App\Models\Website;
use App\Support\ActivityLogger;
use App\Support\Url;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class BacklinkController extends Controller
{
    public function __construct(private readonly BacklinkService $backlinks) {}

    public function index(Request $request, Website $website): Response
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(BacklinkProspect::STATUSES)],
            'min_score' => ['nullable', 'integer', 'min:0', 'max:100'],
        ]);

        return Inertia::render('backlinks/Index', [
            'competitors' => $website->competitors()->orderByDesc('active')->orderByDesc('seen_count')->orderBy('domain')->get(),
            'lastDiscovery' => $website->automationRuns()->where('automation_key', 'backlink_discovery')->latest('started_at')->first(),
            'existingLinks' => $website->backlinks()->latest('last_seen_at')->limit(50)->get(['id', 'source_url', 'source_domain', 'target_url', 'anchor_text', 'rel_attributes', 'last_seen_at']),
            'prospects' => $website->backlinkProspects()->with(['outreachDrafts' => fn ($q) => $q->latest()])
                ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
                ->when($filters['min_score'] ?? null, fn ($q, $s) => $q->where('score', '>=', $s))
                ->orderByDesc('score')->paginate(50)->withQueryString(),
            'filters' => $filters,
            'statuses' => BacklinkProspect::STATUSES,
            'csvColumns' => BacklinkService::CSV_COLUMNS,
        ]);
    }

    public function storeCompetitor(Request $request, Website $website): RedirectResponse
    {
        $this->authorize('update', $website);
        $data = $request->validate(['domain' => ['required', 'string', 'max:255'], 'name' => ['nullable', 'string', 'max:120']]);
        $website->competitors()->firstOrCreate(['domain' => Url::host($data['domain'])], ['name' => $data['name'] ?? null]);

        return back()->with('success', 'Competitor added.');
    }

    public function destroyCompetitor(Website $website, Competitor $competitor): RedirectResponse
    {
        $this->authorize('delete', $competitor);
        $competitor->delete();

        return back();
    }

    public function toggleCompetitor(Website $website, Competitor $competitor): RedirectResponse
    {
        $this->authorize('update', $competitor);
        $competitor->update(['active' => ! $competitor->active]);

        return back();
    }

    public function discover(Website $website): RedirectResponse
    {
        $this->authorize('update', $website);
        RunAutomation::dispatch($website->id, 'backlink_discovery', 'manual');

        return back()->with('success', 'Searching for link opportunities. This takes a few minutes; results appear here.');
    }

    public function importCsv(Request $request, Website $website): RedirectResponse
    {
        $this->authorize('update', $website);
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:2048']]);
        [$imported, $skipped] = $this->backlinks->importCsv($website, $request->file('file'));

        return back()->with('success', "Imported {$imported} prospects ({$skipped} skipped).");
    }

    public function export(Website $website, ContentService $content)
    {
        return response($this->backlinks->exportCsv($website, $content), 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="backlink-prospects-'.$website->normalized_host.'.csv"',
        ]);
    }

    public function updateProspect(Request $request, Website $website, BacklinkProspect $prospect): RedirectResponse
    {
        $this->authorize('update', $prospect);
        $data = $request->validate(['status' => ['required', Rule::in(BacklinkProspect::STATUSES)]]);
        $prospect->update($data);
        ActivityLogger::log($website, 'prospect.status', $prospect, $data);

        return back();
    }

    public function outreach(Website $website, BacklinkProspect $prospect): RedirectResponse
    {
        $this->authorize('update', $prospect);
        $this->backlinks->outreachDraft($prospect);

        return back()->with('success', 'Outreach draft created. Edit and approve it before sending yourself.');
    }

    public function updateOutreach(Request $request, Website $website, OutreachDraft $outreach): RedirectResponse
    {
        $this->authorize('update', $outreach);
        $data = $request->validate([
            'subject' => ['sometimes', 'string', 'max:250'],
            'body' => ['sometimes', 'string', 'max:10000'],
            'status' => ['sometimes', Rule::in(['draft', 'approved', 'sent', 'discarded'])],
        ]);
        if (($data['status'] ?? null) === 'approved') {
            $data['approved_at'] = now();
        }
        if (($data['status'] ?? null) === 'sent') {
            if (! $outreach->approved_at) {
                return back()->with('error', 'Approve the outreach draft before marking it as sent.');
            }
            $data['sent_at'] = now();
            $outreach->prospect->update(['status' => 'contacted']);
        }
        $outreach->update($data);
        ActivityLogger::log($website, 'outreach.updated', $outreach, ['status' => $outreach->status]);

        return back();
    }
}
