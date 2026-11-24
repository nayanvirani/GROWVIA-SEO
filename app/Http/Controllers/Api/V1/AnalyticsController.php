<?php

namespace App\Http\Controllers\Api\V1;

use App\Jobs\SyncSearchConsole;
use App\Models\Website;
use Illuminate\Http\JsonResponse;

class AnalyticsController extends ApiController
{
    public function sync(Website $website): JsonResponse
    {
        $this->authorize('update', $website);
        SyncSearchConsole::dispatch($website->id, 'manual');

        return $this->ok(['status' => 'queued'], 202);
    }

    public function automationRuns(Website $website): JsonResponse
    {
        return $this->ok($website->automationRuns()->latest('started_at')->paginate(50));
    }

    /** Returns the OAuth URL; the browser completes the flow via the web callback route. */
    public function connect(): JsonResponse
    {
        if (blank(config('seo.google.client_id'))) {
            return response()->json(['error' => ['message' => 'Google OAuth is not configured (GOOGLE_CLIENT_ID).']], 400);
        }

        return $this->ok(['redirect_url' => route('integrations.search-console.redirect')]);
    }
}
