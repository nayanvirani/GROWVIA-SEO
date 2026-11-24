<?php

namespace App\Http\Controllers\Web;

use App\Domain\Automation\AutomationRunner;
use App\Http\Controllers\Controller;
use App\Jobs\RunAutomation;
use App\Models\ProviderUsage;
use App\Models\Website;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class AutomationController extends Controller
{
    public function index(Website $website): Response
    {
        $paused = $website->setting('paused_automations') ?? [];
        $last = $website->automationRuns()->selectRaw('automation_key, MAX(started_at) last')->groupBy('automation_key')->pluck('last', 'automation_key');

        return Inertia::render('automation/Index', [
            'automations' => collect(AutomationRunner::AUTOMATIONS)->map(fn ($a, $key) => $a + [
                'key' => $key, 'paused' => in_array($key, $paused, true), 'last_run' => $last[$key] ?? null,
            ])->values(),
            'runs' => $website->automationRuns()->latest('started_at')->limit(50)->get(),
            'usage' => ProviderUsage::where('website_id', $website->id)->where('created_at', '>=', now()->subDays(30))
                ->selectRaw('provider, operation, SUM(request_count) requests, SUM(units) units, SUM(estimated_cost) cost')
                ->groupBy('provider', 'operation')->orderBy('provider')->get(),
        ]);
    }

    public function run(Website $website, string $key): RedirectResponse
    {
        $this->authorize('update', $website);
        abort_unless(isset(AutomationRunner::AUTOMATIONS[$key]), 404);
        RunAutomation::dispatch($website->id, $key, 'manual');

        return back()->with('success', AutomationRunner::AUTOMATIONS[$key]['label'].' queued.');
    }

    public function toggle(Website $website, string $key): RedirectResponse
    {
        $this->authorize('update', $website);
        abort_unless(isset(AutomationRunner::AUTOMATIONS[$key]), 404);
        $paused = collect($website->setting('paused_automations') ?? []);
        $paused = $paused->contains($key) ? $paused->reject(fn ($k) => $k === $key) : $paused->push($key);
        $website->update(['settings_json' => array_merge($website->settings_json ?? [], ['paused_automations' => $paused->values()->all()])]);

        return back();
    }
}
