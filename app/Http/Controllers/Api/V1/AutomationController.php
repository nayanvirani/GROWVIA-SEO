<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Website;
use Illuminate\Http\JsonResponse;

class AutomationController extends ApiController
{
    public function automationRuns(Website $website): JsonResponse
    {
        return $this->ok($website->automationRuns()->latest('started_at')->paginate(50));
    }
}
