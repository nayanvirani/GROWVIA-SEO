<?php

namespace App\Http\Controllers\Web;

use App\Domain\Settings\ProviderSettings;
use App\Http\Controllers\Controller;
use App\Models\ProviderUsage;
use App\Providers\AI\AiProviderFactory;
use App\Providers\AI\OpenAiCompatibleProvider;
use App\Providers\Search\SearchProviderFactory;
use App\Support\Security\HostGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class ProviderController extends Controller
{
    public function __construct(private readonly ProviderSettings $settings, private readonly AiProviderFactory $ai) {}

    public function index(Request $request): Response
    {
        $workspace = $request->user()->activeWorkspace();

        return Inertia::render('providers/Index', [
            'ai' => $this->settings->describe($workspace->id, 'ai'),
            'search' => $this->settings->describe($workspace->id, 'search'),
            'aiPresets' => config('seo.ai_presets'),
            'searchPresets' => config('seo.search_presets'),
            'canManage' => $request->user()->can('manage', $workspace),
            'crawler' => ['base_url' => config('seo.crawler.base_url'), 'healthy' => $this->crawlerHealthy()],
            'usage' => ProviderUsage::where('workspace_id', $workspace->id)->where('created_at', '>=', now()->subDays(30))
                ->selectRaw('provider, SUM(request_count) requests, SUM(units) units')->groupBy('provider')->get(),
        ]);
    }

    public function update(Request $request, string $kind): RedirectResponse
    {
        abort_unless(in_array($kind, ProviderSettings::KINDS, true), 404);
        $workspace = $request->user()->activeWorkspace();
        $this->authorize('manage', $workspace);
        $presets = config($kind === 'ai' ? 'seo.ai_presets' : 'seo.search_presets');

        $data = $request->validate([
            'preset' => ['required', Rule::in(array_keys($presets))],
            'base_url' => ['nullable', 'url:http,https', 'max:500'],
            'model' => ['nullable', 'string', 'max:200'],
            'api_key' => ['nullable', 'string', 'max:500'],
            'clear_key' => ['nullable', 'boolean'],
        ]);
        $this->guardBaseUrl($data['preset'], $data['base_url'] ?? null);
        if ($kind === 'ai' && $data['preset'] !== 'mock' && blank($data['model'] ?? null)) {
            throw ValidationException::withMessages(['model' => 'Choose a model (click "Load models").']);
        }
        $this->settings->save($workspace->id, $kind, $data);

        return back()->with('success', 'Saved. Click "Test" to check it works.');
    }

    /** Fetch the live model list using the typed key (or the saved one). Nothing is stored. */
    public function models(Request $request): RedirectResponse
    {
        $workspace = $request->user()->activeWorkspace();
        $this->authorize('manage', $workspace);
        $data = $request->validate([
            'preset' => ['required', Rule::in(array_keys(config('seo.ai_presets')))],
            'base_url' => ['nullable', 'url:http,https', 'max:500'],
            'api_key' => ['nullable', 'string', 'max:500'],
        ]);
        $this->guardBaseUrl($data['preset'], $data['base_url'] ?? null);
        $saved = $this->settings->get($workspace->id, 'ai');
        $key = $data['api_key'] ?? ($saved['preset'] === $data['preset'] ? $saved['api_key'] : null);

        try {
            $provider = new OpenAiCompatibleProvider([
                'name' => $data['preset'],
                'base_url' => ($data['base_url'] ?? null) ?: config("seo.ai_presets.{$data['preset']}.base_url"),
                'api_key' => $key, 'model' => null,
            ]);
            $models = $provider->listModels();
        } catch (Throwable $e) {
            return back()->with('error', 'Could not load models: '.$e->getMessage());
        }
        if ($filter = config("seo.ai_presets.{$data['preset']}.free_filter")) {
            $free = array_values(array_filter($models, fn ($m) => str_contains($m, $filter)));
            $models = $free ?: $models;
        }

        return back()->with('flash_data', ['models' => $models, 'models_for' => $data['preset']]);
    }

    public function test(Request $request, string $kind): RedirectResponse
    {
        abort_unless(in_array($kind, ProviderSettings::KINDS, true), 404);
        $workspace = $request->user()->activeWorkspace();
        $this->authorize('manage', $workspace);

        try {
            if ($kind === 'ai') {
                $result = $this->ai->forWorkspace($workspace->id)->generate('classify_intent', [
                    '_system' => 'Classify search intent. Reply with JSON {"results":[{"keyword":"...","intent":"informational|commercial|transactional|navigational"}]}.',
                    'keywords' => ['best shopify upsell app'],
                ], ['required' => ['results']]);
                $ok = ! empty($result['data']['results']);
                $message = $ok ? "AI works ({$result['model']}). Answer: ".json_encode($result['data']['results'][0] ?? []) : 'The model replied, but not in the expected format. Try another model.';
            } else {
                $provider = app(SearchProviderFactory::class)->forWorkspace($workspace->id);
                if (! $provider) {
                    return back()->with('error', 'Web search is off or missing its key/URL.');
                }
                $results = $provider->search('best shopify apps', 3);
                $ok = count($results) > 0;
                $message = $ok ? 'Search works. First result: '.$results[0]['url'] : 'Search returned no results.';
            }
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with($ok ? 'success' : 'error', $message);
    }

    /** Only the local Ollama preset may point at this machine; everything else must be public. */
    private function guardBaseUrl(string $preset, ?string $url): void
    {
        if (! $url || $preset === 'ollama') {
            return;
        }
        if (! HostGuard::isAllowedUrl($url, resolve: ! app()->runningUnitTests())) {
            throw ValidationException::withMessages(['base_url' => 'The URL must be a public https address.']);
        }
    }

    private function crawlerHealthy(): bool
    {
        return rescue(fn () => Http::timeout(2)->get(config('seo.crawler.base_url').'/health')->successful(), false, false);
    }
}
