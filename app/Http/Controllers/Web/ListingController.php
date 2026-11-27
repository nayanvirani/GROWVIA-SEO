<?php

namespace App\Http\Controllers\Web;

use App\Domain\Backlink\ListingKit;
use App\Http\Controllers\Controller;
use App\Jobs\RunAutomation;
use App\Models\ListingSubmission;
use App\Models\Website;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ListingController extends Controller
{
    public function __construct(private readonly ListingKit $kit) {}

    public function index(Website $website): Response
    {
        return Inertia::render('listings/Index', [
            // The contact email is read live from settings so changing it never needs a rewrite.
            'pack' => ['contact_email' => $website->setting('contact_email')] + $this->kit->pack($website),
            'fields' => ListingKit::FIELDS,
            'platforms' => $this->kit->platforms($website),
            'mentions' => $website->backlinkProspects()->where('prospect_type', 'unlinked_mention')->with('outreachDrafts')
                ->orderByDesc('created_at')->limit(30)->get(),
            'lastMentionRun' => $website->automationRuns()->where('automation_key', 'brand_mentions')->latest('started_at')->first(),
        ]);
    }

    public function regenerate(Website $website): RedirectResponse
    {
        $this->authorize('update', $website);
        $this->kit->pack($website, regenerate: true);

        return back()->with('success', 'Listing texts rewritten from your website.');
    }

    public function update(Request $request, Website $website, string $platform): RedirectResponse
    {
        $this->authorize('update', $website);
        $data = $request->validate([
            'status' => ['required', Rule::in(ListingSubmission::STATUSES)],
            'listing_url' => ['nullable', 'url:http,https', 'max:2048'],
        ]);
        $row = $this->kit->update($website, $platform, $data);

        return back()->with($row->status === 'live' ? 'success' : 'flash_data', $row->status === 'live'
            ? config("listings.{$platform}.name").' is live and links to you.'
            : ['listing_saved' => $platform]);
    }

    public function findMentions(Website $website): RedirectResponse
    {
        $this->authorize('update', $website);
        RunAutomation::dispatch($website->id, 'brand_mentions', 'manual');

        return back()->with('success', 'Searching for pages that mention you. Results appear here in a few minutes.');
    }
}
