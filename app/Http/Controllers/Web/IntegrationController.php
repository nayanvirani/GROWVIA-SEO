<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Integration;
use App\Models\ProviderUsage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class IntegrationController extends Controller
{
    public function index(Request $request): Response
    {
        $workspace = $request->user()->currentWorkspace;
        $gsc = $workspace->integrations()->where('provider', 'search_console')->first();

        return Inertia::render('integrations/Index', [
            'ai' => ['provider' => config('seo.ai.provider'), 'configured' => config('seo.ai.provider') === 'mock' || filled(config('seo.ai.api_key')),
                'models' => [config('seo.ai.model_small'), config('seo.ai.model_large')]],
            'seoData' => ['provider' => config('seo.seo_data.provider')],
            'searchConsole' => [
                'oauth_configured' => filled(config('seo.google.client_id')) && filled(config('seo.google.client_secret')),
                'status' => $gsc?->status ?? 'disconnected',
                'property' => $gsc?->metadata_json['property'] ?? null,
                'properties' => $gsc?->metadata_json['properties'] ?? [],
            ],
            'crawler' => ['base_url' => config('seo.crawler.base_url'), 'healthy' => $this->crawlerHealthy()],
            'usage' => ProviderUsage::where('workspace_id', $workspace->id)->where('created_at', '>=', now()->subDays(30))
                ->selectRaw('provider, SUM(request_count) requests, SUM(units) units, SUM(estimated_cost) cost')->groupBy('provider')->get(),
        ]);
    }

    public function searchConsoleRedirect(Request $request): RedirectResponse
    {
        abort_unless(filled(config('seo.google.client_id')), 400, 'Google OAuth is not configured.');
        $state = Str::random(40);
        $request->session()->put('gsc_oauth_state', $state);

        return redirect()->away('https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => config('seo.google.client_id'),
            'redirect_uri' => config('seo.google.redirect'),
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/webmasters.readonly',
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ]));
    }

    public function searchConsoleCallback(Request $request): RedirectResponse
    {
        $expected = $request->session()->pull('gsc_oauth_state');
        if (! $expected || ! hash_equals($expected, (string) $request->query('state')) || ! $request->query('code')) {
            return redirect()->route('integrations.index')->with('error', 'Search Console connection was cancelled or invalid.');
        }

        $token = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'code' => $request->query('code'),
            'client_id' => config('seo.google.client_id'),
            'client_secret' => config('seo.google.client_secret'),
            'redirect_uri' => config('seo.google.redirect'),
            'grant_type' => 'authorization_code',
        ]);
        if ($token->failed() || ! $token->json('refresh_token')) {
            return redirect()->route('integrations.index')->with('error', 'Google did not return a refresh token.');
        }

        $sites = Http::withToken($token->json('access_token'))->get('https://searchconsole.googleapis.com/webmasters/v3/sites')->json('siteEntry', []);
        $properties = collect($sites)->where('permissionLevel', '!=', 'siteUnverifiedUser')->pluck('siteUrl')->values()->all();

        Integration::updateOrCreate(
            ['workspace_id' => $request->user()->current_workspace_id, 'provider' => 'search_console'],
            ['status' => 'connected', 'encrypted_credentials' => ['refresh_token' => $token->json('refresh_token')],
                'metadata_json' => ['properties' => $properties, 'property' => $properties[0] ?? null, 'connected_at' => now()->toIso8601String()]]
        );

        return redirect()->route('integrations.index')->with('success', 'Search Console connected. Select the verified property to sync.');
    }

    public function searchConsoleProperty(Request $request): RedirectResponse
    {
        $integration = Integration::where('workspace_id', $request->user()->current_workspace_id)->where('provider', 'search_console')->firstOrFail();
        $data = $request->validate(['property' => ['required', 'string', Rule::in($integration->metadata_json['properties'] ?? [])]]);
        $integration->update(['metadata_json' => array_merge($integration->metadata_json, ['property' => $data['property']])]);

        return back()->with('success', 'Property selected.');
    }

    public function searchConsoleDisconnect(Request $request): RedirectResponse
    {
        Integration::where('workspace_id', $request->user()->current_workspace_id)->where('provider', 'search_console')
            ->update(['status' => 'disconnected', 'encrypted_credentials' => null]);

        return back()->with('success', 'Search Console disconnected.');
    }

    private function crawlerHealthy(): bool
    {
        return rescue(fn () => Http::timeout(2)->get(config('seo.crawler.base_url').'/health')->successful(), false, false);
    }
}
