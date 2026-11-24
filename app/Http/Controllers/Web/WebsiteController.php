<?php

namespace App\Http\Controllers\Web;

use App\Domain\Overview\OverviewService;
use App\Domain\Website\WebsiteService;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreWebsiteRequest;
use App\Http\Requests\UpdateWebsiteRequest;
use App\Models\Website;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WebsiteController extends Controller
{
    public function __construct(private readonly WebsiteService $websites) {}

    public function dashboard(Request $request): RedirectResponse
    {
        $workspace = $request->user()->activeWorkspace();
        $website = $workspace->websites()->find($request->session()->get('current_website_id'))
            ?? $workspace->websites()->oldest()->first();

        if ($website) {
            return redirect()->route('websites.show', $website);
        }

        return $request->user()->can('createWebsite', $workspace)
            ? redirect()->route('websites.create')
            : redirect()->route('team.index')->with('success', 'No websites in this workspace yet. Ask an owner or admin to add one.');
    }

    public function create(Request $request): Response
    {
        $this->authorize('createWebsite', $request->user()->activeWorkspace());

        return Inertia::render('websites/Create');
    }

    public function store(StoreWebsiteRequest $request): RedirectResponse
    {
        $website = $this->websites->create($request->user()->activeWorkspace(), $request->validated());

        return redirect()->route('websites.show', $website)->with('success', 'Website project created.');
    }

    public function show(Website $website, OverviewService $overview): Response
    {
        return Inertia::render('Dashboard', ['overview' => $overview->build($website)]);
    }

    public function edit(Website $website): Response
    {
        return Inertia::render('websites/Settings', [
            'website' => $website->only(['id', 'name', 'base_url', 'website_type', 'language', 'target_country', 'crawl_enabled', 'ownership_confirmed', 'status']),
            'settings' => $website->settings(),
            'verification' => [
                'verified_at' => $website->verifications()->whereNotNull('verified_at')->latest('verified_at')->value('verified_at'),
                'meta_name' => WebsiteService::VERIFICATION_META,
            ],
        ]);
    }

    public function update(UpdateWebsiteRequest $request, Website $website): RedirectResponse
    {
        $this->authorize('update', $website);
        $this->websites->update($website, $request->validated());

        return back()->with('success', 'Settings saved.');
    }

    public function destroy(Website $website): RedirectResponse
    {
        $this->authorize('delete', $website);
        $website->delete();

        return redirect()->route('dashboard')->with('success', 'Website project deleted.');
    }

    public function startVerification(Website $website): RedirectResponse
    {
        $this->authorize('update', $website);
        $token = $this->websites->startVerification($website);

        return back()->with('flash_data', ['verification_token' => $token]);
    }

    public function checkVerification(Request $request, Website $website): RedirectResponse
    {
        $this->authorize('update', $website);
        $data = $request->validate(['token' => ['required', 'string', 'size:32']]);

        return $this->websites->verify($website, $data['token'])
            ? back()->with('success', 'Ownership verified.')
            : back()->with('error', 'Verification tag not found on the homepage yet.')->with('flash_data', ['verification_token' => $data['token']]);
    }
}
