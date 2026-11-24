<?php

namespace App\Http\Controllers\Web;

use App\Domain\Analytics\AnalyticsService;
use App\Http\Controllers\Controller;
use App\Jobs\SyncSearchConsole;
use App\Models\Website;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AnalyticsController extends Controller
{
    public function index(Request $request, Website $website, AnalyticsService $analytics): Response
    {
        $data = $request->validate(['days' => ['nullable', 'integer', 'in:7,28,90']]);
        $days = (int) ($data['days'] ?? 28);
        $end = now()->subDays(3)->toDateString();
        $start = now()->subDays(3 + $days - 1)->toDateString();

        return Inertia::render('analytics/Index', [
            'report' => $analytics->report($website, $start, $end),
            'range' => ['start' => $start, 'end' => $end, 'days' => $days],
            'lastSync' => $website->automationRuns()->where('automation_key', 'search_console_sync')->latest('started_at')->first(),
        ]);
    }

    public function sync(Website $website): RedirectResponse
    {
        $this->authorize('update', $website);
        SyncSearchConsole::dispatch($website->id, 'manual');

        return back()->with('success', 'Search performance sync queued.');
    }
}
