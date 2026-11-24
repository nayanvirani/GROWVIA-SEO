<?php

namespace App\Http\Middleware;

use App\Models\Website;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        $user = $request->user();
        $current = $request->route('website');
        if ($current instanceof Website && $user) {
            $request->session()->put('current_website_id', $current->id);
        }

        return array_merge(parent::share($request), [
            'name' => config('app.name'),
            'auth' => ['user' => $user],
            'workspace' => fn () => $user?->currentWorkspace?->only(['id', 'name']),
            'websites' => fn () => $user?->currentWorkspace?->websites()->orderBy('name')->get(['id', 'name', 'normalized_host']) ?? [],
            'currentWebsite' => fn () => $current instanceof Website
                ? $current->only(['id', 'name', 'base_url', 'normalized_host', 'ownership_confirmed', 'status'])
                : null,
            'providers' => fn () => ['ai' => config('seo.ai.provider'), 'seo_data' => config('seo.seo_data.provider')],
            'flash' => fn () => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
                'data' => $request->session()->get('flash_data'),
            ],
        ]);
    }
}
